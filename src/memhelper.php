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
 *   $mem = new memhelper('/path/to/config.yaml');        // yaml path is required
 *   $prompt = $mem->enhance($conversation) . $prompt;    // host API
 *   $mem->work();                                        // worker tick
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
    /** Internal SQLite holding the FTS5 index, state table and (future) vectors. */
    private dbhelper $db;
    /**
     * External input databases the worker (eventually) walks as additional
     * knowledge sources — completely separate from the internal $db. Held as
     * raw config arrays so connections are only opened when actually used.
     *
     * @var list<array<string, mixed>>
     */
    private array $inputDbs = [];
    private ?string $logPath = null;

    /**
     * The host must point the constructor at the absolute path of its
     * memhelper yaml — there is no implicit discovery. The optional second
     * argument enables verbose logging to that file (append mode); messages
     * are additionally written to stderr when running under the CLI SAPI so
     * supervisord-managed processes surface them too.
     */
    public function __construct(string $configPath, ?string $logPath = null)
    {
        $this->logPath = $logPath !== null && $logPath !== '' ? $logPath : null;
        $cfg = self::config($configPath);

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

        // internal database — required, always sqlite. holds the FTS5 index,
        // the per-slug state table and any future vector tables. separate from
        // anything the host application owns.
        $dbPath = (string) ($cfg['database'] ?? '');
        if ($dbPath === '') {
            throw new RuntimeException('memhelper: database (absolute sqlite path) is required in config.yaml');
        }
        $dbDir = dirname($dbPath);
        if (!is_dir($dbDir) && !@mkdir($dbDir, 0775, true) && !is_dir($dbDir)) {
            throw new RuntimeException('memhelper: database dir does not exist and could not be created: ' . $dbDir);
        }
        $this->db = new dbhelper();
        $this->db->connect('pdo', 'sqlite', $dbPath);
        $this->initSchemaSqlite($this->db);

        // input_dbs: list of read-only knowledge sources (mysql/postgres/sqlite).
        // configurations are stored verbatim; connections are opened lazily
        // when the worker walks them.
        $inputDbs = $cfg['input_dbs'] ?? [];
        if (is_array($inputDbs)) {
            foreach ($inputDbs as $dbc) {
                if (is_array($dbc)) {
                    $this->inputDbs[] = $dbc;
                }
            }
        }
    }

    /**
     * Append a line to the log file (when configured) and mirror to stderr
     * under the CLI SAPI so supervisord captures it too. Best-effort — write
     * failures are silent so logging cannot abort a tick.
     */
    private function log(string $msg, string $level = 'INFO'): void
    {
        $line = '[' . date('c') . '] [' . $level . '] ' . $msg . PHP_EOL;
        if ($this->logPath !== null) {
            @file_put_contents($this->logPath, $line, FILE_APPEND);
        }
        if (PHP_SAPI === 'cli') {
            @fwrite(STDERR, $line);
        }
    }

    /** @var array<string, array{lastTime: float, lastDone: int}> */
    private array $progressState = [];

    /**
     * Throttled progress reporter — emits at most one line per ~2 s or per
     * 10 processed items, plus a final line on completion. Keeps the log
     * readable on initial bulk indexing of thousands of files without
     * losing visibility of where you are.
     */
    private function progress(string $tag, int $done, int $total, string $note): void
    {
        if ($total <= 0) {
            return;
        }
        $now = microtime(true);
        $state = $this->progressState[$tag] ?? ['lastTime' => 0.0, 'lastDone' => 0];
        $emit =
            $done === $total ||
            $done - $state['lastDone'] >= 10 ||
            $now - $state['lastTime'] >= 2.0;
        if (!$emit) {
            return;
        }
        $pct = (int) round($done * 100 / $total);
        $this->log(sprintf('%s: [%d/%d] (%d%%) %s', $tag, $done, $total, $pct, $note));
        $this->progressState[$tag] = ['lastTime' => $now, 'lastDone' => $done];
    }

    private static function config(string $configPath): array
    {
        if ($configPath === '' || !is_file($configPath)) {
            throw new RuntimeException('memhelper: config yaml not found at "' . $configPath . '"');
        }
        $parsed = Yaml::parseFile($configPath);
        return is_array($parsed) ? $parsed : [];
    }

    // ====================================================================
    //  Public — host application entry point (read-only, fast)
    // ====================================================================

    /**
     * Append-ready memory block for the host's prompt. Returns the memory
     * entries most relevant to what is being discussed in the conversation.
     * The conversation is also written to the worker's queue so any new
     * facts get extracted in the background; no writes happen on the call's
     * critical path.
     *
     *   $mem = new memhelper('/path/to/config.yaml');
     *   $prompt = $mem->enhance($conversation) . $prompt;
     *
     * `$conversation` may be any of:
     *   - a plain string (treated as a single user message)
     *   - a list of strings (alternating user / assistant turns)
     *   - OpenAI / Anthropic shape: `[['role' => 'user'|'assistant'|'system', 'content' => string|array], ...]`
     *     (content may be a string OR an array of content blocks with `text`)
     *   - Google Gemini shape: `[['role' => 'user'|'model', 'parts' => [['text' => '...']]], ...]`
     *   - any custom shape where each entry carries a `content`, `text` or `message` key
     *
     * Unknown shapes degrade gracefully — anything that yields no extractable
     * text is just dropped, never throws.
     */
    public function enhance(mixed $conversation): string
    {
        $normalized = self::normalizeConversation($conversation);
        $query = self::queryFromConversation($normalized);
        $hits = $query !== '' ? $this->search($query, 8) : [];
        $block = $this->composeBlock($hits);
        if ($normalized !== []) {
            $this->enqueueConversation($normalized);
        }
        $this->log(sprintf(
            'enhance: turns=%d query=%dB hits=%d block=%dB',
            count($normalized),
            strlen($query),
            count($hits),
            strlen($block)
        ));
        return $block;
    }

    /**
     * Reduce any supported conversation shape to a flat list of
     * `['role' => string, 'content' => string]` entries so the search query
     * extraction and the worker's extraction prompt can stay simple.
     */
    private static function normalizeConversation(mixed $input): array
    {
        if (is_string($input)) {
            return $input === '' ? [] : [['role' => 'user', 'content' => $input]];
        }
        if (!is_array($input)) {
            return [];
        }
        $out = [];
        foreach (array_values($input) as $i => $msg) {
            if (is_string($msg)) {
                // bare-string list — assume the host gave us turns in order
                // starting with the user.
                $out[] = [
                    'role' => $i % 2 === 0 ? 'user' : 'assistant',
                    'content' => $msg
                ];
                continue;
            }
            if (!is_array($msg)) {
                continue;
            }
            $role = self::normalizeRole((string) ($msg['role'] ?? 'user'));
            $content = self::extractMessageText($msg);
            if ($content === '') {
                continue;
            }
            $out[] = ['role' => $role, 'content' => $content];
        }
        return $out;
    }

    private static function normalizeRole(string $role): string
    {
        // gemini uses 'model' where openai/anthropic use 'assistant'.
        return $role === 'model' ? 'assistant' : $role;
    }

    private static function extractMessageText(array $msg): string
    {
        // openai / anthropic: content can be a string OR an array of content
        // blocks (each potentially `{type, text}` or `{text}`).
        if (isset($msg['content'])) {
            $c = $msg['content'];
            if (is_string($c)) {
                return $c;
            }
            if (is_array($c)) {
                $parts = [];
                foreach ($c as $block) {
                    if (is_string($block)) {
                        $parts[] = $block;
                        continue;
                    }
                    if (is_array($block)) {
                        if (isset($block['text']) && is_string($block['text'])) {
                            $parts[] = $block['text'];
                        } elseif (isset($block['content']) && is_string($block['content'])) {
                            $parts[] = $block['content'];
                        }
                    }
                }
                if ($parts !== []) {
                    return implode("\n", $parts);
                }
            }
        }
        // gemini: { role, parts: [ { text }, ... ] }
        if (isset($msg['parts']) && is_array($msg['parts'])) {
            $parts = [];
            foreach ($msg['parts'] as $p) {
                if (is_string($p)) {
                    $parts[] = $p;
                } elseif (is_array($p) && isset($p['text']) && is_string($p['text'])) {
                    $parts[] = $p['text'];
                }
            }
            if ($parts !== []) {
                return implode("\n", $parts);
            }
        }
        // legacy / custom fallback keys
        foreach (['text', 'message', 'body'] as $key) {
            if (isset($msg[$key]) && is_string($msg[$key])) {
                return $msg[$key];
            }
        }
        return '';
    }

    // ====================================================================
    //  Public — supervisor worker entry point (does ALL writes)
    // ====================================================================

    /**
     * @internal Infrastructure entry point used by bin/memhelper-worker only.
     * One iteration of background maintenance: drain the conversation queue,
     * refresh the search index across every configured database, and run
     * the once-per-day compaction pass when due. Not part of the host API —
     * application code should call enhance() instead.
     */
    public function work(): void
    {
        // file-based lock — flock is atomic and the OS releases the lock when
        // the process dies, so a crash mid-tick can't leave a stale sentinel.
        // a long-running tick (initial bulk index, big compaction) makes the
        // next 30 s polling cycle find the lock taken and exit silently.
        $dataDir = $this->dataDir();
        if (!is_dir($dataDir) && !@mkdir($dataDir, 0775, true) && !is_dir($dataDir)) {
            $this->log('failed to create data dir: ' . $dataDir, 'ERROR');
            return;
        }
        $lockPath = $dataDir . '/tick.lock';
        $fp = @fopen($lockPath, 'c');
        if ($fp === false) {
            $this->log('failed to open tick.lock', 'ERROR');
            return;
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            $this->log('another tick is still running, skipping');
            fclose($fp);
            return;
        }
        $tStart = microtime(true);
        $this->log('tick start');
        try {
            @ftruncate($fp, 0);
            @fwrite($fp, (string) getmypid());
            // phase 1: conversations → ai extracts facts → md files
            $this->processQueue();
            // phase 2: input_files (and later input_dbs) → state.body, no fts5
            $this->refreshSources();
            // phase 3: ai distils undistilled / changed sources → md files
            $this->distillSources();
            // phase 4: sync the curated md files into the fts5 search index
            $this->refreshMemoryIndex();
            // phase 5: periodic compaction
            if ($this->shouldCompact()) {
                $this->log('compaction is due (last run > 24 h ago)');
                $this->compact();
                $this->markCompacted();
            } else {
                $this->log('compaction skipped (last run within 24 h)');
            }
            $this->log(sprintf('tick complete in %.2fs', microtime(true) - $tStart));
        } catch (\Throwable $e) {
            $this->log('tick aborted: ' . $e->getMessage(), 'ERROR');
            throw $e;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    // ====================================================================
    //  Internal — conversation queue (written by public, drained by tick)
    // ====================================================================

    private function dataDir(): string
    {
        return $this->output . '/.data';
    }

    private function queueDir(): string
    {
        return $this->dataDir() . '/queue';
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
            $this->log('queue: dir not present yet (no enhance() calls?)');
            return;
        }
        $files = glob($dir . '/*.json') ?: [];
        if ($files === []) {
            $this->log('queue: empty');
            return;
        }
        sort($files);
        $total = count($files);
        $this->log('queue: ' . $total . ' conversation(s) to process');
        foreach ($files as $i => $f) {
            $idx = $i + 1;
            $pct = (int) round($idx * 100 / $total);
            try {
                $raw = (string) file_get_contents($f);
                $convo = json_decode($raw, true);
                if (is_array($convo) && $convo !== []) {
                    $this->log(sprintf('queue: [%d/%d] (%d%%) extracting from %d-turn conversation', $idx, $total, $pct, count($convo)));
                    $this->extractAndPersist($convo);
                } else {
                    $this->log(sprintf('queue: [%d/%d] skipped (empty or unparseable json)', $idx, $total));
                }
            } catch (\Throwable $e) {
                $this->log(sprintf('queue: [%d/%d] threw %s', $idx, $total, $e->getMessage()), 'WARN');
            }
            @unlink($f);
        }
        $this->log('queue: drain complete');
    }

    // ====================================================================
    //  Internal — block composition
    // ====================================================================

    private function composeBlock(array $hits): string
    {
        if ($hits === []) {
            return '';
        }
        // search only ever returns kind='memory' (the memories fts5 table is
        // exclusively populated by refreshMemoryIndex from output/*.md). raw
        // sources are not searchable — they are read by the ai during
        // distillation and surface as curated md entries here.
        $entries = [];
        $seen = [];
        foreach ($hits as $hit) {
            $slug = (string) ($hit['slug'] ?? '');
            if ($slug === '' || isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $entry = $this->getMemory($slug);
            if ($entry !== null) {
                $entries[] = '## ' . $slug . "\n\n" . trim($entry['body']);
            }
        }
        if ($entries === []) {
            return '';
        }
        return "\n\n=== Memory ===\n\n" . implode("\n\n", $entries) . "\n\n=== /Memory ===\n";
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

    private function search(string $q, int $limit): array
    {
        $limit = max(1, $limit);
        $tokens = self::tokenize($q);
        if ($tokens === []) {
            return [];
        }
        try {
            // sqlite bm25 returns negative scores (smaller = better) — keep as
            // returned, the ORDER BY ASC takes care of ranking.
            return $this->db->fetch_all(
                'SELECT slug, kind, title, snippet(memories, 3, "[", "]", "…", 12) AS snippet, bm25(memories) AS score
                 FROM memories WHERE memories MATCH ? ORDER BY score LIMIT ?',
                implode(' OR ', $tokens),
                $limit
            ) ?: [];
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

    private function initSchemaSqlite(dbhelper $db): void
    {
        // detect schema drift (older deployments missing kind/body/distilled_at)
        // and drop — derived state, cheap to redo from disk.
        try {
            $db->fetch_var('SELECT kind FROM memories LIMIT 0');
            $db->fetch_var('SELECT body, distilled_at FROM memhelper_state LIMIT 0');
        } catch (\Throwable) {
            $db->query('DROP TABLE IF EXISTS memories');
            $db->query('DROP TABLE IF EXISTS memhelper_meta');
            $db->query('DROP TABLE IF EXISTS memhelper_state');
        }
        // memories: ONLY curated md entries (kind='memory'). sources never
        // land here — they are read by the ai during distillation, then the
        // resulting memory entries get indexed.
        $db->query(
            'CREATE VIRTUAL TABLE IF NOT EXISTS memories USING fts5(slug UNINDEXED, kind UNINDEXED, title, body, tokenize="unicode61 remove_diacritics 2")'
        );
        // memhelper_state: bookkeeping for every tracked artefact.
        //   kind='memory' — a curated md in output/, mirrors a memories row.
        //   kind='source' — a raw input (file or db row); body holds the
        //                    extracted text the ai distills from.
        //   distilled_at — for sources only: when the ai last summarised the
        //                   body into memory entries. null = needs work.
        $db->query(
            'CREATE TABLE IF NOT EXISTS memhelper_state (
                slug TEXT PRIMARY KEY,
                kind TEXT NOT NULL,
                mtime INTEGER NOT NULL,
                hash TEXT NOT NULL,
                body TEXT,
                indexed_at INTEGER NOT NULL,
                distilled_at INTEGER
            )'
        );
        $db->query('CREATE INDEX IF NOT EXISTS memhelper_state_kind ON memhelper_state(kind)');
    }

    private function writeEntry(string $slug, string $kind, string $title, string $body, int $mtime): void
    {
        $this->db->delete('memories', ['slug' => $slug]);
        $this->db->insert('memories', [
            'slug' => $slug,
            'kind' => $kind,
            'title' => $title,
            'body' => $body
        ]);
        $this->db->delete('memhelper_state', ['slug' => $slug]);
        $this->db->insert('memhelper_state', [
            'slug' => $slug,
            'kind' => $kind,
            'mtime' => $mtime,
            'hash' => sha1($body),
            'indexed_at' => time()
        ]);
    }

    private function removeEntry(string $slug): void
    {
        $this->db->delete('memories', ['slug' => $slug]);
        $this->db->delete('memhelper_state', ['slug' => $slug]);
    }

    /**
     * Record a file as known-unsupported so subsequent ticks don't call the
     * extractor again. The state row keeps it in `current`, the mtime check
     * matches unless someone re-saves the file, and the sentinel hash makes
     * the kind distinguishable from a real index entry.
     */
    private const SKIPPED_HASH = '__unsupported__';

    private function markSkipped(string $slug, int $mtime): void
    {
        $this->db->delete('memories', ['slug' => $slug]);
        $this->db->delete('memhelper_state', ['slug' => $slug]);
        $this->db->insert('memhelper_state', [
            'slug' => $slug,
            'kind' => 'attached',
            'mtime' => $mtime,
            'hash' => self::SKIPPED_HASH,
            'indexed_at' => time()
        ]);
    }

    /**
     * Drop rows that exist in `memories` but have no matching state — happens
     * after schema upgrades or out-of-band DB tampering. Keeps state
     * authoritative without paying for a full rebuild.
     */
    private function purgeOrphans(): int
    {
        $known = $this->db->fetch_col('SELECT slug FROM memhelper_state') ?: [];
        $present = $this->db->fetch_col('SELECT slug FROM memories') ?: [];
        $orphans = array_diff(
            array_map('strval', $present),
            array_map('strval', $known)
        );
        foreach ($orphans as $slug) {
            $this->db->delete('memories', ['slug' => $slug]);
        }
        return count($orphans);
    }

    // ====================================================================
    //  Internal — index refresh from disk (markdown + files dirs)
    // ====================================================================

    /**
     * @return array<string, array{path: string, mtime: int}> keyed by source-slug
     */
    private function scanFilesDirs(): array
    {
        $desired = [];
        foreach ($this->filesDirs as $dir) {
            if (!is_dir($dir)) {
                $this->log('sources: dir missing — ' . $dir, 'WARN');
                continue;
            }
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iter as $f) {
                if (!$f->isFile()) {
                    continue;
                }
                $path = (string) $f->getPathname();
                $desired[self::pathToSlug($path)] = ['path' => $path, 'mtime' => (int) $f->getMTime()];
            }
        }
        return $desired;
    }

    /**
     * Sync the curated md files under `output/` into the memories FTS5 index.
     * Idempotent, diff-aware, resumable — identical mechanics to refreshSources
     * but writes to memories (the searchable layer) instead of state.body.
     */
    private function refreshMemoryIndex(): void
    {
        $tStart = microtime(true);
        $orphans = $this->purgeOrphans();
        if ($orphans > 0) {
            $this->log("memory-index: dropped {$orphans} orphan(s) from memories");
        }

        $desired = [];
        foreach (glob($this->output . '/*.md') ?: [] as $file) {
            $slug = basename($file, '.md');
            if (!self::isValidSlug($slug)) {
                continue;
            }
            $desired[$slug] = ['path' => $file, 'mtime' => (int) filemtime($file)];
        }

        $rows = $this->db->fetch_all("SELECT slug, mtime, hash FROM memhelper_state WHERE kind = ?", 'memory') ?: [];
        $current = [];
        foreach ($rows as $r) {
            $current[(string) $r['slug']] = ['mtime' => (int) $r['mtime'], 'hash' => (string) $r['hash']];
        }

        $toAdd = [];
        $toUpdate = [];
        $toRemove = [];
        $unchanged = 0;
        foreach ($desired as $slug => $info) {
            if (!isset($current[$slug])) {
                $toAdd[] = $slug;
            } elseif ($current[$slug]['mtime'] !== $info['mtime']) {
                $toUpdate[] = $slug;
            } else {
                $unchanged++;
            }
        }
        foreach (array_keys($current) as $slug) {
            if (!isset($desired[$slug])) {
                $toRemove[] = $slug;
            }
        }

        $total = count($toAdd) + count($toUpdate) + count($toRemove);
        if ($total === 0) {
            $this->log(sprintf('memory-index: nothing changed — unchanged=%d (%.2fs)', $unchanged, microtime(true) - $tStart));
            return;
        }
        $this->log(sprintf(
            'memory-index: plan — add=%d update=%d remove=%d unchanged=%d',
            count($toAdd), count($toUpdate), count($toRemove), $unchanged
        ));

        $done = 0;
        $tag = 'memory-index';

        foreach ($toRemove as $slug) {
            $this->removeEntry($slug);
            $done++;
            $this->progress($tag, $done, $total, "removed $slug");
        }
        foreach ($toAdd as $slug) {
            $info = $desired[$slug];
            $raw = (string) file_get_contents($info['path']);
            [$fm, $body] = self::splitFrontmatter($raw);
            $this->writeEntry($slug, 'memory', (string) ($fm['description'] ?? ''), $body, $info['mtime']);
            $done++;
            $this->progress($tag, $done, $total, "added $slug");
        }
        foreach ($toUpdate as $slug) {
            $info = $desired[$slug];
            $raw = (string) file_get_contents($info['path']);
            [$fm, $body] = self::splitFrontmatter($raw);
            $hash = sha1($body);
            if ($hash === $current[$slug]['hash']) {
                $this->db->update(
                    'memhelper_state',
                    ['mtime' => $info['mtime'], 'indexed_at' => time()],
                    ['slug' => $slug]
                );
                $done++;
                $this->progress($tag, $done, $total, "touched $slug (mtime-only)");
                continue;
            }
            $this->writeEntry($slug, 'memory', (string) ($fm['description'] ?? ''), $body, $info['mtime']);
            $done++;
            $this->progress($tag, $done, $total, "updated $slug");
        }
        $this->log(sprintf('memory-index: complete in %.2fs', microtime(true) - $tStart));
    }

    /**
     * Walk input_files (and later input_dbs), extract text, and persist each
     * source into memhelper_state with kind='source' + body. Sources are NOT
     * inserted into the memories FTS5 index — that one only holds curated
     * memory entries (output/*.md). When a source's content changes its
     * distilled_at is cleared so the next distillSources() pass picks it up.
     */
    private function refreshSources(): void
    {
        if ($this->filesDirs === [] && $this->inputDbs === []) {
            return;
        }
        $tStart = microtime(true);

        $desired = $this->scanFilesDirs();
        // input_dbs are configured but the row walker is not implemented yet —
        // surface that so the operator knows their entries are parsed but inert.
        if ($this->inputDbs !== []) {
            $this->log('input_dbs: configured but db-row indexing is not yet implemented', 'WARN');
        }

        $rows = $this->db->fetch_all(
            "SELECT slug, mtime, hash FROM memhelper_state WHERE kind = ?",
            'source'
        ) ?: [];
        $current = [];
        foreach ($rows as $r) {
            $current[(string) $r['slug']] = ['mtime' => (int) $r['mtime'], 'hash' => (string) $r['hash']];
        }

        $toAdd = [];
        $toUpdate = [];
        $toRemove = [];
        $unchanged = 0;
        foreach ($desired as $slug => $info) {
            if (!isset($current[$slug])) {
                $toAdd[] = $slug;
            } elseif ($current[$slug]['mtime'] !== $info['mtime']) {
                $toUpdate[] = $slug;
            } else {
                $unchanged++;
            }
        }
        foreach (array_keys($current) as $slug) {
            if (!isset($desired[$slug])) {
                $toRemove[] = $slug;
            }
        }

        $total = count($toAdd) + count($toUpdate) + count($toRemove);
        if ($total === 0) {
            $this->log(sprintf('sources: nothing changed — unchanged=%d (%.2fs)', $unchanged, microtime(true) - $tStart));
            return;
        }
        $this->log(sprintf(
            'sources: plan — add=%d update=%d remove=%d unchanged=%d',
            count($toAdd), count($toUpdate), count($toRemove), $unchanged
        ));

        $done = 0;
        $tag = 'sources';

        foreach ($toRemove as $slug) {
            $this->db->delete('memhelper_state', ['slug' => $slug]);
            $done++;
            $this->progress($tag, $done, $total, "removed " . basename((string) self::slugToPath($slug)));
        }
        foreach ($toAdd as $slug) {
            $info = $desired[$slug];
            $text = $this->extractText($info['path']);
            if ($text === null) {
                $this->markSkipped($slug, $info['mtime']);
                $done++;
                $this->progress($tag, $done, $total, "skipped " . basename($info['path']) . " (unsupported)");
                continue;
            }
            $this->db->insert('memhelper_state', [
                'slug' => $slug,
                'kind' => 'source',
                'mtime' => $info['mtime'],
                'hash' => sha1($text),
                'body' => $text,
                'indexed_at' => time()
                // distilled_at intentionally null — distillSources() picks it up
            ]);
            $done++;
            $this->progress($tag, $done, $total, "added " . basename($info['path']));
        }
        foreach ($toUpdate as $slug) {
            $info = $desired[$slug];
            $text = $this->extractText($info['path']);
            if ($text === null) {
                $this->markSkipped($slug, $info['mtime']);
                $done++;
                $this->progress($tag, $done, $total, "still skipped " . basename($info['path']) . " (unsupported)");
                continue;
            }
            $newHash = sha1($text);
            if (
                $current[$slug]['hash'] !== self::SKIPPED_HASH &&
                $newHash === $current[$slug]['hash']
            ) {
                // content unchanged — just bump mtime, leave distilled_at alone
                $this->db->update(
                    'memhelper_state',
                    ['mtime' => $info['mtime'], 'indexed_at' => time()],
                    ['slug' => $slug]
                );
                $done++;
                $this->progress($tag, $done, $total, "touched " . basename($info['path']) . " (mtime-only)");
                continue;
            }
            // content changed (or was previously skipped) — clear distilled_at
            // so distillSources() re-processes this row next.
            $this->db->delete('memhelper_state', ['slug' => $slug]);
            $this->db->insert('memhelper_state', [
                'slug' => $slug,
                'kind' => 'source',
                'mtime' => $info['mtime'],
                'hash' => $newHash,
                'body' => $text,
                'indexed_at' => time()
            ]);
            $done++;
            $this->progress($tag, $done, $total, "updated " . basename($info['path']));
        }
        $this->log(sprintf('sources: complete in %.2fs', microtime(true) - $tStart));
    }

    /**
     * For every source row that has either never been processed or whose
     * content changed since the last distillation, ask the ai to extract
     * durable facts and apply the resulting diff to output/*.md.
     */
    private function distillSources(): void
    {
        if (!$this->aiAvailable()) {
            // log only when there is actually something pending so we don't
            // spam the log on every tick of an ai-less deployment.
            $pending = (int) ($this->db->fetch_var(
                "SELECT COUNT(*) FROM memhelper_state
                 WHERE kind = 'source' AND hash != ?
                   AND (distilled_at IS NULL OR distilled_at < indexed_at)",
                self::SKIPPED_HASH
            ) ?: 0);
            if ($pending > 0) {
                $this->log("distill: {$pending} source(s) pending but ai is not configured", 'WARN');
            }
            return;
        }
        $sources = $this->db->fetch_all(
            "SELECT slug, body FROM memhelper_state
             WHERE kind = 'source' AND hash != ?
               AND (distilled_at IS NULL OR distilled_at < indexed_at)
             ORDER BY indexed_at ASC",
            self::SKIPPED_HASH
        ) ?: [];
        if ($sources === []) {
            $this->log('distill: no pending sources');
            return;
        }
        $this->log('distill: ' . count($sources) . ' pending source(s)');
        $tStart = microtime(true);
        $total = count($sources);
        foreach ($sources as $i => $row) {
            $slug = (string) $row['slug'];
            $body = (string) $row['body'];
            $idx = $i + 1;
            $label = (string) (self::slugToPath($slug) ?? $slug);
            try {
                $diff = $this->distillOne($label, $body);
            } catch (\Throwable $e) {
                $this->log(sprintf('distill: [%d/%d] %s — ai failed: %s', $idx, $total, basename($label), $e->getMessage()), 'WARN');
                continue;
            }
            $counts = ['add' => 0, 'update' => 0, 'delete' => 0, 'skip' => 0];
            foreach ($diff as $d) {
                $a = (string) ($d['action'] ?? 'skip');
                $counts[$a] = ($counts[$a] ?? 0) + 1;
            }
            $this->log(sprintf(
                'distill: [%d/%d] %s — add=%d update=%d delete=%d skip=%d',
                $idx, $total, basename($label),
                $counts['add'], $counts['update'], $counts['delete'], $counts['skip']
            ));
            $this->applyDiff($diff);
            // mark as distilled regardless of action count — an empty diff is
            // a valid answer ("nothing in this source is worth saving") and
            // shouldn't keep coming back to the ai on every tick.
            $this->db->update(
                'memhelper_state',
                ['distilled_at' => time()],
                ['slug' => $slug]
            );
            $this->progress('distill', $idx, $total, basename($label));
        }
        $this->log(sprintf('distill: complete in %.2fs', microtime(true) - $tStart));
    }

    /**
     * Single-source distillation — sends body to the ai and returns the
     * decoded diff array. Encapsulated so distillSources() stays readable.
     */
    private function distillOne(string $label, string $body): array
    {
        $existing = $this->listMemories();
        $indexLines = array_map(
            fn(array $e): string => '- ' . $e['slug'] . ' (' . ($e['type'] ?? 'reference') . '): ' . ($e['description'] ?? ''),
            $existing
        );
        $prompt =
            "You curate a long-term memory store for an LLM assistant. A source has just been added or updated. Extract any durable facts worth keeping as memory entries.\n\n" .
            "Source: " . $label . "\n" .
            "Content:\n" . $body . "\n\n" .
            "Existing memory entries (slug → description):\n" .
            ($indexLines === [] ? '(none yet)' : implode("\n", $indexLines)) .
            "\n\nReturn a JSON array of actions, each with:\n" .
            "  action: \"add\" | \"update\" | \"skip\"\n" .
            "  slug: kebab-case (existing for update, new for add)\n" .
            "  content: full memory body (required for add/update)\n" .
            "  description: one-line summary (required for add)\n" .
            "  type: \"user\" | \"feedback\" | \"project\" | \"reference\" (required for add)\n\n" .
            "A memory entry is a distinct, durable fact — NOT the whole document. Good examples: a person's contact info, a configuration value + its purpose, a decision + its reason, a workflow step. Bad examples: generic how-to content, time-sensitive data, trivia.\n" .
            "Many sources won't yield anything memorable — return [] in that case.\n" .
            "Respond with raw JSON only, no markdown fences.";
        $raw = $this->callAi($prompt);
        return self::decodeDiff($raw);
    }

    // ====================================================================
    //  Internal — auto-extraction + compaction (LLM-driven)
    // ====================================================================

    private function extractAndPersist(array $conversation): void
    {
        if (!$this->aiAvailable()) {
            $this->log('extract: ai not configured — skipping (set ai.provider + ai.model in config.yaml)', 'WARN');
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
        $this->log(sprintf('extract: calling ai (%s/%s) with prompt of %d bytes', $this->aiConfig['provider'] ?? '?', $this->aiConfig['model'] ?? '?', strlen($prompt)));
        $tStart = microtime(true);
        try {
            $raw = $this->callAi($prompt);
        } catch (\Throwable $e) {
            $this->log('extract: ai call failed — ' . $e->getMessage(), 'ERROR');
            return;
        }
        $diff = self::decodeDiff($raw);
        $this->log(sprintf('extract: ai responded in %.2fs (%d bytes), decoded %d action(s)', microtime(true) - $tStart, strlen($raw), count($diff)));
        $counts = ['add' => 0, 'update' => 0, 'delete' => 0, 'skip' => 0];
        foreach ($diff as $d) {
            $a = (string) ($d['action'] ?? 'skip');
            $counts[$a] = ($counts[$a] ?? 0) + 1;
        }
        $this->log(sprintf('extract: applying diff — add=%d update=%d delete=%d skip=%d', $counts['add'], $counts['update'], $counts['delete'], $counts['skip']));
        $this->applyDiff($diff);
    }

    private function compact(): void
    {
        if (!$this->aiAvailable()) {
            $this->log('compact: ai not configured — skipping', 'WARN');
            return;
        }
        $blocks = [];
        foreach (glob($this->output . '/*.md') ?: [] as $file) {
            $raw = (string) file_get_contents($file);
            [$fm, $body] = self::splitFrontmatter($raw);
            $blocks[] =
                '## ' . basename($file, '.md') .
                ' (type: ' . ($fm['metadata']['type'] ?? 'reference') . ')' .
                "\nDescription: " . ($fm['description'] ?? '') .
                "\n\n" . trim($body);
        }
        if ($blocks === []) {
            $this->log('compact: no memory files to evaluate');
            return;
        }
        $this->log(sprintf('compact: evaluating %d memory entries', count($blocks)));
        $prompt =
            "You curate a long-term memory store for an LLM assistant. The current entries are listed below. Identify duplicates, contradictions, and obsolete facts. Merge overlapping entries into a single canonical entry. Drop anything that is no longer relevant.\n\n" .
            implode("\n\n---\n\n", $blocks) .
            "\n\nReturn a JSON array of actions using the same schema as the extract step (action, slug, content, description, type). Respond with raw JSON only, no markdown fences.";
        $tStart = microtime(true);
        try {
            $raw = $this->callAi($prompt);
        } catch (\Throwable $e) {
            $this->log('compact: ai call failed — ' . $e->getMessage(), 'ERROR');
            return;
        }
        $diff = self::decodeDiff($raw);
        $this->log(sprintf('compact: ai responded in %.2fs, decoded %d action(s) — backing up before apply', microtime(true) - $tStart, count($diff)));
        $this->backup();
        $this->applyDiff($diff);
    }

    private function shouldCompact(): bool
    {
        $sentinel = $this->dataDir() . '/last_compact';
        if (!is_file($sentinel)) {
            return true;
        }
        $last = (int) trim((string) @file_get_contents($sentinel));
        return time() - $last > 24 * 3600;
    }

    private function markCompacted(): void
    {
        $dataDir = $this->dataDir();
        if (!is_dir($dataDir) && !@mkdir($dataDir, 0775, true)) {
            return;
        }
        @file_put_contents($dataDir . '/last_compact', (string) time());
    }

    private function backup(): void
    {
        $dir = $this->dataDir() . '/backup/' . date('Ymd-His');
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
                $this->log(sprintf('diff: %s %s', $a, $slug));
            } catch (RuntimeException $e) {
                $this->log(sprintf('diff: %s %s failed — %s', $a, $slug, $e->getMessage()), 'WARN');
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
        // sync to the internal db immediately so the next enhance() call sees
        // the new entry without waiting for the next worker tick.
        $this->writeEntry(
            $slug,
            'memory',
            (string) ($frontmatter['description'] ?? ''),
            trim($content),
            (int) filemtime($path)
        );
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
        $this->removeEntry($slug);
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
            'docx' => self::docxToText($path),
            'xlsx' => self::xlsxToText($path),
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

    /**
     * Pull plain text out of a .docx — a zip archive holding word/document.xml.
     * Strip tags + decode entities + collapse whitespace. No external tooling
     * needed; relies on ext-zip which ships with most PHP builds.
     */
    private static function docxToText(string $path): ?string
    {
        if (!extension_loaded('zip')) {
            return null;
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false) {
            return null;
        }
        // <w:p> paragraphs are flattened to a single space so words from
        // adjacent runs don't collide.
        $xml = preg_replace('/<\/w:p>/', "\n", $xml) ?? $xml;
        $text = strip_tags($xml);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{2,}/', "\n\n", $text) ?? $text;
        return trim($text) ?: null;
    }

    /**
     * Pull plain text out of a .xlsx — a zip archive with xl/sharedStrings.xml
     * and xl/worksheets/sheet*.xml. Numeric / date cells are emitted verbatim,
     * shared strings are resolved against the lookup table.
     */
    private static function xlsxToText(string $path): ?string
    {
        if (!extension_loaded('zip')) {
            return null;
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        try {
            // shared string table
            $shared = [];
            $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
            if ($sharedXml !== false) {
                $dom = @simplexml_load_string($sharedXml);
                if ($dom !== false) {
                    foreach ($dom->si as $si) {
                        $buf = '';
                        foreach ($si->xpath('.//*[local-name()="t"]') ?: [] as $t) {
                            $buf .= (string) $t;
                        }
                        $shared[] = $buf;
                    }
                }
            }
            // each sheet — values via shared strings, numeric values inline
            $parts = [];
            $i = 1;
            while (true) {
                $sheetXml = $zip->getFromName("xl/worksheets/sheet{$i}.xml");
                if ($sheetXml === false) {
                    break;
                }
                $dom = @simplexml_load_string($sheetXml);
                if ($dom !== false) {
                    foreach ($dom->sheetData->row as $row) {
                        foreach ($row->c as $cell) {
                            $type = (string) $cell['t'];
                            $val = (string) $cell->v;
                            if ($type === 's') {
                                $parts[] = $shared[(int) $val] ?? '';
                            } elseif ($type === 'inlineStr') {
                                $parts[] = (string) ($cell->is->t ?? '');
                            } elseif ($val !== '') {
                                $parts[] = $val;
                            }
                        }
                    }
                }
                $i++;
            }
        } finally {
            $zip->close();
        }
        $text = implode(' ', array_filter($parts, fn(string $s): bool => $s !== ''));
        return $text !== '' ? $text : null;
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
        if (!self::isValidSlug($slug)) {
            throw new RuntimeException('memhelper: invalid slug "' . $slug . '" — use kebab/snake_case ASCII');
        }
    }

    private static function isValidSlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]{0,127}$/', $slug) === 1;
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
