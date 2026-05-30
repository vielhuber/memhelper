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

    public function testEmptyMemoryDirReturnsEmptyString(): void
    {
        $this->mem()->work();
        $this->assertSame('', $this->mem()->enhance([
            ['role' => 'user', 'content' => 'hello']
        ]));
    }

    public function testRelevantMemoryIsRetrievedForMatchingTopic(): void
    {
        file_put_contents(
            $this->tmpDir . '/editor-preferences.md',
            "---\nname: editor-preferences\ndescription: editor of choice\nmetadata:\n  type: user\n---\n\nEditor is Helix.\n"
        );
        $this->mem()->work();
        $block = $this->mem()->enhance([
            ['role' => 'user', 'content' => 'what is my editor?']
        ]);
        $this->assertStringContainsString('=== Memory ===', $block);
        $this->assertStringContainsString('editor-preferences', $block);
        $this->assertStringContainsString('Helix', $block);
    }

    public function testInputFilesAreStagedAsSourcesForDistillation(): void
    {
        // under model B, input_files do not appear in enhance() directly —
        // they are first read into memhelper_state with kind='source' and the
        // worker's distillation pass asks the ai to summarise them into md
        // entries. without an ai configured we can still verify that the
        // sources phase picks them up and stores the body verbatim, ready
        // for a future distill pass.
        $filesDir = $this->tmpDir . '/docs';
        mkdir($filesDir);
        file_put_contents($filesDir . '/notes.txt', 'Charly is a multi-agent orchestrator built in php.');
        $this->writeConfig($filesDir);
        $this->mem()->work();
        // poke the internal sqlite directly — the source row should exist
        // with the file's text content stored as the body.
        // internal db now always lives at <output>/.data/memhelper.db
        $pdo = new \PDO('sqlite:' . $this->tmpDir . '/.data/memhelper.db');
        $row = $pdo->query(
            "SELECT slug, kind, body FROM memhelper_state WHERE kind = 'source' AND slug LIKE '%notes.txt'"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame('source', $row['kind']);
        $this->assertSame('Charly is a multi-agent orchestrator built in php.', $row['body']);
    }

    public function testConversationIsEnqueuedForWorker(): void
    {
        $this->mem()->enhance([
            ['role' => 'user', 'content' => 'i prefer helix as my editor'],
            ['role' => 'assistant', 'content' => 'noted']
        ]);
        $queued = glob($this->tmpDir . '/.data/queue/*.json') ?: [];
        $this->assertCount(1, $queued);
        $decoded = json_decode((string) file_get_contents($queued[0]), true);
        $this->assertIsArray($decoded);
        $this->assertSame('i prefer helix as my editor', $decoded[0]['content']);
    }

    public function testEmptyConversationArrayDoesNotEnqueue(): void
    {
        // explicit empty array is still legal at the signature level (the
        // caller might pass an empty turn list); enqueueing is the costly
        // side effect, so it must stay gated on non-empty input.
        $this->mem()->enhance([]);
        $this->assertSame([], glob($this->tmpDir . '/.data/queue/*.json') ?: []);
    }

    public function testStringConversationIsAccepted(): void
    {
        $this->seedEditorMemory();
        $this->mem()->work();
        $block = $this->mem()->enhance('what is my editor?');
        $this->assertStringContainsString('Helix', $block);
    }

    public function testGeminiPartsShapeIsAccepted(): void
    {
        $this->seedEditorMemory();
        $this->mem()->work();
        $block = $this->mem()->enhance([
            ['role' => 'user', 'parts' => [['text' => 'what is my editor?']]]
        ]);
        $this->assertStringContainsString('Helix', $block);
    }

    public function testAnthropicContentBlocksAreAccepted(): void
    {
        $this->seedEditorMemory();
        $this->mem()->work();
        $block = $this->mem()->enhance([
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'what is my editor?']
            ]]
        ]);
        $this->assertStringContainsString('Helix', $block);
    }

    public function testListOfStringsIsAccepted(): void
    {
        $this->seedEditorMemory();
        $this->mem()->work();
        $block = $this->mem()->enhance([
            'hi there',
            'hello back',
            'what is my editor?'
        ]);
        $this->assertStringContainsString('Helix', $block);
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
