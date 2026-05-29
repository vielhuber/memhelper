<?php
declare(strict_types=1);

namespace vielhuber\memhelper;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use vielhuber\aihelper\aihelper;
use vielhuber\dbhelper\dbhelper;

/**
 * memhelper — markdown-first memory layer for LLM agents.
 *
 * The only public host API is memhelper::enhance($conversation). Everything
 * else — config loading, database connections, queue draining, index
 * refresh, LLM-driven extraction, periodic compaction — runs internally.
 *
 * Plain `.md` files under `output` stay the source of truth. The configured
 * databases (sqlite, mysql, postgres — any combination, mirrored) hold a
 * derived search index that the worker rebuilds from the files.
 */
final class memhelper
{
    private string $output;
    /** @var list<string> */
    private array $filesDirs;
    private array $aiConfig;
    private ?aihelper $ai = null;
    /** @var list<array{driver: string, db: dbhelper}> */
    private array $dbs = [];

    private function __construct()
    {
        $cfg = self::config();

        $output = (string) ($cfg['output'] ?? '');
        if ($output === '') {
            throw new RuntimeException('memhelper: output is required in config.yaml');
        }
        if (!is_dir($output) && !@mkdir($output, 0775, true) && !is_dir($output)) {
            throw new RuntimeException('memhelper: output dir does not exist and could not be created: ' . $output);
        }
        $this->output = rtrim($output, '/');

        $files = $cfg['input_files'] ?? [];
        $this->filesDirs = is_array($files)
            ? array_values(array_filter(array_map('strval', $files), fn(string $d): bool => $d !== ''))
            : [];

        $this->aiConfig = is_array($cfg['ai'] ?? null) ? $cfg['ai'] : [];

        $dbList = $cfg['input_dbs'] ?? [];
        if (!is_array($dbList) || $dbList === []) {
            throw new RuntimeException('memhelper: input_dbs must be a non-empty list in config.yaml');
        }
        foreach ($dbList as $dbc) {
            if (!is_array($dbc)) {
                continue;
            }
            $driver = (string) ($dbc['driver'] ?? 'sqlite');
            $db = new dbhelper();
            match ($driver) {
                'sqlite' => $db->connect(
                    'pdo',
                    'sqlite',
                    (string) ($dbc['path'] ?? ($this->output . '/.index.sqlite'))
                ),
                'mysql' => $db->connect(
                    'pdo',
                    'mysql',
                    (string) ($dbc['host'] ?? '127.0.0.1'),
                    (string) ($dbc['user'] ?? ''),
                    (string) ($dbc['password'] ?? ''),
                    (string) ($dbc['database'] ?? ''),
                    (int) ($dbc['port'] ?? 3306)
                ),
                'postgres' => $db->connect(
                    'pdo',
                    'postgres',
                    (string) ($dbc['host'] ?? '127.0.0.1'),
                    (string) ($dbc['user'] ?? ''),
                    (string) ($dbc['password'] ?? ''),
                    (string) ($dbc['database'] ?? ''),
                    (int) ($dbc['port'] ?? 5432)
                ),
                default => throw new RuntimeException(
                    'memhelper: unsupported database driver "' . $driver . '" — use sqlite, mysql or postgres'
                )
            };
            $this->initSchema($driver, $db);
            $this->dbs[] = ['driver' => $driver, 'db' => $db];
        }
    }

    /**
     * Parse the project config.yaml. Search order:
     *   1. MEMHELPER_CONFIG env var
     *   2. ./config.yaml in CWD
     *   3. ./config.yml
     */
    private static function config(): array
    {
        $env = getenv('MEMHELPER_CONFIG');
        $candidates = [];
        if (is_string($env) && $env !== '') {
            $candidates[] = $env;
        }
        $cwd = getcwd() ?: '.';
        $candidates[] = $cwd . '/config.yaml';
        $candidates[] = $cwd . '/config.yml';
        foreach ($candidates as $path) {
            if (is_file($path)) {
                $parsed = Yaml::parseFile($path);
                return is_array($parsed) ? $parsed : [];
            }
        }
        throw new RuntimeException(
            'memhelper: no config.yaml found — point MEMHELPER_CONFIG at one or place config.yaml in CWD'
        );
    }

    // ====================================================================
    //  Public — host application entry point (read-only, fast, static)
    // ====================================================================

