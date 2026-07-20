<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use vielhuber\memhelper\memhelper;

final class Test extends TestCase
{
    private string $tmpDir;
    private string $cfgPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/memhelper-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0775, true);
        $this->writeConfig();
    }

    private function mem(): memhelper
    {
        return new memhelper($this->cfgPath);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tmpDir);
    }

    private function writeConfig(?string $extraFilesDir = null): void
    {
        $this->cfgPath = $this->tmpDir . '/config.yaml';
        $yaml = "output: " . $this->tmpDir . "\n";
        if ($extraFilesDir !== null) {
            $yaml .= "input_files:\n    - " . $extraFilesDir . "\n";
        }
        file_put_contents($this->cfgPath, $yaml);
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            if (is_dir($full) && !is_link($full)) {
                self::removeTree($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($path);
    }

    public function testEmptyMemoryDirReturnsEmptyArray(): void
    {
        $this->mem()->work();
        $this->assertSame([], $this->mem()->grab('hello'));
    }

    public function testFirstTickDoesNotReportANonexistentLockAsStale(): void
    {
        $logPath = $this->tmpDir . '/memory.log';

        (new memhelper($this->cfgPath, $logPath))->work();

        $this->assertStringNotContainsString('takeover stale lock', (string) file_get_contents($logPath));
    }

    public function testFindFactsReturnsMatchingEntry(): void
    {
        // new-style frontmatter with both tags + sources.
        file_put_contents(
            $this->tmpDir . '/editor-preferences.md',
            "---\nname: editor-preferences\ndescription: editor of choice\nmetadata:\n  tags:\n    - preference\n    - tool\n    - editor\n  sources:\n    - manual\n---\n\nEditor is Helix.\n"
        );
        $this->mem()->work();
        $facts = $this->mem()->grab('what is my editor?');
        $this->assertGreaterThan(0, count($facts));
        $this->assertSame('editor-preferences', $facts[0]['slug']);
        $this->assertSame(['preference', 'tool', 'editor'], $facts[0]['tags']);
        $this->assertSame(['manual'], $facts[0]['sources']);
        $this->assertSame('editor of choice', $facts[0]['description']);
        $this->assertSame('Editor is Helix.', $facts[0]['body']);
        $this->assertNull($facts[0]['via']);
    }

    public function testEmptyQueryReturnsEmpty(): void
    {
        $this->seedEditorMemory();
        $this->mem()->work();
        $this->assertSame([], $this->mem()->grab(''));
        $this->assertSame([], $this->mem()->grab('   '));
    }

    public function testInputFilesAreStagedAsSourcesForDistillation(): void
    {
        // input_files are read into memhelper_state with kind='source' and the
        // worker's distillation pass asks the ai to summarise them into md
        // entries. without an ai configured we can still verify that the
        // sources phase picks them up and stores the body verbatim, ready
        // for a future distill pass.
        $filesDir = $this->tmpDir . '/docs';
        mkdir($filesDir);
        file_put_contents($filesDir . '/notes.txt', 'Charly is a multi-agent orchestrator built in php.');
        $this->writeConfig($filesDir);
        $this->mem()->work();
        $pdo = new \PDO('sqlite:' . $this->tmpDir . '/.data/memhelper.db');
        $row = $pdo->query(
            "SELECT slug, kind, body FROM memhelper_state WHERE kind = 'source' AND slug LIKE '%notes.txt'"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame('source', $row['kind']);
        $this->assertSame('Charly is a multi-agent orchestrator built in php.', $row['body']);
    }

    public function testCrossReferenceExpansionPullsLinkedNeighbour(): void
    {
        // a query that lexically matches only one entry should still surface
        // its [[…]]-linked neighbour because grab does a 1-hop expand.
        file_put_contents(
            $this->tmpDir . '/contact-julia.md',
            "---\nname: contact-julia\ndescription: contact julia fuchs\nmetadata:\n  type: user\n---\n\nJulia Fuchs ist die Schwester von [[user-name-david]].\n"
        );
        file_put_contents(
            $this->tmpDir . '/user-name-david.md',
            "---\nname: user-name-david\ndescription: name of the user\nmetadata:\n  type: user\n---\n\nDer User heißt David Vielhuber.\n"
        );
        $this->mem()->work();

        $facts = $this->mem()->grab('kennst du Julia Fuchs?');
        $slugs = array_column($facts, 'slug');
        $this->assertContains('contact-julia', $slugs);
        $this->assertContains('user-name-david', $slugs);
        $julia = $facts[array_search('contact-julia', $slugs, true)];
        $david = $facts[array_search('user-name-david', $slugs, true)];
        $this->assertNull($julia['via']);
        $this->assertSame('link', $david['via']);
    }

    public function testLinkTableIsRebuiltOnFirstTickAfterUpgrade(): void
    {
        // simulate an upgrade: memory md files predate the links table. the
        // first refreshMemoryIndex must backfill edges from the unchanged set
        // — otherwise existing stores would only get links after every entry
        // happens to be re-distilled.
        file_put_contents(
            $this->tmpDir . '/foo.md',
            "---\nname: foo\ndescription: foo\nmetadata:\n  type: reference\n---\n\nFoo is connected to [[bar]].\n"
        );
        file_put_contents(
            $this->tmpDir . '/bar.md',
            "---\nname: bar\ndescription: bar\nmetadata:\n  type: reference\n---\n\nBar exists.\n"
        );
        $this->mem()->work();

        // wipe the links table directly to simulate an older binary
        $pdo = new \PDO('sqlite:' . $this->tmpDir . '/.data/memhelper.db');
        $pdo->exec('DELETE FROM memhelper_links');

        // next tick must repopulate
        $this->mem()->work();
        $rows = $pdo->query("SELECT from_slug, to_slug FROM memhelper_links")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame('foo', $rows[0]['from_slug']);
        $this->assertSame('bar', $rows[0]['to_slug']);
    }

    public function testRemovingAnEntryClearsItsLinks(): void
    {
        file_put_contents(
            $this->tmpDir . '/foo.md',
            "---\nname: foo\ndescription: foo\nmetadata:\n  type: reference\n---\n\nLinks to [[bar]].\n"
        );
        file_put_contents(
            $this->tmpDir . '/bar.md',
            "---\nname: bar\ndescription: bar\nmetadata:\n  type: reference\n---\n\nLinks to [[foo]].\n"
        );
        $this->mem()->work();

        $pdo = new \PDO('sqlite:' . $this->tmpDir . '/.data/memhelper.db');
        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM memhelper_links')->fetchColumn());

        unlink($this->tmpDir . '/foo.md');
        $this->mem()->work();

        $rows = $pdo->query('SELECT from_slug, to_slug FROM memhelper_links')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertSame([], $rows, 'edges touching foo must be gone on both sides');
    }

    private function seedEditorMemory(): void
    {
        file_put_contents(
            $this->tmpDir . '/editor-preferences.md',
            "---\nname: editor-preferences\ndescription: editor of choice\nmetadata:\n  type: user\n---\n\nEditor is Helix.\n"
        );
    }

    public function testInputDbsAutoDiscoversTablesAndIndexesRows(): void
    {
        // build a tiny external sqlite db; no alias, no tables config —
        // memhelper must discover the table, pick text-affinity columns,
        // skip the *_id foreign key, and stage each row as a source.
        $extDbPath = $this->tmpDir . '/external.db';
        $ext = new \PDO('sqlite:' . $extDbPath);
        $ext->exec('CREATE TABLE chats_messages (
            id INTEGER PRIMARY KEY,
            role TEXT,
            content TEXT,
            chat_id VARCHAR(36),
            created_at INTEGER
        )');
        $ext->exec("INSERT INTO chats_messages (id, role, content, chat_id, created_at) VALUES
            (1, 'user', 'My favourite editor is Helix.', 'c1', 1000),
            (2, 'assistant', 'Noted.', 'c1', 1001)
        ");
        unset($ext);

        $this->cfgPath = $this->tmpDir . '/config.yaml';
        file_put_contents($this->cfgPath, implode("\n", [
            'output: ' . $this->tmpDir,
            'input_dbs:',
            '    - driver: sqlite',
            '      path: ' . $extDbPath,
            ''
        ]));
        $this->mem()->work();

        $pdo = new \PDO('sqlite:' . $this->tmpDir . '/.data/memhelper.db');
        $rows = $pdo->query(
            "SELECT slug, body FROM memhelper_state WHERE kind = 'source' AND slug LIKE 'dbrow:%' ORDER BY slug"
        )->fetchAll(\PDO::FETCH_ASSOC);

        // alias derived from filename "external.db" → "external"; chat_id
        // skipped by *_id rule, created_at skipped by *_at + non-text rule.
        $this->assertCount(2, $rows);
        $this->assertSame('dbrow:external:chats_messages:1', $rows[0]['slug']);
        $this->assertSame("role: user\ncontent: My favourite editor is Helix.", $rows[0]['body']);
        $this->assertSame('dbrow:external:chats_messages:2', $rows[1]['slug']);
        $this->assertSame("role: assistant\ncontent: Noted.", $rows[1]['body']);
    }

    public function testInputDbsBlacklistsNoiseTablesAndSensitiveColumns(): void
    {
        $extDbPath = $this->tmpDir . '/external.db';
        $ext = new \PDO('sqlite:' . $extDbPath);
        // a noise table by exact-name match
        $ext->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, batch TEXT)');
        $ext->exec("INSERT INTO migrations (id, batch) VALUES (1, 'should be skipped')");
        // a noise table by suffix match
        $ext->exec('CREATE TABLE webhook_log (id INTEGER PRIMARY KEY, event TEXT)');
        $ext->exec("INSERT INTO webhook_log (id, event) VALUES (1, 'also skipped')");
        // a legit table with a sensitive password column
        $ext->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            email TEXT,
            name TEXT,
            password TEXT
        )');
        $ext->exec("INSERT INTO users (id, email, name, password) VALUES
            (1, 'a@b.c', 'Alice', 'sekret-1234')
        ");
        unset($ext);

        $this->cfgPath = $this->tmpDir . '/config.yaml';
        file_put_contents($this->cfgPath, implode("\n", [
            'output: ' . $this->tmpDir,
            'input_dbs:',
            '    - driver: sqlite',
            '      path: ' . $extDbPath,
            ''
        ]));
        $this->mem()->work();

        $pdo = new \PDO('sqlite:' . $this->tmpDir . '/.data/memhelper.db');
        $rows = $pdo->query(
            "SELECT slug, body FROM memhelper_state WHERE kind = 'source' AND slug LIKE 'dbrow:%' ORDER BY slug"
        )->fetchAll(\PDO::FETCH_ASSOC);

        // migrations + webhook_log gone entirely; password column dropped from users row.
        $this->assertCount(1, $rows);
        $this->assertSame('dbrow:external:users:1', $rows[0]['slug']);
        $this->assertStringNotContainsString('sekret-1234', $rows[0]['body']);
        $this->assertStringNotContainsString('password', strtolower($rows[0]['body']));
        $this->assertSame("email: a@b.c\nname: Alice", $rows[0]['body']);
    }

    public function testInputDbsIncludeTablesActsAsWhitelist(): void
    {
        $extDbPath = $this->tmpDir . '/external.db';
        $ext = new \PDO('sqlite:' . $extDbPath);
        $ext->exec('CREATE TABLE chats_messages (id INTEGER PRIMARY KEY, content TEXT)');
        $ext->exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, name TEXT)');
        $ext->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT)');
        $ext->exec("INSERT INTO chats_messages (id, content) VALUES (1, 'hello there')");
        $ext->exec("INSERT INTO tasks (id, name) VALUES (1, 'should be skipped')");
        $ext->exec("INSERT INTO users (id, email) VALUES (1, 'also skipped')");
        unset($ext);

        $this->cfgPath = $this->tmpDir . '/config.yaml';
        file_put_contents($this->cfgPath, implode("\n", [
            'output: ' . $this->tmpDir,
            'input_dbs:',
            '    - driver: sqlite',
            '      path: ' . $extDbPath,
            '      include_tables: [chats_messages]',
            ''
        ]));
        $this->mem()->work();

        $pdo = new \PDO('sqlite:' . $this->tmpDir . '/.data/memhelper.db');
        $rows = $pdo->query(
            "SELECT slug FROM memhelper_state WHERE kind='source' AND slug LIKE 'dbrow:%' ORDER BY slug"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame('dbrow:external:chats_messages:1', $rows[0]['slug']);
    }

    public function testInputDbsWhereFiltersRows(): void
    {
        $extDbPath = $this->tmpDir . '/external.db';
        $ext = new \PDO('sqlite:' . $extDbPath);
        $ext->exec('CREATE TABLE chats_messages (id INTEGER PRIMARY KEY, role TEXT, status TEXT, content TEXT)');
        $ext->exec("INSERT INTO chats_messages (id, role, status, content) VALUES
            (1, 'user', 'completed', 'Remember this.'),
            (2, 'assistant', 'completed', 'Do not index this.'),
            (3, 'user', 'streaming', 'Do not index this either.')
        ");
        unset($ext);

        $this->cfgPath = $this->tmpDir . '/config.yaml';
        file_put_contents($this->cfgPath, implode("\n", [
            'output: ' . $this->tmpDir,
            'input_dbs:',
            '    - driver: sqlite',
            '      path: ' . $extDbPath,
            '      include_tables: [chats_messages]',
            '      where:',
            "          chats_messages: role = 'user' AND status = 'completed'",
            ''
        ]));
        $this->mem()->work();

        $pdo = new \PDO('sqlite:' . $this->tmpDir . '/.data/memhelper.db');
        $rows = $pdo->query(
            "SELECT slug, body FROM memhelper_state WHERE kind='source' AND slug LIKE 'dbrow:%' ORDER BY slug"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame('dbrow:external:chats_messages:1', $rows[0]['slug']);
        $this->assertStringContainsString('Remember this.', $rows[0]['body']);
    }

    public function testInputDbsExcludeTablesAddsToBlacklist(): void
    {
        $extDbPath = $this->tmpDir . '/external.db';
        $ext = new \PDO('sqlite:' . $extDbPath);
        $ext->exec('CREATE TABLE chats_messages (id INTEGER PRIMARY KEY, content TEXT)');
        $ext->exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, name TEXT)');
        $ext->exec("INSERT INTO chats_messages (id, content) VALUES (1, 'keep')");
        $ext->exec("INSERT INTO tasks (id, name) VALUES (1, 'drop')");
        unset($ext);

        $this->cfgPath = $this->tmpDir . '/config.yaml';
        file_put_contents($this->cfgPath, implode("\n", [
            'output: ' . $this->tmpDir,
            'input_dbs:',
            '    - driver: sqlite',
            '      path: ' . $extDbPath,
            '      exclude_tables: [tasks]',
            ''
        ]));
        $this->mem()->work();

        $pdo = new \PDO('sqlite:' . $this->tmpDir . '/.data/memhelper.db');
        $rows = $pdo->query(
            "SELECT slug FROM memhelper_state WHERE kind='source' AND slug LIKE 'dbrow:%' ORDER BY slug"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame('dbrow:external:chats_messages:1', $rows[0]['slug']);
    }

    public function testTightenedIncludeTablesPrunesOrphanState(): void
    {
        // first tick indexes both tables; the second tick narrows
        // include_tables and must prune the now-excluded table's state.
        $extDbPath = $this->tmpDir . '/external.db';
        $ext = new \PDO('sqlite:' . $extDbPath);
        $ext->exec('CREATE TABLE chats_messages (id INTEGER PRIMARY KEY, content TEXT)');
        $ext->exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, name TEXT)');
        $ext->exec("INSERT INTO chats_messages (id, content) VALUES (1, 'keep')");
        $ext->exec("INSERT INTO tasks (id, name) VALUES (1, 'will be orphaned')");
        unset($ext);

        $this->cfgPath = $this->tmpDir . '/config.yaml';
        file_put_contents($this->cfgPath, implode("\n", [
            'output: ' . $this->tmpDir,
            'input_dbs:',
            '    - driver: sqlite',
            '      path: ' . $extDbPath,
            ''
        ]));
        $this->mem()->work();

        $pdo = new \PDO('sqlite:' . $this->tmpDir . '/.data/memhelper.db');
        $before = (int) $pdo->query(
            "SELECT COUNT(*) FROM memhelper_state WHERE kind='source' AND slug LIKE 'dbrow:%'"
        )->fetchColumn();
        $this->assertSame(2, $before);

        // narrow the scope and tick again
        file_put_contents($this->cfgPath, implode("\n", [
            'output: ' . $this->tmpDir,
            'input_dbs:',
            '    - driver: sqlite',
            '      path: ' . $extDbPath,
            '      include_tables: [chats_messages]',
            ''
        ]));
        $this->mem()->work();

        $rows = $pdo->query(
            "SELECT slug FROM memhelper_state WHERE kind='source' AND slug LIKE 'dbrow:%' ORDER BY slug"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame('dbrow:external:chats_messages:1', $rows[0]['slug']);
    }

    public function testFileSourceRefreshDoesNotRemoveDbRowSources(): void
    {
        // regression: refreshFileSources used to load every kind='source'
        // row and mark anything not in the file scan for removal — which
        // wiped all dbrow:* rows on every tick, forcing the next pass to
        // re-distill all db rows from scratch.
        $extDbPath = $this->tmpDir . '/external.db';
        $ext = new \PDO('sqlite:' . $extDbPath);
        $ext->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT)');
        $ext->exec("INSERT INTO notes (id, body) VALUES (1, 'persistent')");
        unset($ext);

        $filesDir = $this->tmpDir . '/docs';
        mkdir($filesDir);
        file_put_contents($filesDir . '/notes.txt', 'file content here.');

        $this->cfgPath = $this->tmpDir . '/config.yaml';
        file_put_contents($this->cfgPath, implode("\n", [
            'output: ' . $this->tmpDir,
            'input_files:',
            '    - ' . $filesDir,
            'input_dbs:',
            '    - driver: sqlite',
            '      path: ' . $extDbPath,
            ''
        ]));
        $this->mem()->work();
        $this->mem()->work(); // second tick — the buggy version would remove the dbrow

        $pdo = new \PDO('sqlite:' . $this->tmpDir . '/.data/memhelper.db');
        $dbrows = (int) $pdo->query(
            "SELECT COUNT(*) FROM memhelper_state WHERE kind='source' AND slug LIKE 'dbrow:%'"
        )->fetchColumn();
        $this->assertSame(1, $dbrows, 'dbrow source must survive a tick where files are also configured');
    }

    public function testInputDbsRowChangesAreDetected(): void
    {
        $extDbPath = $this->tmpDir . '/external.db';
        $ext = new \PDO('sqlite:' . $extDbPath);
        $ext->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT)');
        $ext->exec("INSERT INTO notes (id, body) VALUES (1, 'first'), (2, 'second')");
        unset($ext);

        $this->cfgPath = $this->tmpDir . '/config.yaml';
        file_put_contents($this->cfgPath, implode("\n", [
            'output: ' . $this->tmpDir,
            'input_dbs:',
            '    - driver: sqlite',
            '      path: ' . $extDbPath,
            ''
        ]));
        $this->mem()->work();

        // mutate the source: edit row 1, delete row 2, insert row 3
        $ext = new \PDO('sqlite:' . $extDbPath);
        $ext->exec("UPDATE notes SET body = 'first-changed' WHERE id = 1");
        $ext->exec("DELETE FROM notes WHERE id = 2");
        $ext->exec("INSERT INTO notes (id, body) VALUES (3, 'third')");
        unset($ext);
        $this->mem()->work();

        $pdo = new \PDO('sqlite:' . $this->tmpDir . '/.data/memhelper.db');
        $rows = $pdo->query(
            "SELECT slug, body FROM memhelper_state WHERE kind = 'source' AND slug LIKE 'dbrow:%' ORDER BY slug"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(2, $rows);
        $this->assertSame('dbrow:external:notes:1', $rows[0]['slug']);
        $this->assertSame('body: first-changed', $rows[0]['body']);
        $this->assertSame('dbrow:external:notes:3', $rows[1]['slug']);
        $this->assertSame('body: third', $rows[1]['body']);
    }
}
