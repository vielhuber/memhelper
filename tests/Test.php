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

    /**
     * Keep both facts and link expansion inside the exact project namespace.
     */
    public function testProjectScopesKeepIdenticalSlugsIsolated(): void
    {
        $memory = $this->mem();
        $apply = new \ReflectionMethod(memhelper::class, 'applyDiff');
        $apply->invoke(
            $memory,
            [[
                'action' => 'add',
                'slug' => 'database-choice',
                'content' => 'Project Alpha uses PostgreSQL and follows [[deployment-rule]].'
            ], [
                'action' => 'add',
                'slug' => 'deployment-rule',
                'content' => 'Project Alpha deploys from a release branch.'
            ]],
            [],
            [],
            '/var/www/alpha'
        );
        $apply->invoke(
            $memory,
            [[
                'action' => 'add',
                'slug' => 'database-choice',
                'content' => 'Project Beta uses SQLite and follows [[deployment-rule]].'
            ], [
                'action' => 'add',
                'slug' => 'deployment-rule',
                'content' => 'Project Beta deploys directly from main.'
            ]],
            [],
            [],
            '/var/www/beta'
        );

        $this->assertSame([], $memory->grab('PostgreSQL'));
        $alphaFacts = $memory->grab('PostgreSQL', 10, '/var/www/alpha');
        $betaFacts = $memory->grab('SQLite', 10, '/var/www/beta');
        $this->assertSame(
            ['Project Alpha uses PostgreSQL and follows [[deployment-rule]].', 'Project Alpha deploys from a release branch.'],
            array_column($alphaFacts, 'body')
        );
        $this->assertSame(
            ['Project Beta uses SQLite and follows [[deployment-rule]].', 'Project Beta deploys directly from main.'],
            array_column($betaFacts, 'body')
        );
    }

    /**
     * Build bounded prompt context exclusively from the curated local index.
     */
    public function testInjectBuildsProjectContextWithoutAiCall(): void
    {
        $memory = $this->mem();
        $apply = new \ReflectionMethod(memhelper::class, 'applyDiff');
        $apply->invoke(
            $memory,
            [['action' => 'add', 'slug' => 'database-choice', 'content' => 'The project uses PostgreSQL for persistence.']],
            [],
            [],
            '/var/www/alpha'
        );
        $apply->invoke(
            $memory,
            [['action' => 'add', 'slug' => 'deployment-rule', 'content' => 'Deployments use a separate release branch.']],
            [],
            [],
            '/var/www/alpha'
        );

        $context = $memory->inject('/var/www/alpha', 'Adjust the PostgreSQL migration.');

        $this->assertStringContainsString('Project memory', $context);
        $this->assertStringContainsString('PostgreSQL', $context);
        $this->assertStringNotContainsString('release branch', $context);

        $recentContext = $memory->inject('/var/www/alpha');
        $this->assertStringContainsString('PostgreSQL', $recentContext);
        $this->assertStringContainsString('release branch', $recentContext);
        $this->assertSame('', $memory->inject('/var/www/beta'));
    }

    /**
     * Reject scoped files whose directory hash does not match their namespace.
     */
    public function testScopedFilesOnlyLoadFromTheirMatchingNamespaceDirectory(): void
    {
        $directory = $this->tmpDir . '/.scopes/incorrect';
        mkdir($directory, recursive: true);
        file_put_contents($directory . '/database-choice.md', implode("\n", [
            '---',
            'name: database-choice',
            'scope: /var/www/alpha',
            '---',
            'Project Alpha uses PostgreSQL.',
            ''
        ]));

        $memory = $this->mem();
        $memory->work();

        $this->assertSame([], $memory->grab('PostgreSQL', 10, '/var/www/alpha'));
    }

    /**
     * Keep injected prompt content inside its configured byte budget.
     */
    public function testInjectTruncatesAnOversizedFactWithinTheConfiguredLimit(): void
    {
        file_put_contents($this->cfgPath, implode("\n", [
            'output: ' . $this->tmpDir,
            'injection_max_bytes: 1000',
            ''
        ]));
        $memory = $this->mem();
        $apply = new \ReflectionMethod(memhelper::class, 'applyDiff');
        $apply->invoke(
            $memory,
            [[
                'action' => 'add',
                'slug' => 'database-choice',
                'content' => 'PostgreSQL ' . str_repeat('database details ', 200)
            ]],
            [],
            [],
            '/var/www/alpha'
        );

        $context = $memory->inject('/var/www/alpha', 'PostgreSQL');

        $this->assertLessThanOrEqual(1000, strlen($context));
        $this->assertStringContainsString('PostgreSQL', $context);
        $this->assertStringEndsWith('[truncated]', $context);
    }

    /**
     * Reflect query-backed source additions, updates and removals in staging.
     */
    public function testQuerySourceStagesRowsWithTheirScope(): void
    {
        $externalPath = $this->tmpDir . '/external.db';
        $external = new \PDO('sqlite:' . $externalPath);
        $external->exec('CREATE TABLE chats (id TEXT PRIMARY KEY, code_folder TEXT)');
        $external->exec('CREATE TABLE chats_messages (id TEXT PRIMARY KEY, chat_id TEXT, role TEXT, content TEXT)');
        $external->exec("INSERT INTO chats VALUES ('chat-1', '/var/www/alpha')");
        $external->exec("INSERT INTO chats_messages VALUES ('message-1', 'chat-1', 'user', 'Use PostgreSQL for this project.')");
        unset($external);

        file_put_contents($this->cfgPath, implode("\n", [
            'output: ' . $this->tmpDir,
            'input_dbs:',
            '    - key: project-chats',
            '      driver: sqlite',
            '      path: ' . $externalPath,
            '      query: |',
            '          SELECT chats_messages.id, chats.code_folder AS scope, chats_messages.role, chats_messages.content',
            '          FROM chats_messages INNER JOIN chats ON chats.id = chats_messages.chat_id',
            '      primary_key: id',
            '      scope_column: scope',
            '      content_columns: [role, content]',
            ''
        ]));

        $this->mem()->work();

        $database = new \PDO('sqlite:' . $this->tmpDir . '/.data/memhelper.db');
        $source = $database->query(
            "SELECT scope, body, distilled_at FROM memhelper_state WHERE kind = 'source' AND slug LIKE 'dbquery:%'"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('/var/www/alpha', $source['scope']);
        $this->assertSame("role: user\ncontent: Use PostgreSQL for this project.", $source['body']);

        $external = new \PDO('sqlite:' . $externalPath);
        $external->exec(
            "UPDATE chats SET code_folder = '/var/www/beta' WHERE id = 'chat-1'"
        );
        $external->exec(
            "UPDATE chats_messages SET content = 'Use SQLite for this project.' WHERE id = 'message-1'"
        );
        unset($external, $database);
        $this->mem()->work();

        $database = new \PDO('sqlite:' . $this->tmpDir . '/.data/memhelper.db');
        $source = $database->query(
            "SELECT scope, body, distilled_at FROM memhelper_state WHERE kind = 'source' AND slug LIKE 'dbquery:%'"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('/var/www/beta', $source['scope']);
        $this->assertSame("role: user\ncontent: Use SQLite for this project.", $source['body']);
        $this->assertNull($source['distilled_at']);

        $external = new \PDO('sqlite:' . $externalPath);
        $external->exec("DELETE FROM chats_messages WHERE id = 'message-1'");
        unset($external, $database);
        $this->mem()->work();

        $database = new \PDO('sqlite:' . $this->tmpDir . '/.data/memhelper.db');
        $this->assertSame(
            0,
            (int) $database->query(
                "SELECT COUNT(*) FROM memhelper_state WHERE kind = 'source' AND slug LIKE 'dbquery:%'"
            )->fetchColumn()
        );
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
        file_put_contents($filesDir . '/notes.txt', 'The application is a multi-agent orchestrator built in php.');
        $this->writeConfig($filesDir);
        $this->mem()->work();
        $pdo = new \PDO('sqlite:' . $this->tmpDir . '/.data/memhelper.db');
        $row = $pdo->query(
            "SELECT slug, kind, body FROM memhelper_state WHERE kind = 'source' AND slug LIKE '%notes.txt'"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame('source', $row['kind']);
        $this->assertSame('The application is a multi-agent orchestrator built in php.', $row['body']);
    }

    public function testDistillationWaitsDuringProviderCapacityPause(): void
    {
        $filesDir = $this->tmpDir . '/docs';
        mkdir($filesDir);
        file_put_contents($filesDir . '/notes.txt', 'This fact must remain pending.');
        file_put_contents($this->cfgPath, implode("\n", [
            'output: ' . $this->tmpDir,
            'input_files:',
            '    - ' . $filesDir,
            'ai:',
            '    provider: openai',
            '    model: unavailable-test-model',
            ''
        ]));
        $memory = $this->mem();
        $pause = new \ReflectionProperty(memhelper::class, 'aiUnavailableUntil');
        $pause->setValue($memory, time() + 300);

        $memory->work();

        $pdo = new \PDO('sqlite:' . $this->tmpDir . '/.data/memhelper.db');
        $distilledAt = $pdo->query(
            "SELECT distilled_at FROM memhelper_state WHERE kind = 'source' AND slug LIKE '%notes.txt'"
        )->fetchColumn();
        $this->assertNull($distilledAt);
    }

    /** Preserve separate harness session and authentication stores for AIHelper calls. */
    public function testHarnessStoreConfigurationIsLoaded(): void
    {
        file_put_contents($this->cfgPath, implode("\n", [
            'output: ' . $this->tmpDir,
            'ai:',
            '    provider: codex',
            '    model: gpt-5.6-sol',
            '    cli_session_home: /host/data/harness/chats/memory',
            '    cli_auth_home: /host/data/harness/profiles/codex',
            ''
        ]));
        $memory = $this->mem();
        $configuration = new \ReflectionProperty(memhelper::class, 'aiConfig');

        $this->assertSame([
            'provider' => 'codex',
            'model' => 'gpt-5.6-sol',
            'cli_session_home' => '/host/data/harness/chats/memory',
            'cli_auth_home' => '/host/data/harness/profiles/codex'
        ], $configuration->getValue($memory));
    }

    public function testCrossReferenceExpansionPullsLinkedNeighbour(): void
    {
        // a query that lexically matches only one entry should still surface
        // its [[…]]-linked neighbour because grab does a 1-hop expand.
        file_put_contents(
            $this->tmpDir . '/primary-contact.md',
            "---\nname: primary-contact\ndescription: primary contact profile\nmetadata:\n  type: user\n---\n\nDie Kontaktperson ist mit [[user-profile]] verknuepft.\n"
        );
        file_put_contents(
            $this->tmpDir . '/user-profile.md',
            "---\nname: user-profile\ndescription: profile of the user\nmetadata:\n  type: user\n---\n\nDas Profil gehoert zum User.\n"
        );
        $this->mem()->work();

        $facts = $this->mem()->grab('kennst du die Kontaktperson?');
        $slugs = array_column($facts, 'slug');
        $this->assertContains('primary-contact', $slugs);
        $this->assertContains('user-profile', $slugs);
        $contactFact = $facts[array_search('primary-contact', $slugs, true)];
        $userFact = $facts[array_search('user-profile', $slugs, true)];
        $this->assertNull($contactFact['via']);
        $this->assertSame('link', $userFact['via']);
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

    /**
     * Keep daily compaction away from externally reported busy periods.
     */
    public function testInputDbGuardPausesCompaction(): void
    {
        $externalDatabasePath = $this->tmpDir . '/external.db';
        $externalDatabase = new \PDO('sqlite:' . $externalDatabasePath);
        $externalDatabase->exec('CREATE TABLE jobs (id INTEGER PRIMARY KEY, status TEXT)');
        $externalDatabase->exec("INSERT INTO jobs (id, status) VALUES (1, 'running')");
        unset($externalDatabase);
        $logPath = $this->tmpDir . '/memory.log';
        file_put_contents($this->cfgPath, implode("\n", [
            'output: ' . $this->tmpDir,
            'input_dbs:',
            '    - driver: sqlite',
            '      path: ' . $externalDatabasePath,
            "      compaction_pause_when: SELECT COUNT(*) FROM jobs WHERE status = 'running'",
            ''
        ]));

        (new memhelper($this->cfgPath, $logPath))->work();

        $this->assertStringContainsString(
            'compaction paused by input database guard: compaction-guard-1',
            (string) file_get_contents($logPath)
        );
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

    public function testExpiredMemoryIsDroppedByTheWorker(): void
    {
        $this->writeMemory('old-booking', 'Hotelbuchung in Rom.', validUntil: '2020-01-01');
        $this->writeMemory('travel-preferences', 'Der User reist gerne nach Rom.');

        $this->mem()->work();

        $this->assertFileDoesNotExist($this->tmpDir . '/old-booking.md');
        $this->assertFileExists($this->tmpDir . '/travel-preferences.md');
        $slugs = array_column($this->mem()->grab('Rom'), 'slug');
        $this->assertSame(['travel-preferences'], $slugs);
    }

    public function testMemoryValidIntoTheFutureSurvivesAndStaysSearchable(): void
    {
        $this->writeMemory('running-contract', 'Der Vertrag läuft noch.', validUntil: date('Y-m-d', time() + 86400 * 30));

        $this->mem()->work();

        $this->assertFileExists($this->tmpDir . '/running-contract.md');
        $this->assertSame(['running-contract'], array_column($this->mem()->grab('Vertrag'), 'slug'));
    }

    public function testScoresArePositiveAndDescending(): void
    {
        $this->writeMemory('editor-preferences', 'Der User nutzt Helix als Editor.');
        $this->writeMemory('editor-history', 'Frueher nutzte der User einen anderen Editor.');
        $this->mem()->work();

        $facts = $this->mem()->grab('editor');

        $this->assertCount(2, $facts);
        $this->assertGreaterThan(0, $facts[0]['score']);
        $this->assertGreaterThanOrEqual($facts[1]['score'], $facts[0]['score']);
    }

    public function testNeighbourRankingPrefersTheMultiplyReferencedEntry(): void
    {
        // both primaries match the query and link to the hub; only one of them
        // links to the side entry — so the hub must be the first neighbour.
        $this->writeMemory('contract-hosting', 'Hosting-Vertrag, siehe [[household-budget]].');
        $this->writeMemory('contract-internet', 'Internet-Vertrag, siehe [[household-budget]] und [[side-note]].');
        $this->writeMemory('household-budget', 'Haushaltsbuch des Users.');
        $this->writeMemory('side-note', 'Eine Randnotiz.');
        $this->mem()->work();

        $facts = $this->mem()->grab('vertrag', 2);

        $neighbours = array_values(array_filter($facts, static fn(array $f): bool => $f['via'] === 'link'));
        $this->assertNotSame([], $neighbours);
        $this->assertSame('household-budget', $neighbours[0]['slug']);
    }

    public function testPendingSourcesArePackedIntoOneBatch(): void
    {
        $rows = [
            ['slug' => 'attached:/tmp/a.txt', 'body' => 'first'],
            ['slug' => 'attached:/tmp/b.txt', 'body' => 'second'],
            ['slug' => 'dbrow:external:notes:1', 'body' => 'third']
        ];

        $batches = $this->invoke('batchSources', $rows);

        $this->assertCount(1, $batches);
        $this->assertCount(3, $batches[0]);
        $this->assertSame('/tmp/a.txt', $batches[0][0]['label']);
        $this->assertSame('dbrow:external:notes:1', $batches[0][2]['label']);
    }

    public function testSourceBatchesRespectTheByteBudget(): void
    {
        file_put_contents($this->cfgPath, "output: " . $this->tmpDir . "\nmax_source_bytes: 1000\n");
        $rows = [
            ['slug' => 'dbrow:external:notes:1', 'body' => str_repeat('a', 800)],
            ['slug' => 'dbrow:external:notes:2', 'body' => str_repeat('b', 800)]
        ];

        $batches = $this->invoke('batchSources', $rows);

        $this->assertCount(2, $batches);
        $this->assertCount(1, $batches[0]);
        $this->assertCount(1, $batches[1]);
    }

    /**
     * Prevent one distillation call from receiving multiple project namespaces.
     */
    public function testSourceBatchesNeverMixProjectScopes(): void
    {
        $rows = [
            ['slug' => 'dbquery:projects:1', 'scope' => '/var/www/alpha', 'body' => 'first'],
            ['slug' => 'dbquery:projects:2', 'scope' => '/var/www/beta', 'body' => 'second'],
            ['slug' => 'dbquery:projects:3', 'scope' => '/var/www/alpha', 'body' => 'third']
        ];

        $batches = $this->invoke('batchSources', $rows);

        $this->assertCount(3, $batches);
        $this->assertSame(['/var/www/alpha'], array_unique(array_column($batches[0], 'scope')));
        $this->assertSame(['/var/www/beta'], array_unique(array_column($batches[1], 'scope')));
        $this->assertSame(['/var/www/alpha'], array_unique(array_column($batches[2], 'scope')));
    }

    public function testCompactionClustersLinkedAndTaggedEntriesTogether(): void
    {
        $entries = [
            ['slug' => 'a', 'description' => '', 'tags' => ['contact'], 'body' => 'refers to [[b]]', 'links' => ['b']],
            ['slug' => 'b', 'description' => '', 'tags' => [], 'body' => '', 'links' => []],
            ['slug' => 'c', 'description' => '', 'tags' => ['contact'], 'body' => '', 'links' => []],
            ['slug' => 'z', 'description' => '', 'tags' => ['unrelated'], 'body' => '', 'links' => []]
        ];

        $batches = $this->invoke('clusterMemories', $entries);

        // everything fits one prompt budget, so the clustering shows up as the
        // ordering inside the single batch: the a/b/c cluster comes first.
        $this->assertCount(1, $batches);
        $this->assertSame(['a', 'b', 'c', 'z'], array_column($batches[0], 'slug'));
    }

    public function testOversizedClustersAreSplitAcrossBatches(): void
    {
        $entries = [];
        foreach (range(1, 5) as $i) {
            $entries[] = [
                'slug' => 'entry-' . $i,
                'description' => '',
                'tags' => ['shared'],
                'body' => str_repeat('x', 20000),
                'links' => []
            ];
        }

        $batches = $this->invoke('clusterMemories', $entries);

        $this->assertGreaterThan(1, count($batches));
        $this->assertSame(5, array_sum(array_map('count', $batches)));
    }

    public function testSaveMemoryStampsCreatedAndKeepsItOnUpdate(): void
    {
        $memory = $this->mem();
        $apply = new \ReflectionMethod(memhelper::class, 'applyDiff');
        $apply->invoke($memory, [['action' => 'add', 'slug' => 'foo', 'content' => 'one']], ['manual']);
        $created = self::frontmatterOf($this->tmpDir . '/foo.md')['metadata']['created'];

        $apply->invoke($memory, [['action' => 'update', 'slug' => 'foo', 'content' => 'two']], ['manual']);
        $frontmatter = self::frontmatterOf($this->tmpDir . '/foo.md');

        $this->assertSame($created, $frontmatter['metadata']['created']);
        $this->assertArrayHasKey('updated', $frontmatter['metadata']);
        $this->assertArrayNotHasKey('valid_until', $frontmatter['metadata']);
    }

    public function testProtectedSlugsRejectWritesButAllowDeletes(): void
    {
        $this->writeMemory('keep-me', 'Original body.');
        $memory = $this->mem();
        $apply = new \ReflectionMethod(memhelper::class, 'applyDiff');

        $apply->invoke($memory, [['action' => 'update', 'slug' => 'keep-me', 'content' => 'overwritten']], [], ['keep-me']);
        $this->assertStringContainsString('Original body.', (string) file_get_contents($this->tmpDir . '/keep-me.md'));

        $apply->invoke($memory, [['action' => 'delete', 'slug' => 'keep-me']], [], ['keep-me']);
        $this->assertFileDoesNotExist($this->tmpDir . '/keep-me.md');
    }

    public function testIndexUpgradeRebuildsMemoriesWithoutDiscardingDistilledSources(): void
    {
        $filesDir = $this->tmpDir . '/docs';
        mkdir($filesDir);
        file_put_contents($filesDir . '/notes.txt', 'Some source text.');
        $this->writeConfig($filesDir);
        $this->writeMemory('editor-preferences', 'Editor ist Helix.');
        $this->mem()->work();

        $dbPath = $this->tmpDir . '/.data/memhelper.db';
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->exec("UPDATE memhelper_state SET distilled_at = 1700000000 WHERE kind = 'source'");
        $pdo->exec('ALTER TABLE memhelper_state RENAME TO memhelper_state_scoped');
        $pdo->exec(
            'CREATE TABLE memhelper_state (
                slug TEXT PRIMARY KEY,
                kind TEXT NOT NULL,
                mtime INTEGER NOT NULL,
                hash TEXT NOT NULL,
                body TEXT,
                indexed_at INTEGER NOT NULL,
                distilled_at INTEGER
            )'
        );
        $pdo->exec(
            'INSERT INTO memhelper_state
             (slug, kind, mtime, hash, body, indexed_at, distilled_at)
             SELECT slug, kind, mtime, hash, body, indexed_at, distilled_at FROM memhelper_state_scoped'
        );
        $pdo->exec('DROP TABLE memhelper_state_scoped');
        $pdo->exec('DROP TABLE memhelper_links');
        $pdo->exec(
            'CREATE TABLE memhelper_links (
                from_slug TEXT NOT NULL,
                to_slug TEXT NOT NULL,
                PRIMARY KEY (from_slug, to_slug)
            )'
        );
        $pdo->exec('DROP TABLE memories');
        $pdo->exec('CREATE VIRTUAL TABLE memories USING fts5(slug UNINDEXED, kind UNINDEXED, title, body)');
        unset($pdo);

        $this->mem()->work();

        $pdo = new \PDO('sqlite:' . $dbPath);
        $source = $pdo->query(
            "SELECT scope, body, distilled_at FROM memhelper_state WHERE kind = 'source'"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('', $source['scope']);
        $this->assertSame('Some source text.', $source['body'], 'staged source body must survive the index upgrade');
        $this->assertSame(1700000000, (int) $source['distilled_at'], 'upgrade must not force a re-distillation');
        $this->assertSame(['editor-preferences'], array_column($this->mem()->grab('editor'), 'slug'));
    }

    public function testHandEditedExpiryReachesTheIndexWithoutABodyChange(): void
    {
        $this->writeMemory('booking', 'Hotelbuchung in Rom.');
        $this->mem()->work();
        $this->assertFileExists($this->tmpDir . '/booking.md');

        // same body, only the frontmatter gains an expiry — the mtime-only
        // fast path used to swallow this.
        $this->writeMemory('booking', 'Hotelbuchung in Rom.', validUntil: '2020-01-01');
        touch($this->tmpDir . '/booking.md', time() + 10);
        $this->mem()->work();

        $this->assertFileDoesNotExist($this->tmpDir . '/booking.md');
    }

    private function writeMemory(string $slug, string $body, ?string $validUntil = null): void
    {
        $frontmatter = "---\nname: " . $slug . "\ndescription: " . $slug . "\nmetadata:\n  tags:\n    - test\n";
        if ($validUntil !== null) {
            $frontmatter .= "  valid_until: '" . $validUntil . "'\n";
        }
        file_put_contents($this->tmpDir . '/' . $slug . '.md', $frontmatter . "---\n\n" . $body . "\n");
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod(memhelper::class, $method))->invoke($this->mem(), ...$args);
    }

    private static function frontmatterOf(string $path): array
    {
        $split = new \ReflectionMethod(memhelper::class, 'splitFrontmatter');
        return $split->invoke(null, (string) file_get_contents($path))[0];
    }
}