    /**
     * Append-ready memory block for the host's prompt. Returns the always-
     * loaded MEMORY.md content (if present) plus the memory entries most
     * relevant to what is being discussed in the conversation. The conversation
     * is also written to the worker's queue so any new facts get extracted in
     * the background; no writes happen on the call's critical path.
     *
     *   $prompt = memhelper::enhance($conversation) . $prompt;
     *
     * @param list<array{role: string, content: string}> $conversation
     */
    public static function enhance(array $conversation): string
    {
        return (new self())->build($conversation);
    }

    private function build(array $conversation): string
    {
        $query = self::queryFromConversation($conversation);
        $hits = $query !== '' ? $this->searchAcrossAll($query, 8) : [];
        $block = $this->composeBlock($hits);
        if ($conversation !== []) {
            $this->enqueueConversation($conversation);
        }
        return $block;
    }

    // ====================================================================
    //  Internal — supervisor worker entry point (does ALL writes)
    // ====================================================================

    /**
     * @internal Infrastructure entry point used by bin/memhelper-worker only.
     * One iteration of background maintenance: drain the conversation queue,
     * refresh the search index across every configured database, and run
     * the once-per-day compaction pass when due. Not part of the host API —
     * application code should call enhance() instead.
     */
    public static function work(): void
    {
        $self = new self();
        $self->processQueue();
        $self->refreshIndexes();
        if ($self->shouldCompact()) {
            $self->compact();
            $self->markCompacted();
        }
    }

    // ====================================================================
    //  Internal — conversation queue (written by public, drained by tick)
    // ====================================================================

    private function queueDir(): string
    {
        return $this->output . '/.queue';
    }

    private function enqueueConversation(array $conversation): void
    {
        if ($conversation === []) {
            return;
        }
        $dir = $this->queueDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        // microtime prefix + random suffix gives a chronological, collision-
        // free filename even under bursty parallel writes from the host app.
        $name = sprintf(
            '%s-%s.json',
            str_replace('.', '-', sprintf('%.6f', microtime(true))),
            bin2hex(random_bytes(4))
        );
        @file_put_contents($dir . '/' . $name, json_encode($conversation));
    }

    private function processQueue(): void
    {
        $dir = $this->queueDir();
        if (!is_dir($dir)) {
            return;
        }
        $files = glob($dir . '/*.json') ?: [];
        if ($files === []) {
            return;
        }
        sort($files);
        foreach ($files as $f) {
            try {
                $raw = (string) file_get_contents($f);
                $convo = json_decode($raw, true);
                if (is_array($convo) && $convo !== []) {
                    $this->extractAndPersist($convo);
                }
            } catch (\Throwable) {
                // bad json or partial write — skip without aborting the batch
            }
            @unlink($f);
        }
    }

    // ====================================================================
    //  Internal — block composition
    // ====================================================================

    private function composeBlock(array $hits): string
    {
        $parts = [];
        $always = $this->readMemoryIndex();
        if ($always !== '') {
            $parts[] = trim($always);
        }
        if ($hits !== []) {
            $entries = [];
            $seen = [];
            foreach ($hits as $hit) {
                $slug = (string) ($hit['slug'] ?? '');
                if ($slug === '' || isset($seen[$slug])) {
                    continue;
                }
                $seen[$slug] = true;
                $kind = (string) ($hit['kind'] ?? 'memory');
                if ($kind === 'memory') {
                    $entry = $this->getMemory($slug);
                    if ($entry !== null) {
                        $entries[] = '## ' . $slug . "\n\n" . trim($entry['body']);
                    }
                } else {
                    $path = self::slugToPath($slug);
                    if ($path !== null) {
                        $snippet = (string) ($hit['snippet'] ?? '');
                        $entries[] = '## ' . basename($path) . ' (' . $path . ')' . "\n\n" . trim($snippet);
                    }
                }
            }
            if ($entries !== []) {
                $parts[] = "Relevant memory for this conversation:\n\n" . implode("\n\n", $entries);
            }
        }
        if ($parts === []) {
            return '';
        }
        return "\n\n=== Memory ===\n\n" . implode("\n\n", $parts) . "\n\n=== /Memory ===\n";
    }

    private function readMemoryIndex(): string
    {
        $file = $this->output . '/MEMORY.md';
        if (!is_file($file)) {
            return '';
        }
        return (string) file_get_contents($file);
    }

    private static function queryFromConversation(array $conversation): string
    {
        // last user message is the best signal for "what are we talking about
        // right now" without paying for a summarisation LLM call. fall back to
        // the last assistant message if no user turn is present.
        for ($i = count($conversation) - 1; $i >= 0; $i--) {
            $m = $conversation[$i];
            if (($m['role'] ?? '') === 'user' && !empty($m['content'])) {
                return (string) $m['content'];
            }
        }
        for ($i = count($conversation) - 1; $i >= 0; $i--) {
            $m = $conversation[$i];
            if (!empty($m['content'])) {
                return (string) $m['content'];
            }
        }
        return '';
    }

    // ====================================================================
    //  Internal — search across all configured DBs
    // ====================================================================

    private function searchAcrossAll(string $query, int $limit): array
    {
        $merged = [];
        foreach ($this->dbs as $entry) {
            $rows = $this->searchOne($entry['driver'], $entry['db'], $query, $limit);
            foreach ($rows as $row) {
                $slug = (string) ($row['slug'] ?? '');
                if ($slug === '') {
                    continue;
                }
                $score = (float) ($row['score'] ?? 0.0);
                // sqlite bm25 returns negative scores (smaller = better); flip
                // so larger always wins regardless of backend.
                if ($entry['driver'] === 'sqlite') {
                    $score = -$score;
                }
                if (!isset($merged[$slug]) || $score > $merged[$slug]['score']) {
                    $row['score'] = $score;
                    $merged[$slug] = $row;
                }
            }
        }
        usort($merged, fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        return array_slice(array_values($merged), 0, $limit);
    }

    private function searchOne(string $driver, dbhelper $db, string $q, int $limit): array
    {
        $limit = max(1, $limit);
        $tokens = self::tokenize($q);
        if ($tokens === []) {
            return [];
        }
        try {
            return match ($driver) {
                'sqlite' => $db->fetch_all(
                    'SELECT slug, kind, title, snippet(memories, 3, "[", "]", "…", 12) AS snippet, bm25(memories) AS score
                     FROM memories WHERE memories MATCH ? ORDER BY score LIMIT ?',
                    implode(' OR ', $tokens),
                    $limit
                ) ?: [],
                'mysql' => $db->fetch_all(
                    'SELECT slug, kind, title, SUBSTRING(body, 1, 240) AS snippet,
                            MATCH(title, body) AGAINST(?) AS score
                     FROM memories WHERE MATCH(title, body) AGAINST(?)
                     ORDER BY score DESC LIMIT ?',
                    implode(' ', $tokens),
                    implode(' ', $tokens),
                    $limit
                ) ?: [],
                'postgres' => $db->fetch_all(
                    "SELECT slug, kind, title,
                            ts_headline('simple', body, to_tsquery('simple', ?), 'StartSel=[, StopSel=], MaxFragments=1, MaxWords=12, MinWords=4') AS snippet,
                            ts_rank(tsv, to_tsquery('simple', ?)) AS score
                     FROM memories WHERE tsv @@ to_tsquery('simple', ?)
                     ORDER BY score DESC LIMIT ?",
                    implode(' | ', $tokens),
                    implode(' | ', $tokens),
                    implode(' | ', $tokens),
                    $limit
                ) ?: []
            };
        } catch (\Throwable) {
            // malformed full-text query (e.g. unbalanced fts5 boolean) just
            // means "no hits"; don't blow up the whole prompt assembly.
            return [];
        }
    }

    /**
     * Lowercase, strip punctuation, drop short tokens and a tiny stopword set
     * so a natural-language question like "what is my editor?" becomes a list
     * of distinctive terms each backend can OR together.
     */
    private static function tokenize(string $query): array
    {
        $stopwords = [
            'the', 'and', 'for', 'with', 'are', 'was', 'were', 'this', 'that',
            'what', 'who', 'how', 'when', 'where', 'why', 'has', 'had', 'have',
            'der', 'die', 'das', 'des', 'dem', 'den', 'ein', 'eine', 'einer',
            'und', 'ist', 'wie', 'was', 'wer', 'wo', 'warum', 'mein', 'meine',
            'sind', 'aber', 'oder', 'für', 'auf', 'bei', 'aus', 'zur', 'zum'
        ];
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [];
        $tokens = [];
        foreach ($parts as $t) {
            if ($t === '' || mb_strlen($t) < 3) {
                continue;
            }
            if (in_array($t, $stopwords, true)) {
                continue;
            }
            $tokens[$t] = true;
        }
        return array_keys($tokens);
    }

    // ====================================================================
    //  Internal — schema + index writes (mirrored across all DBs)
    // ====================================================================

    private function initSchema(string $driver, dbhelper $db): void
    {
        match ($driver) {
            'sqlite' => $this->initSchemaSqlite($db),
            'mysql' => $this->initSchemaMysql($db),
            'postgres' => $this->initSchemaPostgres($db)
        };
    }

    private function initSchemaSqlite(dbhelper $db): void
    {
        try {
            $db->fetch_var('SELECT kind FROM memories LIMIT 0');
        } catch (\Throwable) {
            $db->query('DROP TABLE IF EXISTS memories');
            $db->query('DROP TABLE IF EXISTS memhelper_meta');
        }
        $db->query(
            'CREATE VIRTUAL TABLE IF NOT EXISTS memories USING fts5(slug UNINDEXED, kind UNINDEXED, title, body, tokenize="unicode61 remove_diacritics 2")'
        );
        $db->query(
            'CREATE TABLE IF NOT EXISTS memhelper_meta (setting_key TEXT PRIMARY KEY, setting_value TEXT)'
        );
    }

    private function initSchemaMysql(dbhelper $db): void
    {
        $db->query(
            'CREATE TABLE IF NOT EXISTS memories (
                slug VARCHAR(255) NOT NULL PRIMARY KEY,
                kind VARCHAR(16) NOT NULL,
                title TEXT,
                body MEDIUMTEXT,
                FULLTEXT KEY memories_ftx (title, body)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $db->query(
            'CREATE TABLE IF NOT EXISTS memhelper_meta (
                setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
                setting_value TEXT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    private function initSchemaPostgres(dbhelper $db): void
    {
        $db->query(
            "CREATE TABLE IF NOT EXISTS memories (
                slug TEXT PRIMARY KEY,
                kind TEXT NOT NULL,
                title TEXT,
                body TEXT,
                tsv tsvector GENERATED ALWAYS AS (
                    setweight(to_tsvector('simple', coalesce(title, '')), 'A') ||
                    setweight(to_tsvector('simple', coalesce(body, '')), 'B')
                ) STORED
            )"
        );
        $db->query('CREATE INDEX IF NOT EXISTS memories_tsv_idx ON memories USING gin(tsv)');
        $db->query(
            'CREATE TABLE IF NOT EXISTS memhelper_meta (
                setting_key TEXT PRIMARY KEY,
                setting_value TEXT
            )'
        );
    }

    private function indexUpsertAll(string $slug, string $kind, string $title, string $body): void
    {
        foreach ($this->dbs as $entry) {
            $entry['db']->delete('memories', ['slug' => $slug]);
            $entry['db']->insert('memories', [
                'slug' => $slug,
                'kind' => $kind,
                'title' => $title,
                'body' => $body
            ]);
        }
    }

    private function indexDeleteAll(string $slug): void
    {
        foreach ($this->dbs as $entry) {
            $entry['db']->delete('memories', ['slug' => $slug]);
        }
    }

    // ====================================================================
    //  Internal — index refresh from disk (markdown + files dirs)
    // ====================================================================

    private function refreshIndexes(): void
    {
        // markdown memories: rebuild per-db when the on-disk mtime is newer
        // than the indexed_until cached in that db's meta table.
        foreach ($this->dbs as $entry) {
            $this->refreshMarkdownInto($entry['db']);
        }
        // files: dirs — walk, extract text, mirror to all DBs.
        $this->refreshFilesAcrossAll();
    }

    private function refreshMarkdownInto(dbhelper $db): void
    {
        // unconditional rebuild on every call. filesystem mtime resolution is
        // 1 s on most fs, so two writes inside the same second would otherwise
        // bypass an mtime-cached short-circuit and lose the second file. the
        // memory dir is small enough that a full rebuild is single-digit ms.
        $db->delete('memories', ['kind' => 'memory']);
        foreach (glob($this->output . '/*.md') ?: [] as $file) {
            if (basename($file) === 'MEMORY.md') {
                continue;
            }
            $raw = (string) file_get_contents($file);
            [$fm, $body] = self::splitFrontmatter($raw);
            $db->insert('memories', [
                'slug' => basename($file, '.md'),
                'kind' => 'memory',
                'title' => (string) ($fm['description'] ?? ''),
                'body' => $body
            ]);
        }
    }

    private function refreshFilesAcrossAll(): void
    {
        $current = [];
        foreach ($this->filesDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iter as $f) {
                if (!$f->isFile()) {
                    continue;
                }
                $current[$f->getPathname()] = $f->getMTime();
            }
        }
        // remove rows whose underlying file disappeared from any watch dir
        $known = [];
        foreach ($this->dbs as $entry) {
            $slugs = $entry['db']->fetch_col(
                "SELECT slug FROM memories WHERE kind = ?",
                'attached'
            ) ?: [];
            foreach ($slugs as $s) {
                $known[(string) $s] = true;
            }
        }
        foreach (array_keys($known) as $slug) {
            $path = self::slugToPath($slug);
            if ($path === null || !isset($current[$path])) {
                $this->indexDeleteAll($slug);
            }
        }
        // (re)index each present file — extractText returns null on
        // unsupported types so they're silently skipped.
        foreach ($current as $path => $mtime) {
            $text = $this->extractText((string) $path);
            if ($text === null) {
                continue;
            }
            $this->indexUpsertAll(
                self::pathToSlug((string) $path),
                'attached',
                basename((string) $path),
                $text
            );
        }
    }

    // ====================================================================
    //  Internal — auto-extraction + compaction (LLM-driven)
    // ====================================================================

    private function extractAndPersist(array $conversation): void
    {
        if (!$this->aiAvailable()) {
            return;
        }
        $existing = $this->listMemories();
        $indexLines = array_map(
            fn(array $e): string => '- ' . $e['slug'] . ' (' . ($e['type'] ?? 'reference') . '): ' . ($e['description'] ?? ''),
            $existing
        );
        $convo = '';
        foreach ($conversation as $m) {
            $role = strtoupper((string) ($m['role'] ?? 'user'));
            $content = (string) ($m['content'] ?? '');
            $convo .= $role . ': ' . $content . "\n\n";
        }
        $prompt =
            "You curate a long-term memory store for an LLM assistant. Decide which facts from the conversation below are worth saving as durable memory entries.\n\n" .
            "Existing entries (slug → description):\n" .
            ($indexLines === [] ? '(none yet)' : implode("\n", $indexLines)) .
            "\n\nConversation:\n" .
            $convo .
            "\nReturn a JSON array of actions, each with:\n" .
            "  action: \"add\" | \"update\" | \"delete\" | \"skip\"\n" .
            "  slug: kebab-case (existing for update/delete, new for add)\n" .
            "  content: full memory body (required for add/update)\n" .
            "  description: one-line summary (required for add)\n" .
            "  type: \"user\" | \"feedback\" | \"project\" | \"reference\" (required for add)\n\n" .
            "Only include changes worth keeping. Return [] if nothing is worth saving. Respond with raw JSON, no markdown fences.";
        try {
            $raw = $this->callAi($prompt);
        } catch (\Throwable) {
            return;
        }
        $this->applyDiff(self::decodeDiff($raw));
    }

    private function compact(): void
    {
        if (!$this->aiAvailable()) {
            return;
        }
        $blocks = [];
        foreach (glob($this->output . '/*.md') ?: [] as $file) {
            if (basename($file) === 'MEMORY.md') {
                continue;
            }
            $raw = (string) file_get_contents($file);
            [$fm, $body] = self::splitFrontmatter($raw);
            $blocks[] =
                '## ' . basename($file, '.md') .
                ' (type: ' . ($fm['metadata']['type'] ?? 'reference') . ')' .
                "\nDescription: " . ($fm['description'] ?? '') .
                "\n\n" . trim($body);
        }
        if ($blocks === []) {
            return;
        }
        $prompt =
            "You curate a long-term memory store for an LLM assistant. The current entries are listed below. Identify duplicates, contradictions, and obsolete facts. Merge overlapping entries into a single canonical entry. Drop anything that is no longer relevant.\n\n" .
            implode("\n\n---\n\n", $blocks) .
            "\n\nReturn a JSON array of actions using the same schema as the extract step (action, slug, content, description, type). Respond with raw JSON only, no markdown fences.";
        try {
            $raw = $this->callAi($prompt);
        } catch (\Throwable) {
            return;
        }
        $this->backup();
        $this->applyDiff(self::decodeDiff($raw));
    }

    private function shouldCompact(): bool
    {
        $sentinel = $this->output . '/.last_compact';
        if (!is_file($sentinel)) {
            return true;
        }
        $last = (int) trim((string) @file_get_contents($sentinel));
        return time() - $last > 24 * 3600;
    }

    private function markCompacted(): void
    {
        @file_put_contents($this->output . '/.last_compact', (string) time());
    }

    private function backup(): void
    {
        $dir = $this->output . '/.backup/' . date('Ymd-His');
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        foreach (glob($this->output . '/*.md') ?: [] as $file) {
            @copy($file, $dir . '/' . basename($file));
        }
    }

    private function applyDiff(array $diff): void
    {
        foreach ($diff as $action) {
            $a = (string) ($action['action'] ?? 'skip');
            $slug = (string) ($action['slug'] ?? '');
            if ($slug === '' || $a === 'skip') {
                continue;
            }
            try {
                match ($a) {
                    'add', 'update' => $this->saveMemory(
                        $slug,
                        (string) ($action['content'] ?? ''),
                        isset($action['description']) ? (string) $action['description'] : null,
                        isset($action['type']) ? (string) $action['type'] : null
                    ),
                    'delete' => $this->deleteMemory($slug),
                    default => null
                };
            } catch (RuntimeException) {
                // bad slug or write failure on a single entry shouldn't abort
                // the whole diff; partial progress sticks.
                continue;
            }
        }
    }

    // ====================================================================
    //  Internal — markdown CRUD (used by extraction/compaction)
    // ====================================================================

    private function saveMemory(string $slug, string $content, ?string $description, ?string $type): void
    {
        self::assertValidSlug($slug);
        $path = $this->pathForSlug($slug);
        $existing = is_file($path) ? self::splitFrontmatter((string) file_get_contents($path))[0] : [];
        $frontmatter = [
            'name' => $slug,
            'description' => $description ?? ($existing['description'] ?? ''),
            'metadata' => ['type' => $type ?? ($existing['metadata']['type'] ?? 'reference')]
        ];
        $serialized = self::renderFrontmatter($frontmatter) . trim($content) . "\n";
        if (file_put_contents($path, $serialized) === false) {
            throw new RuntimeException('memhelper: failed to write memory file: ' . $path);
        }
        $this->indexUpsertAll($slug, 'memory', (string) ($frontmatter['description'] ?? ''), trim($content));
    }

    private function deleteMemory(string $slug): bool
    {
        self::assertValidSlug($slug);
        $path = $this->pathForSlug($slug);
        if (!is_file($path)) {
            return false;
        }
        if (!@unlink($path)) {
            throw new RuntimeException('memhelper: failed to delete memory file: ' . $path);
        }
        $this->indexDeleteAll($slug);
        return true;
    }

    private function getMemory(string $slug): ?array
    {
        $path = $this->pathForSlug($slug);
        if (!is_file($path)) {
            return null;
        }
        $raw = (string) file_get_contents($path);
        [$fm, $body] = self::splitFrontmatter($raw);
        return ['slug' => $slug, 'frontmatter' => $fm, 'body' => $body];
    }

    private function listMemories(?string $type = null): array
    {
        $out = [];
        foreach (glob($this->output . '/*.md') ?: [] as $file) {
            if (basename($file) === 'MEMORY.md') {
                continue;
            }
            $head = (string) file_get_contents($file, false, null, 0, 4096);
            [$fm] = self::splitFrontmatter($head);
            $entryType = $fm['metadata']['type'] ?? null;
            if ($type !== null && $entryType !== $type) {
                continue;
            }
            $out[] = [
                'slug' => basename($file, '.md'),
                'description' => $fm['description'] ?? '',
                'type' => $entryType
            ];
        }
        return $out;
    }

    // ====================================================================
    //  Internal — aihelper bridge
    // ====================================================================

    private function aiAvailable(): bool
    {
        return !empty($this->aiConfig['provider']) && !empty($this->aiConfig['model']);
    }

    private function ai(): aihelper
    {
        if ($this->ai === null) {
            $apiKey = (string) ($this->aiConfig['api_key'] ?? '');
            $this->ai = aihelper::create(
                provider: (string) $this->aiConfig['provider'],
                model: (string) $this->aiConfig['model'],
                api_key: $apiKey !== '' ? $apiKey : null
            );
        }
        return $this->ai;
    }

    private function callAi(string $prompt): string
    {
        $result = $this->ai()->ask(prompt: $prompt);
        if (!($result['success'] ?? false)) {
            throw new RuntimeException('memhelper: aihelper call failed: ' . ($result['response'] ?? 'unknown error'));
        }
        return (string) ($result['response'] ?? '');
    }

    private static function decodeDiff(string $raw): array
    {
        $raw = trim($raw);
        if (str_starts_with($raw, '```')) {
            $raw = (string) preg_replace('/^```(?:json)?\r?\n|\r?\n```$/m', '', $raw);
            $raw = trim($raw);
        }
        $start = strpos($raw, '[');
        $end = strrpos($raw, ']');
        if ($start === false || $end === false || $end <= $start) {
            return [];
        }
        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
        return is_array($decoded) ? $decoded : [];
    }

    // ====================================================================
    //  Internal — text extraction
    // ====================================================================

    private function extractText(string $path): ?string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'md', 'txt', 'csv', 'json', 'yaml', 'yml' => @file_get_contents($path) ?: null,
            'pdf' => self::pdfToText($path),
            default => null
        };
    }

    private static function pdfToText(string $path): ?string
    {
        $bin = trim((string) @shell_exec('command -v pdftotext'));
        if ($bin === '') {
            return null;
        }
        $output = [];
        $code = 0;
        @exec(escapeshellcmd($bin) . ' -layout ' . escapeshellarg($path) . ' - 2>/dev/null', $output, $code);
        return $code === 0 ? implode("\n", $output) : null;
    }

    // ====================================================================
    //  Internal — frontmatter, slugs, paths
    // ====================================================================

    private function pathForSlug(string $slug): string
    {
        self::assertValidSlug($slug);
        return $this->output . '/' . $slug . '.md';
    }

    private static function assertValidSlug(string $slug): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,127}$/', $slug)) {
            throw new RuntimeException('memhelper: invalid slug "' . $slug . '" — use kebab/snake_case ASCII');
        }
    }

    private static function pathToSlug(string $path): string
    {
        return 'attached:' . $path;
    }

    private static function slugToPath(string $slug): ?string
    {
        if (!str_starts_with($slug, 'attached:')) {
            return null;
        }
        return substr($slug, strlen('attached:'));
    }

    private static function splitFrontmatter(string $raw): array
    {
        if (!str_starts_with($raw, "---\n") && !str_starts_with($raw, "---\r\n")) {
            return [[], $raw];
        }
        $rest = preg_replace('/^---\r?\n/', '', $raw, 1) ?? '';
        $end = preg_match('/\r?\n---\r?\n/', $rest, $m, PREG_OFFSET_CAPTURE);
        if ($end !== 1) {
            return [[], $raw];
        }
        $yamlBlock = substr($rest, 0, $m[0][1]);
        $body = substr($rest, $m[0][1] + strlen($m[0][0]));
        return [self::parseTinyYaml($yamlBlock), $body];
    }

    private static function parseTinyYaml(string $yaml): array
    {
        $out = [];
        $nested = null;
        foreach (preg_split('/\r?\n/', $yaml) ?: [] as $line) {
            if ($line === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            if (str_starts_with($line, '  ') && $nested !== null) {
                $kv = explode(':', trim($line), 2);
                if (count($kv) === 2) {
                    $out[$nested][trim($kv[0])] = trim($kv[1], " \"'");
                }
                continue;
            }
            $nested = null;
            $kv = explode(':', $line, 2);
            if (count($kv) !== 2) {
                continue;
            }
            $key = trim($kv[0]);
            $value = trim($kv[1]);
            if ($value === '') {
                $out[$key] = [];
                $nested = $key;
            } else {
                $out[$key] = trim($value, " \"'");
            }
        }
        return $out;
    }

    private static function renderFrontmatter(array $fm): string
    {
        $lines = ['---'];
        foreach ($fm as $k => $v) {
            if (is_array($v)) {
                $lines[] = $k . ':';
                foreach ($v as $ck => $cv) {
                    $lines[] = '  ' . $ck . ': ' . self::yamlScalar((string) $cv);
                }
            } else {
                $lines[] = $k . ': ' . self::yamlScalar((string) $v);
            }
        }
        $lines[] = '---';
        $lines[] = '';
        return implode("\n", $lines) . "\n";
    }

    private static function yamlScalar(string $value): string
    {
        if ($value === '' || preg_match('/[:#]|^\s|^-/', $value)) {
            return '"' . str_replace('"', '\\"', $value) . '"';
        }
        return $value;
    }
}
