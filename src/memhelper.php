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
use vielhuber\simplemcp\Attributes\McpTool;
use vielhuber\simplemcp\Attributes\Schema;

/**
 * memhelper — markdown-first memory layer for LLM agents.
 *
 *   $mem = new memhelper('/path/to/config.yaml');        // yaml path is required
 *   $facts = $mem->grab('how is X named?');         // host API
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
    private int $maxSourceBytes;
    private int $existingMemoryLimit;

    /**
     * The host may pass the absolute path of its memhelper yaml explicitly,
     * or leave both args empty to fall back to the environment variables
     * `MEMHELPER_CONFIG` and `MEMHELPER_LOG`. The env-fallback path is what
     * makes `new memhelper()` callable from simplemcp's reflection-driven
     * tool discovery: no host code in the MCP server, env vars come in
     * through the mcp.json `env` block.
     */
    public function __construct(string $configPath = '', ?string $logPath = null)
    {
        if ($configPath === '') {
            $envCfg = getenv('MEMHELPER_CONFIG');
            $configPath = $envCfg === false ? '' : $envCfg;
        }
        if ($logPath === null || $logPath === '') {
            $envLog = getenv('MEMHELPER_LOG');
            $logPath = $envLog === false ? null : ($envLog === '' ? null : $envLog);
        }
        $this->logPath = $logPath;
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
        $this->maxSourceBytes = max(1000, (int) ($cfg['max_source_bytes'] ?? 20000));
        $this->existingMemoryLimit = max(0, (int) ($cfg['existing_memory_limit'] ?? 200));

        // internal database — always sqlite, always lives under .data/ of the
        // output directory. no user-facing config knob, no path-collision risk.
        $dbPath = $this->output . '/.data/memhelper.db';
        $dbDir = dirname($dbPath);
        if (!is_dir($dbDir) && !@mkdir($dbDir, 0775, true) && !is_dir($dbDir)) {
            throw new RuntimeException('memhelper: internal db dir could not be created: ' . $dbDir);
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
     * Progress reporter. Small batches (≤ 25 items) emit every step so the
     * operator sees full activity; large batches throttle to one line per
     * ~2 s or per 10 processed items, plus a final line on completion.
     */
    private function progress(string $tag, int $done, int $total, string $note): void
    {
        if ($total <= 0) {
            return;
        }
        if ($total <= 25) {
            $pct = (int) round($done * 100 / $total);
            $this->log(sprintf('%s: [%d/%d] (%d%%) %s', $tag, $done, $total, $pct, $note));
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
     * Look up curated facts for a natural-language query. Tokenises the
     * query, runs the fts5 search, expands hits by one hop along the
     * `[[…]]` link graph and returns the matching memory entries as raw
     * structured data (slug, tags, description, body, sources, score, via).
     *
     * This is both the host-facing PHP method AND the MCP-exposed tool
     * (simplemcp's discovery picks the `#[McpTool]` attribute up directly,
     * so there is no separate wrapper class to keep in sync).
     *
     * @return list<array{slug:string,tags:list<string>,description:string,body:string,sources:list<string>,score:float|null,via:string|null}>
     */
    #[McpTool(
        name: 'grab',
        description: 'Look up curated facts the assistant has stored about the user, their projects, contacts, contracts, preferences and recurring rules. Returns an array of `{slug, tags, description, body, sources, score, via}` entries. `tags` are free-form keywords attached by the curator. `sources` is the provenance list — which raw input rows or file paths produced this fact (`dbrow:…`, `attached:…`, `compact:…`). Cross-references between facts (`[[slug]]`) are followed one hop and surface as `via:"link"` neighbours. Use this whenever an answer depends on prior personal knowledge — names, family, household details, accounts, dates, preferences, established routines.'
    )]
    public function grab(
        #[Schema(
            description: 'Natural-language query, e.g. "Wie heißt der Hund von David?" or "monthly hosting cost". Matches the curated entries via full-text search over their bodies.'
        )]
        string $query,
        #[Schema(
            description: 'Maximum number of primary matches to return (1-20). Linked neighbours are returned in addition and capped at the same number.',
            minimum: 1,
            maximum: 20,
            default: 10
        )]
        int $limit = 10
    ): array {
        $query = trim($query);
        $limit = max(1, min(20, $limit));
        if ($query === '') {
            $this->log('grab: empty query — returning []');
            return [];
        }
        $hits = $this->search($query, $limit);
        $primarySlugs = [];
        foreach ($hits as $h) {
            $slug = (string) ($h['slug'] ?? '');
            if ($slug !== '') {
                $primarySlugs[] = $slug;
            }
        }
        $neighbours = $this->neighboursOf($primarySlugs, max(1, $limit));

        $results = [];
        $seen = [];
        foreach ($hits as $h) {
            $slug = (string) ($h['slug'] ?? '');
            if ($slug === '' || isset($seen[$slug])) {
                continue;
            }
            $entry = $this->getMemory($slug);
            if ($entry === null) {
                continue;
            }
            $seen[$slug] = true;
            $results[] = [
                'slug' => $slug,
                'tags' => self::readTags($entry['frontmatter']),
                'description' => (string) ($entry['frontmatter']['description'] ?? ''),
                'body' => trim((string) $entry['body']),
                'sources' => self::readSources($entry['frontmatter']),
                'score' => isset($h['score']) ? (float) $h['score'] : null,
                'via' => null
            ];
        }
        foreach ($neighbours as $slug) {
            if (isset($seen[$slug])) {
                continue;
            }
            $entry = $this->getMemory($slug);
            if ($entry === null) {
                continue;
            }
            $seen[$slug] = true;
            $results[] = [
                'slug' => $slug,
                'tags' => self::readTags($entry['frontmatter']),
                'description' => (string) ($entry['frontmatter']['description'] ?? ''),
                'body' => trim((string) $entry['body']),
                'sources' => self::readSources($entry['frontmatter']),
                'score' => null,
                'via' => 'link'
            ];
        }
        $this->log(sprintf(
            'grab: query=%dB hits=%d neighbours=%d returned=%d',
            strlen($query), count($hits), count($neighbours), count($results)
        ));
        return $results;
    }

    // ====================================================================
    //  Public — supervisor worker entry point (does ALL writes)
    // ====================================================================

    /**
     * @internal Infrastructure entry point used by bin/memhelper-worker only.
     * One iteration of background maintenance: refresh sources, distil them
     * via ai, sync the curated mds into the fts5 index, and run the
     * once-per-day compaction pass when due. Read access for hosts goes
     * through grab().
     */
    public function work(): void
    {
        // db-based lock with PID + TTL — crash-safe across process death via
        // the posix-kill liveness check and a 1 h staleness fallback. avoids
        // a stray .data/tick.lock file on disk.
        if (!$this->acquireTickLock()) {
            $this->log('another tick is still running, skipping');
            return;
        }
        $tStart = microtime(true);
        $this->log('tick start');
        try {
            // phase 1: input_files + input_dbs → state.body, no fts5
            $this->refreshSources();
            // phase 2: ai distils undistilled / changed sources → md files
            $this->distillSources();
            // phase 3: sync the curated md files into the fts5 search index
            $this->refreshMemoryIndex();
            // phase 4: periodic compaction
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
            $this->releaseTickLock();
        }
    }

    private const TICK_LOCK_KEY = 'tick_lock';
    private const TICK_LOCK_TTL_SECONDS = 3600;
    private const COMPACT_KEY = 'last_compact';
    private const COMPACT_INTERVAL_SECONDS = 86400;

    private function acquireTickLock(): bool
    {
        $now = time();
        $myPid = getmypid();
        try {
            $row = $this->db->fetch_row(
                'SELECT value, updated_at FROM memhelper_meta WHERE key = ?',
                self::TICK_LOCK_KEY
            );
            if (is_array($row)) {
                $heldPid = (int) ($row['value'] ?? 0);
                $heldAt = (int) ($row['updated_at'] ?? 0);
                $fresh = ($now - $heldAt) < self::TICK_LOCK_TTL_SECONDS;
                $alive = $this->isProcessAlive($heldPid);
                if ($fresh && $alive) {
                    return false;
                }
                // stale (owner dead or older than TTL) → take it over.
                $this->log(sprintf(
                    'tick lock: takeover stale lock (pid=%d alive=%s held %ds ago)',
                    $heldPid, $alive ? 'yes' : 'no', $now - $heldAt
                ), 'WARN');
                $this->db->update(
                    'memhelper_meta',
                    ['value' => (string) $myPid, 'updated_at' => $now],
                    ['key' => self::TICK_LOCK_KEY]
                );
                return true;
            }
            $this->db->insert('memhelper_meta', [
                'key' => self::TICK_LOCK_KEY,
                'value' => (string) $myPid,
                'updated_at' => $now
            ]);
            return true;
        } catch (\Throwable $e) {
            $this->log('tick lock: failed to acquire: ' . $e->getMessage(), 'ERROR');
            return false;
        }
    }

    private function releaseTickLock(): void
    {
        try {
            $this->db->delete('memhelper_meta', [
                'key' => self::TICK_LOCK_KEY,
                'value' => (string) getmypid()
            ]);
        } catch (\Throwable) {
            // best effort — next tick will detect a stale row anyway.
        }
    }

    private function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }
        // linux fallback: /proc/<pid> exists for live processes
        return is_dir('/proc/' . $pid);
    }

    // ====================================================================
    //  Internal — conversation queue (written by public, drained by tick)
    // ====================================================================

    private function dataDir(): string
    {
        return $this->output . '/.data';
    }

    // ====================================================================
    //  Internal — 1-hop link expansion (used by grab)
    // ====================================================================

    /**
     * Return up to `$maxAdd` slugs reachable from any of `$primarySlugs` via
     * one hop in the link graph (either direction). The cap keeps the
     * returned fact set bounded when an entry is heavily cross-referenced.
     */
    private function neighboursOf(array $primarySlugs, int $maxAdd): array
    {
        if ($primarySlugs === [] || $maxAdd <= 0) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($primarySlugs), '?'));
        try {
            $rows = $this->db->fetch_all(
                "SELECT to_slug AS slug FROM memhelper_links WHERE from_slug IN ($placeholders)
                 UNION
                 SELECT from_slug AS slug FROM memhelper_links WHERE to_slug IN ($placeholders)",
                ...array_merge($primarySlugs, $primarySlugs)
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }
        $primary = array_flip($primarySlugs);
        $out = [];
        foreach ($rows as $r) {
            $s = (string) ($r['slug'] ?? '');
            if ($s === '' || isset($primary[$s])) {
                continue;
            }
            $out[$s] = true;
            if (count($out) >= $maxAdd) {
                break;
            }
        }
        return array_keys($out);
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
        // detect schema drift (older deployments missing kind/body/distilled_at
        // or the meta table) and drop — derived state, cheap to redo from disk.
        try {
            $db->fetch_var('SELECT kind FROM memories LIMIT 0');
            $db->fetch_var('SELECT body, distilled_at FROM memhelper_state LIMIT 0');
            $db->fetch_var('SELECT value, updated_at FROM memhelper_meta LIMIT 0');
        } catch (\Throwable) {
            $db->query('DROP TABLE IF EXISTS memories');
            $db->query('DROP TABLE IF EXISTS memhelper_meta');
            $db->query('DROP TABLE IF EXISTS memhelper_state');
            $db->query('DROP TABLE IF EXISTS memhelper_links');
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
        // memhelper_meta: singleton-ish bookkeeping that does not belong to a
        // single slug. used for the tick lock (key=tick_lock, value=pid,
        // updated_at=when-acquired) and the compaction sentinel (key=last_compact,
        // updated_at=when-last-run). avoids stray files under .data/.
        $db->query(
            'CREATE TABLE IF NOT EXISTS memhelper_meta (
                key TEXT PRIMARY KEY,
                value TEXT,
                updated_at INTEGER NOT NULL
            )'
        );
        // memhelper_links: explicit cross-references between memory entries,
        // materialised from `[[slug]]` patterns in their bodies. enables a
        // 1-hop expansion at search time so a hit on one entry surfaces its
        // neighbours too. one row per directed edge — duplicates collapse
        // via the composite primary key.
        $db->query(
            'CREATE TABLE IF NOT EXISTS memhelper_links (
                from_slug TEXT NOT NULL,
                to_slug TEXT NOT NULL,
                PRIMARY KEY (from_slug, to_slug)
            )'
        );
        $db->query('CREATE INDEX IF NOT EXISTS memhelper_links_to ON memhelper_links(to_slug)');
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
        $this->upsertLinks($slug, $body);
    }

    private function removeEntry(string $slug): void
    {
        $this->db->delete('memories', ['slug' => $slug]);
        $this->db->delete('memhelper_state', ['slug' => $slug]);
        // remove edges in both directions — a deleted entry stops being a
        // source and stops being a target.
        $this->db->delete('memhelper_links', ['from_slug' => $slug]);
        $this->db->delete('memhelper_links', ['to_slug' => $slug]);
    }

    /**
     * Replace the outgoing link set for `$slug` with the slugs referenced by
     * `[[other-slug]]` patterns in `$body`. Self-loops and links to invalid
     * slugs are dropped — the latter keeps a forward reference to a not-yet-
     * existing memory cheap to fix later (just add the missing md).
     */
    private function upsertLinks(string $slug, string $body): void
    {
        $this->db->delete('memhelper_links', ['from_slug' => $slug]);
        $targets = self::parseLinkTargets($body);
        foreach ($targets as $target) {
            if ($target === $slug || !self::isValidSlug($target)) {
                continue;
            }
            try {
                $this->db->insert('memhelper_links', [
                    'from_slug' => $slug,
                    'to_slug' => $target
                ]);
            } catch (\Throwable) {
                // PK collision — already linked, no-op.
            }
        }
    }

    /** Extract `[[slug]]` references from a memory body. */
    private static function parseLinkTargets(string $body): array
    {
        if (!preg_match_all('/\[\[([a-z0-9][a-z0-9_-]{0,127})\]\]/', $body, $m)) {
            return [];
        }
        return array_values(array_unique($m[1]));
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
            'kind' => 'source',
            'mtime' => $mtime,
            'hash' => self::SKIPPED_HASH,
            'indexed_at' => time(),
            // pretend we've already distilled so distillSources skips it too
            'distilled_at' => time()
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

        // one-shot link backfill: the diff path below only re-parses entries
        // that actually change, so when this method runs against a populated
        // memory store that was indexed before the links table existed (or
        // by an older binary), unchanged rows never get their `[[…]]` edges
        // extracted. detect that empty-but-populated state and walk every
        // md once to seed the table.
        $linkRows = (int) ($this->db->fetch_var('SELECT COUNT(*) FROM memhelper_links') ?? 0);
        if ($linkRows === 0 && $desired !== []) {
            $seeded = 0;
            foreach ($desired as $slug => $info) {
                $raw = (string) file_get_contents($info['path']);
                [, $body] = self::splitFrontmatter($raw);
                $this->upsertLinks($slug, $body);
                $seeded++;
            }
            $this->log("memory-index: seeded link table from {$seeded} existing memory file(s)");
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
        $this->log(sprintf(
            'sources: scan starting — %d files dir(s), %d input_db(s) configured',
            count($this->filesDirs),
            count($this->inputDbs)
        ));
        $this->refreshFileSources();
        $this->refreshDbSources();
    }

    private function refreshFileSources(): void
    {
        if ($this->filesDirs === []) {
            return;
        }
        $tStart = microtime(true);
        $desired = $this->scanFilesDirs();

        // scope to attached: slugs — without this LIKE filter we would also
        // pick up dbrow:* sources and mark them for removal, causing every
        // db-row to thrash add→remove→add on every tick.
        $rows = $this->db->fetch_all(
            "SELECT slug, mtime, hash FROM memhelper_state WHERE kind = 'source' AND slug LIKE 'attached:%'"
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

    // Auto-discovery limits + blacklist patterns. Hardcoded for now — these
    // are heuristics, not policy knobs. Move to config if real deployments
    // need different defaults.
    private const DB_TABLE_ROW_CAP = 50000;
    private const DB_TABLE_BLACKLIST_EXACT = [
        'migrations', 'migration_versions', 'phinxlog', 'phinxlog_default',
        'jobs', 'job_batches', 'failed_jobs',
        'cache', 'cache_locks',
        'sessions',
        'password_reset_tokens', 'personal_access_tokens',
    ];
    private const DB_TABLE_BLACKLIST_PREFIX = [
        'sqlite_', 'oauth_', 'telescope_', 'pulse_', 'horizon_',
    ];
    private const DB_TABLE_BLACKLIST_SUFFIX = [
        '_log', '_logs', '_audit', '_history', '_tmp', '_temp', '_cache', '_queue', '_queues',
    ];
    private const DB_TABLE_BLACKLIST_CONTAINS = [
        'migration',
    ];
    private const DB_COLUMN_BLACKLIST_EXACT = [
        'password', 'password_hash', 'remember_token', 'csrf_token',
        'session_data',
    ];
    /** Skip a column when its name matches any of these regexes (sensitive
     *  data, foreign keys, timestamps, fingerprints — all noise for memory). */
    private const DB_COLUMN_BLACKLIST_PATTERNS = [
        '/_id$/',
        '/_at$/',
        '/^timestamp_/',
        '/_token$/',
        '/_secret$/',
        '/_hash$/',
        '/_key$/',
        '/password/',
    ];

    /**
     * Walk every configured input_db, auto-discover tables, drop the noisy
     * ones, and stage each row as a kind='source' entry. Alias is derived
     * from the db filename (sqlite) or database name (mysql/postgres). No
     * per-table config — column selection is heuristic and conservative.
     */
    private function refreshDbSources(): void
    {
        if ($this->inputDbs === []) {
            return;
        }
        $usedAliases = [];
        foreach ($this->inputDbs as $idx => $dbc) {
            if (!is_array($dbc)) {
                continue;
            }
            $alias = $this->deriveAlias($dbc, $usedAliases);
            $usedAliases[$alias] = true;
            $driver = strtolower((string) ($dbc['driver'] ?? '?'));
            $target = $driver === 'sqlite'
                ? (string) ($dbc['path'] ?? '?')
                : sprintf('%s@%s', $dbc['user'] ?? '?', $dbc['host'] ?? '?');
            $this->log(sprintf('input_dbs: [%d] alias=%s driver=%s target=%s', $idx + 1, $alias, $driver, $target));
            $source = $this->openInputDb($dbc, $alias);
            if ($source === null) {
                continue;
            }
            try {
                $tables = $this->discoverTables($source, $dbc);
            } catch (\Throwable $e) {
                $this->log("input_dbs[$alias]: discovery failed — " . $e->getMessage(), 'WARN');
                continue;
            }
            if ($tables === []) {
                $this->log("input_dbs[$alias]: no tables discovered");
                continue;
            }
            $this->log(sprintf('input_dbs[%s]: discovered %d table(s)', $alias, count($tables)));
            // per-db scoping: `include_tables` is a whitelist (if set, every
            // other table is skipped). `exclude_tables` adds extra entries to
            // the built-in blacklist for this db only. lowercase compare so
            // YAML casing differences don't matter.
            $includeTables = isset($dbc['include_tables']) && is_array($dbc['include_tables'])
                ? array_map('strtolower', array_map('strval', $dbc['include_tables']))
                : null;
            $excludeTables = is_array($dbc['exclude_tables'] ?? null)
                ? array_map('strtolower', array_map('strval', $dbc['exclude_tables']))
                : [];
            $tableFilters = is_array($dbc['where'] ?? null) ? $dbc['where'] : [];
            $processedTables = [];
            foreach ($tables as $t) {
                $tn = $t['name'];
                $tnLow = strtolower($tn);
                if ($includeTables !== null && !in_array($tnLow, $includeTables, true)) {
                    $this->log("input_dbs[$alias:$tn]: skip — not in include_tables");
                    continue;
                }
                if (in_array($tnLow, $excludeTables, true)) {
                    $this->log("input_dbs[$alias:$tn]: skip — in exclude_tables");
                    continue;
                }
                if (self::isTableBlacklisted($tn)) {
                    $this->log("input_dbs[$alias:$tn]: skip — blacklisted");
                    continue;
                }
                if ($t['pkName'] === null) {
                    $this->log("input_dbs[$alias:$tn]: skip — no single-column primary key");
                    continue;
                }
                $tableFilter = isset($tableFilters[$tn]) && is_string($tableFilters[$tn])
                    ? trim($tableFilters[$tn])
                    : null;
                if ($tableFilter !== null && str_contains($tableFilter, ';')) {
                    $this->log("input_dbs[$alias:$tn]: skip — where filter must not contain semicolons", 'WARN');
                    continue;
                }
                $rowCount = $t['rowCount'];
                if ($tableFilter !== null && $tableFilter !== '') {
                    $rowCount = (int) ($source->fetch_var(
                        sprintf('SELECT COUNT(*) FROM "%s" WHERE (%s)', $tn, $tableFilter)
                    ) ?: 0);
                }
                if ($rowCount > self::DB_TABLE_ROW_CAP) {
                    $this->log(sprintf(
                        'input_dbs[%s:%s]: skip — %d rows > cap (%d)',
                        $alias, $tn, $rowCount, self::DB_TABLE_ROW_CAP
                    ));
                    continue;
                }
                $contentCols = $this->pickIndexableColumns($t['columns'], $t['pkName']);
                if ($contentCols === []) {
                    $this->log("input_dbs[$alias:$tn]: skip — no indexable text columns");
                    continue;
                }
                try {
                    $this->refreshDbTable(
                        $source,
                        $alias,
                        $tn,
                        $t['pkName'],
                        $contentCols,
                        $tableFilter
                    );
                    $processedTables[] = $tn;
                } catch (\Throwable $e) {
                    $this->log(sprintf(
                        'input_dbs[%s:%s]: failed — %s',
                        $alias, $tn, $e->getMessage()
                    ), 'WARN');
                }
            }
            $this->pruneOrphanDbSources($alias, $processedTables);
        }
    }

    /**
     * Drop state rows for any dbrow:<alias>:<table>:* whose table is no
     * longer being processed (table dropped from the source db, removed
     * from include_tables, added to exclude_tables, or now blacklisted).
     * refreshDbTable only ever touches rows of its own table, so without
     * this sweep those entries linger as orphans.
     */
    private function pruneOrphanDbSources(string $alias, array $processedTables): void
    {
        $slugs = $this->db->fetch_col(
            "SELECT slug FROM memhelper_state WHERE kind = 'source' AND slug LIKE ?",
            'dbrow:' . $alias . ':%'
        ) ?: [];
        if ($slugs === []) {
            return;
        }
        $prefixes = [];
        foreach ($processedTables as $t) {
            $prefixes[] = sprintf('dbrow:%s:%s:', $alias, $t);
        }
        $removed = 0;
        foreach ($slugs as $slug) {
            $slug = (string) $slug;
            $keep = false;
            foreach ($prefixes as $p) {
                if (str_starts_with($slug, $p)) {
                    $keep = true;
                    break;
                }
            }
            if (!$keep) {
                $this->db->delete('memhelper_state', ['slug' => $slug]);
                $removed++;
            }
        }
        if ($removed > 0) {
            $this->log(sprintf('input_dbs[%s]: pruned %d orphan dbrow source(s)', $alias, $removed));
        }
    }

    private function deriveAlias(array $dbc, array $used): string
    {
        $driver = strtolower((string) ($dbc['driver'] ?? ''));
        $base = match ($driver) {
            'sqlite' => self::aliasSlug(pathinfo((string) ($dbc['path'] ?? 'db'), PATHINFO_FILENAME)),
            'mysql', 'postgres' => self::aliasSlug((string) ($dbc['database'] ?? ($dbc['host'] ?? 'db'))),
            default => 'db',
        };
        if ($base === '') {
            $base = 'db';
        }
        $alias = $base;
        $i = 2;
        while (isset($used[$alias])) {
            $alias = $base . '-' . $i++;
        }
        return $alias;
    }

    private static function aliasSlug(string $s): string
    {
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        return trim($s, '-');
    }

    private static function isTableBlacklisted(string $name): bool
    {
        $n = strtolower($name);
        if (in_array($n, self::DB_TABLE_BLACKLIST_EXACT, true)) {
            return true;
        }
        foreach (self::DB_TABLE_BLACKLIST_PREFIX as $p) {
            if (str_starts_with($n, $p)) return true;
        }
        foreach (self::DB_TABLE_BLACKLIST_SUFFIX as $s) {
            if (str_ends_with($n, $s)) return true;
        }
        foreach (self::DB_TABLE_BLACKLIST_CONTAINS as $c) {
            if (str_contains($n, $c)) return true;
        }
        return false;
    }

    /**
     * Pick the columns that should contribute to the per-row source body.
     * Conservative: only text-affinity types, then drop ids/timestamps/
     * secrets. The pk is excluded too — it's the slug, not body content.
     */
    private function pickIndexableColumns(array $columns, string $pkName): array
    {
        $picked = [];
        foreach ($columns as $c) {
            $name = (string) $c['name'];
            if ($name === $pkName) continue;
            if (!self::isTextAffinity((string) $c['type'])) continue;
            $low = strtolower($name);
            if (in_array($low, self::DB_COLUMN_BLACKLIST_EXACT, true)) continue;
            $skip = false;
            foreach (self::DB_COLUMN_BLACKLIST_PATTERNS as $p) {
                if (preg_match($p, $low)) { $skip = true; break; }
            }
            if ($skip) continue;
            $picked[] = $name;
        }
        return $picked;
    }

    /** Text-affinity by declared type — covers sqlite, mysql, postgres
     *  consistently. JSON columns are TEXT but skipped: too noisy. */
    private static function isTextAffinity(string $declaredType): bool
    {
        $t = strtolower($declaredType);
        if ($t === '') return false;
        if (str_contains($t, 'json')) return false;
        if (str_contains($t, 'uuid')) return false;
        return str_contains($t, 'char')
            || str_contains($t, 'text')
            || str_contains($t, 'clob')
            || str_contains($t, 'enum');
    }

    /**
     * Discover all tables for one input_db. Returns array of
     * {name, pkName|null, columns:[{name,type},...], rowCount}.
     */
    private function discoverTables(dbhelper $source, array $dbc): array
    {
        $driver = strtolower((string) ($dbc['driver'] ?? ''));
        return match ($driver) {
            'sqlite' => $this->discoverSqliteTables($source),
            'mysql' => $this->discoverMysqlTables($source, (string) ($dbc['database'] ?? '')),
            'postgres' => $this->discoverPostgresTables($source),
            default => [],
        };
    }

    private function discoverSqliteTables(dbhelper $source): array
    {
        $rows = $source->fetch_all(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        ) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $name = (string) $r['name'];
            $info = $source->fetch_all('PRAGMA table_info("' . str_replace('"', '""', $name) . '")') ?: [];
            $pk = null;
            $compositePk = false;
            $cols = [];
            foreach ($info as $c) {
                $cols[] = ['name' => (string) $c['name'], 'type' => (string) $c['type']];
                if ((int) ($c['pk'] ?? 0) > 0) {
                    if ($pk !== null) {
                        $compositePk = true;
                    } else {
                        $pk = (string) $c['name'];
                    }
                }
            }
            $count = (int) ($source->fetch_var('SELECT COUNT(*) FROM "' . str_replace('"', '""', $name) . '"') ?? 0);
            $out[] = [
                'name' => $name,
                'pkName' => $compositePk ? null : $pk,
                'columns' => $cols,
                'rowCount' => $count,
            ];
        }
        return $out;
    }

    private function discoverMysqlTables(dbhelper $source, string $database): array
    {
        $rows = $source->fetch_all(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = ? AND table_type = 'BASE TABLE'
             ORDER BY table_name",
            $database
        ) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $name = (string) ($r['table_name'] ?? $r['TABLE_NAME'] ?? '');
            if ($name === '') continue;
            $colRows = $source->fetch_all(
                "SELECT column_name, data_type, column_key
                 FROM information_schema.columns
                 WHERE table_schema = ? AND table_name = ?
                 ORDER BY ordinal_position",
                $database, $name
            ) ?: [];
            $pk = null;
            $compositePk = false;
            $cols = [];
            foreach ($colRows as $c) {
                $cn = (string) ($c['column_name'] ?? $c['COLUMN_NAME'] ?? '');
                $ct = (string) ($c['data_type'] ?? $c['DATA_TYPE'] ?? '');
                $ck = (string) ($c['column_key'] ?? $c['COLUMN_KEY'] ?? '');
                $cols[] = ['name' => $cn, 'type' => $ct];
                if ($ck === 'PRI') {
                    if ($pk !== null) {
                        $compositePk = true;
                    } else {
                        $pk = $cn;
                    }
                }
            }
            $count = (int) ($source->fetch_var('SELECT COUNT(*) FROM `' . str_replace('`', '``', $name) . '`') ?? 0);
            $out[] = [
                'name' => $name,
                'pkName' => $compositePk ? null : $pk,
                'columns' => $cols,
                'rowCount' => $count,
            ];
        }
        return $out;
    }

    private function discoverPostgresTables(dbhelper $source): array
    {
        $rows = $source->fetch_all(
            "SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename"
        ) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $name = (string) ($r['tablename'] ?? '');
            if ($name === '') continue;
            $colRows = $source->fetch_all(
                "SELECT column_name, data_type FROM information_schema.columns
                 WHERE table_schema = 'public' AND table_name = ?
                 ORDER BY ordinal_position",
                $name
            ) ?: [];
            $cols = [];
            foreach ($colRows as $c) {
                $cols[] = [
                    'name' => (string) ($c['column_name'] ?? ''),
                    'type' => (string) ($c['data_type'] ?? ''),
                ];
            }
            $pkRows = $source->fetch_all(
                "SELECT a.attname AS pk
                 FROM pg_index i JOIN pg_attribute a
                   ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
                 WHERE i.indrelid = ?::regclass AND i.indisprimary",
                '"' . str_replace('"', '""', $name) . '"'
            ) ?: [];
            $pk = null;
            $compositePk = count($pkRows) > 1;
            if (count($pkRows) === 1) {
                $pk = (string) $pkRows[0]['pk'];
            }
            $count = (int) ($source->fetch_var('SELECT COUNT(*) FROM "' . str_replace('"', '""', $name) . '"') ?? 0);
            $out[] = [
                'name' => $name,
                'pkName' => $compositePk ? null : $pk,
                'columns' => $cols,
                'rowCount' => $count,
            ];
        }
        return $out;
    }

    private function openInputDb(array $dbc, string $alias): ?dbhelper
    {
        $driver = strtolower((string) ($dbc['driver'] ?? ''));
        try {
            $h = new dbhelper();
            if ($driver === 'sqlite') {
                $path = (string) ($dbc['path'] ?? '');
                if ($path === '' || !is_file($path)) {
                    $this->log("input_dbs[$alias]: sqlite path not found: $path", 'WARN');
                    return null;
                }
                $h->connect('pdo', 'sqlite', $path);
                return $h;
            }
            if ($driver === 'mysql' || $driver === 'postgres') {
                $h->connect(
                    'pdo',
                    $driver,
                    (string) ($dbc['host'] ?? '127.0.0.1'),
                    (string) ($dbc['user'] ?? ''),
                    (string) ($dbc['password'] ?? ''),
                    (string) ($dbc['database'] ?? ''),
                    (int) ($dbc['port'] ?? ($driver === 'mysql' ? 3306 : 5432))
                );
                return $h;
            }
            $this->log("input_dbs[$alias]: unsupported driver '$driver'", 'WARN');
            return null;
        } catch (\Throwable $e) {
            $this->log("input_dbs[$alias]: connect failed — " . $e->getMessage(), 'WARN');
            return null;
        }
    }

    /**
     * Index one table from an external db. Builds a per-row body from the
     * pre-selected text columns, diffs against memhelper_state by sha1, and
     * writes add/update/remove as needed. The source db is read-only — we
     * only SELECT.
     *
     * @param list<string> $contentCols
     */
    private function refreshDbTable(
        dbhelper $source,
        string $alias,
        string $table,
        string $pkCol,
        array $contentCols,
        ?string $where = null
    ): void {
        $tStart = microtime(true);

        // load current state for this alias+table
        $prefix = sprintf('dbrow:%s:%s:', $alias, $table);
        $stateRows = $this->db->fetch_all(
            "SELECT slug, hash FROM memhelper_state WHERE kind = 'source' AND slug LIKE ?",
            $prefix . '%'
        ) ?: [];
        $current = [];
        foreach ($stateRows as $r) {
            $current[(string) $r['slug']] = (string) $r['hash'];
        }

        // page through the source table; never load everything at once
        $cols = array_unique(array_merge([$pkCol], $contentCols));
        $colList = implode(', ', array_map(static fn(string $c): string => '"' . $c . '"', $cols));
        $batchSize = 500;
        $offset = 0;
        $seen = [];
        $toAdd = [];
        $toUpdate = [];
        $unchanged = 0;

        while (true) {
            $whereSql = $where !== null && $where !== '' ? ' WHERE (' . $where . ')' : '';
            $rows = $source->fetch_all(
                sprintf('SELECT %s FROM "%s"%s ORDER BY "%s" LIMIT %d OFFSET %d',
                    $colList, $table, $whereSql, $pkCol, $batchSize, $offset)
            ) ?: [];
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $pkVal = $row[$pkCol] ?? null;
                if ($pkVal === null || $pkVal === '') {
                    continue;
                }
                $slug = $prefix . (string) $pkVal;
                $parts = [];
                foreach ($contentCols as $c) {
                    $v = $row[$c] ?? null;
                    if ($v === null || $v === '') {
                        continue;
                    }
                    $parts[] = $c . ': ' . (string) $v;
                }
                if ($parts === []) {
                    continue;
                }
                $body = implode("\n", $parts);
                $hash = sha1($body);
                $seen[$slug] = ['body' => $body, 'hash' => $hash];
                if (!isset($current[$slug])) {
                    $toAdd[$slug] = ['body' => $body, 'hash' => $hash];
                } elseif ($current[$slug] !== $hash) {
                    $toUpdate[$slug] = ['body' => $body, 'hash' => $hash];
                } else {
                    $unchanged++;
                }
            }
            $offset += $batchSize;
        }

        $toRemove = [];
        foreach (array_keys($current) as $slug) {
            if (!isset($seen[$slug])) {
                $toRemove[] = $slug;
            }
        }

        $total = count($toAdd) + count($toUpdate) + count($toRemove);
        if ($total === 0) {
            $this->log(sprintf(
                'input_dbs[%s:%s]: nothing changed — unchanged=%d (%.2fs)',
                $alias, $table, $unchanged, microtime(true) - $tStart
            ));
            return;
        }
        $this->log(sprintf(
            'input_dbs[%s:%s]: plan — add=%d update=%d remove=%d unchanged=%d',
            $alias, $table, count($toAdd), count($toUpdate), count($toRemove), $unchanged
        ));

        $tag = "input_dbs[$alias:$table]";
        $done = 0;
        foreach ($toRemove as $slug) {
            $this->db->delete('memhelper_state', ['slug' => $slug]);
            $done++;
            $this->progress($tag, $done, $total, "removed $slug");
        }
        foreach ($toAdd as $slug => $r) {
            $this->db->insert('memhelper_state', [
                'slug' => $slug,
                'kind' => 'source',
                'mtime' => time(),
                'hash' => $r['hash'],
                'body' => $r['body'],
                'indexed_at' => time()
                // distilled_at intentionally null — distillSources() will pick it up
            ]);
            $done++;
            $this->progress($tag, $done, $total, "added $slug");
        }
        foreach ($toUpdate as $slug => $r) {
            // delete+insert so distilled_at resets to null and the row is
            // re-distilled on the next pass; an UPDATE would leave the old
            // distilled_at in place.
            $this->db->delete('memhelper_state', ['slug' => $slug]);
            $this->db->insert('memhelper_state', [
                'slug' => $slug,
                'kind' => 'source',
                'mtime' => time(),
                'hash' => $r['hash'],
                'body' => $r['body'],
                'indexed_at' => time()
            ]);
            $done++;
            $this->progress($tag, $done, $total, "updated $slug");
        }
        $this->log(sprintf(
            'input_dbs[%s:%s]: complete in %.2fs',
            $alias, $table, microtime(true) - $tStart
        ));
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
            if (strlen($body) > $this->maxSourceBytes) {
                $body = mb_strcut($body, 0, $this->maxSourceBytes) . "\n\n[truncated]";
            }
            $idx = $i + 1;
            $label = (string) (self::slugToPath($slug) ?? $slug);
            // body preview so we can confirm the right content reaches the ai
            $bodyPreview = str_replace(
                ["\r", "\n"],
                ['', ' ⏎ '],
                mb_substr($body, 0, 200)
            );
            $this->log(sprintf(
                'distill: [%d/%d] %s — sending %d bytes to ai (preview: %s%s)',
                $idx, $total, basename($label), strlen($body), $bodyPreview,
                mb_strlen($body) > 200 ? '…' : ''
            ));
            try {
                [$diff, $raw] = $this->distillOne($label, $body);
            } catch (\Throwable $e) {
                $this->log(sprintf('distill: [%d/%d] %s — ai failed: %s', $idx, $total, basename($label), $e->getMessage()), 'WARN');
                $error = strtolower($e->getMessage());
                if (str_contains($error, 'context window') || str_contains($error, 'prompt is too long')) {
                    $this->db->update(
                        'memhelper_state',
                        ['distilled_at' => time()],
                        ['slug' => $slug]
                    );
                }
                continue;
            }
            $counts = ['add' => 0, 'update' => 0, 'delete' => 0, 'skip' => 0];
            foreach ($diff as $d) {
                $a = (string) ($d['action'] ?? 'skip');
                $counts[$a] = ($counts[$a] ?? 0) + 1;
            }
            $rawPreview = str_replace(
                ["\r", "\n"],
                ['', ' ⏎ '],
                mb_substr($raw, 0, 300)
            );
            $this->log(sprintf(
                'distill: [%d/%d] %s — ai returned %d bytes, decoded %d action(s) — add=%d update=%d delete=%d skip=%d',
                $idx, $total, basename($label),
                strlen($raw), count($diff),
                $counts['add'], $counts['update'], $counts['delete'], $counts['skip']
            ));
            // always log the raw response (truncated) so the operator can see
            // exactly what the ai said — invaluable when 0 actions get
            // decoded or the provider replies in an unexpected shape.
            $this->log(
                'distill: raw response: ' . $rawPreview . (mb_strlen($raw) > 300 ? '…' : ''),
                trim($raw) === '[]' || $counts['add'] + $counts['update'] + $counts['delete'] > 0
                    ? 'INFO'
                    : 'WARN'
            );
            $this->applyDiff($diff, $slug);
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
     * Single-source distillation — sends body to the ai and returns a tuple
     * of the decoded diff array AND the raw ai response (for diagnostic
     * logging by the caller). Encapsulated so distillSources stays readable.
     *
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    private function distillOne(string $label, string $body): array
    {
        $existing = array_slice($this->listMemories(), 0, $this->existingMemoryLimit);
        $indexLines = array_map(
            function (array $e): string {
                $tagSuffix = empty($e['tags']) ? '' : ' [' . implode(', ', $e['tags']) . ']';
                return '- ' . $e['slug'] . $tagSuffix . ': ' . ($e['description'] ?? '');
            },
            $existing
        );
        $prompt =
            "TASK: Extract durable facts from the source document below into a memory store.\n\n" .
            "Source: " . $label . "\n" .
            "---BEGIN SOURCE---\n" .
            $body . "\n" .
            "---END SOURCE---\n\n" .
            "Existing memory slugs:\n" .
            ($indexLines === [] ? '(none yet)' : implode("\n", $indexLines)) .
            "\n\n" .
            "OUTPUT: Reply with a JSON array — nothing before or after, no markdown fences. Each item has:\n" .
            "  - action: \"add\" or \"update\"\n" .
            "  - slug: kebab-case identifier — ALWAYS lower-case **English** keywords regardless of source language. Slugs are stable identifiers in a single namespace; mixing languages (`pets-of-household` next to `meinung-friseur-donauwelle`) produces near-duplicates that never cluster. Use English even when content/description stay in the source language.\n" .
            "  - content: the memory body, written in third person\n" .
            "  - description: a one-line summary\n" .
            "  - tags: a list of 1-5 lower-case keyword strings (free-form). Pick concrete keywords that describe the fact (entity types, domains, topics) — e.g. `[\"contact\",\"family\"]`, `[\"contract\",\"hosting\",\"monthly\"]`, `[\"rule\",\"email\"]`. Reuse tags across related entries so they cluster — when the index above already shows tags in `[brackets]` after a slug, prefer those over inventing synonyms.\n\n" .
            "RULES:\n" .
            "  1. CROSS-LINK AGGRESSIVELY. Whenever an entry mentions an entity (person, project, account, household, contract, recurring concept), inline a `[[that-slug]]` reference to it. This applies BOTH to slugs already in the list above AND to slugs you are creating in THIS SAME response — list newly-added slugs first, then reference them from later entries. Cross-references are how related memories surface together at retrieval time. An entry without any `[[…]]` is almost always a missed opportunity unless the fact is genuinely standalone.\n" .
            "  2. When several entries share a recurring umbrella concept (e.g. \"household budget\", \"family\", \"vehicle fleet\", a specific contract suite), add ONE hub entry for the umbrella and link every related entry from it as `[[hub-slug]]` references inside the hub's content.\n" .
            "  3. Apply a STRICT LONG-TERM VALUE GATE. Save only facts that are likely to remain useful across unrelated future conversations: identity, relationships, stable preferences, recurring obligations, durable project decisions, explicitly established rules and persistent configurations. Be conservative; if uncertain, return [].\n" .
            "  4. One fact = one entry. Split combined statements into separate entries.\n" .
            "  5. If the source mentions a fact that overlaps an existing slug → use action:\"update\" with the merged content.\n" .
            "  6. Return [] for one-off research results, individual vacancies or search hits, generated document contents, implementation progress, test/build results, logs, transient errors, temporary paths, current-run status, timestamps that only describe a run, and third-party facts unrelated to the user's durable context. A stable search preference may be memory; a specific result found during that search is not. A durable architecture decision may be memory; a file changed or test passed during its implementation is not.\n" .
            "  7. Write `content` and `description` in the LANGUAGE OF THE SOURCE. If the source mixes languages, use the dominant one. This keeps memory searchable in the user's own words. Tags stay lower-case English keywords regardless of source language.\n\n" .
            "WORKED EXAMPLE A — linking to a PRE-EXISTING slug (do NOT copy these into your output — they are illustrations only):\n" .
            "  Source content: \"Meine Katze heißt Mochi und ich nutze vim als Editor. Mochi mag meine Schwester Anna.\"\n" .
            "  Existing slugs include: contact-anna [contact, family]\n" .
            "  Output: [\n" .
            "    {\"action\":\"add\",\"slug\":\"pet-mochi\",\"content\":\"Die Katze des Users heißt Mochi. Mochi mag [[contact-anna]].\",\"description\":\"die Katze des Users\",\"tags\":[\"pet\",\"cat\"]},\n" .
            "    {\"action\":\"add\",\"slug\":\"editor-preference\",\"content\":\"Der User bevorzugt vim als Editor.\",\"description\":\"Editor-Präferenz\",\"tags\":[\"preference\",\"tool\",\"editor\"]}\n" .
            "  ]\n\n" .
            "WORKED EXAMPLE B — linking to slugs ADDED IN THE SAME BATCH + a HUB entry:\n" .
            "  Source content: \"Lorenz und Rosalie sind meine Kinder. Lorenz spielt Fußball, Rosalie geht zur Ballettstunde.\"\n" .
            "  Existing slugs include: (none relevant)\n" .
            "  Output: [\n" .
            "    {\"action\":\"add\",\"slug\":\"contact-lorenz\",\"content\":\"Lorenz ist Sohn des Users. Lorenz spielt Fußball.\",\"description\":\"Sohn des Users\",\"tags\":[\"contact\",\"family\",\"child\"]},\n" .
            "    {\"action\":\"add\",\"slug\":\"contact-rosalie\",\"content\":\"Rosalie ist Tochter des Users. Rosalie geht zur Ballettstunde.\",\"description\":\"Tochter des Users\",\"tags\":[\"contact\",\"family\",\"child\"]},\n" .
            "    {\"action\":\"add\",\"slug\":\"children-of-user\",\"content\":\"Die Kinder des Users sind [[contact-lorenz]] und [[contact-rosalie]].\",\"description\":\"die Kinder des Users\",\"tags\":[\"family\",\"hub\"]}\n" .
            "  ]\n\n" .
            "WORKED EXAMPLE C — recurring umbrella across many entries (the haushaltsbuch pattern):\n" .
            "  Source content: \"Im Haushaltsbuch: All-Inkl 177€/Jahr, Vodafone GigaZuhause 64,99€/Monat, HelloFresh 165,96€/Monat.\"\n" .
            "  Output: [\n" .
            "    {\"action\":\"add\",\"slug\":\"hosting-all-inkl\",\"content\":\"Der User nutzt All-Inkl. Im [[household-budget]] sind 177€ jährlich angesetzt.\",\"description\":\"...\",\"tags\":[\"contract\",\"hosting\",\"yearly\"]},\n" .
            "    {\"action\":\"add\",\"slug\":\"internet-vodafone\",\"content\":\"Der User nutzt Vodafone GigaZuhause. Im [[household-budget]] sind 64,99€ monatlich angesetzt.\",\"description\":\"...\",\"tags\":[\"contract\",\"internet\",\"monthly\"]},\n" .
            "    {\"action\":\"add\",\"slug\":\"food-hellofresh\",\"content\":\"Der User nutzt HelloFresh. Im [[household-budget]] sind 165,96€ monatlich angesetzt.\",\"description\":\"...\",\"tags\":[\"contract\",\"food\",\"subscription\",\"monthly\"]},\n" .
            "    {\"action\":\"add\",\"slug\":\"household-budget\",\"content\":\"Das Haushaltsbuch des Users umfasst u.a. [[hosting-all-inkl]], [[internet-vodafone]], [[food-hellofresh]].\",\"description\":\"Haushaltsbuch-Übersicht\",\"tags\":[\"budget\",\"household\",\"hub\"]}\n" .
            "  ]\n\n" .
            "NOW process the source above and return the JSON array.";
        $raw = $this->callAi($prompt);
        return [self::decodeDiff($raw), $raw];
    }

    // ====================================================================
    //  Internal — compaction (LLM-driven)
    // ====================================================================

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
            $tags = self::readTags($fm);
            $blocks[] =
                '## ' . basename($file, '.md') .
                ($tags === [] ? '' : ' (tags: ' . implode(', ', $tags) . ')') .
                "\nDescription: " . ($fm['description'] ?? '') .
                "\n\n" . trim($body);
        }
        if ($blocks === []) {
            $this->log('compact: no memory files to evaluate');
            return;
        }
        $this->log(sprintf('compact: evaluating %d memory entries', count($blocks)));
        $prompt =
            "You curate a durable long-term memory store for an LLM assistant. The current entries are listed below. Do five things:\n" .
            "  1. PURGE TRANSIENT DATA: emit `delete` for one-off research results, individual vacancies or search hits, generated document contents, implementation progress, test/build results, logs, transient errors, temporary paths and current-run status. Preserve stable preferences, recurring rules and durable decisions even when they originated in those conversations.\n" .
            "  2. DEDUPE + MERGE: identify duplicates, contradictions and obsolete facts — collapse overlapping entries into one canonical entry and delete superseded entries.\n" .
            "  3. ADD MISSING CROSS-REFERENCES: whenever an entry mentions an entity that already has its own slug, emit an `update` action whose `content` is the original body with `[[that-slug]]` inserted at the right places. If many entries clearly share an umbrella concept but no hub entry exists yet, add one and link all members from it.\n" .
            "  4. PRESERVE every valid existing `[[slug]]` cross-reference when rewriting bodies — those links power 1-hop expansion at retrieval time.\n" .
            "  5. PRESERVE the original language of each entry — never translate.\n\n" .
            implode("\n\n---\n\n", $blocks) .
            "\n\nReturn a JSON array of actions using the same schema as the extract step (action is add, update or delete; slug, content, description, tags). Delete actions only require action and slug. `tags` is a list of lower-case keyword strings — keep, narrow or extend the existing tag set when rewriting; do not invent unrelated tags. Respond with raw JSON only, no markdown fences.";
        $tStart = microtime(true);
        try {
            $raw = $this->callAi($prompt);
        } catch (\Throwable $e) {
            $this->log('compact: ai call failed — ' . $e->getMessage(), 'ERROR');
            return;
        }
        $diff = self::decodeDiff($raw);
        $this->log(sprintf('compact: ai responded in %.2fs, decoded %d action(s)', microtime(true) - $tStart, count($diff)));
        $this->applyDiff($diff, 'compact:' . date('c'));
    }

    private function shouldCompact(): bool
    {
        $last = (int) ($this->db->fetch_var(
            'SELECT updated_at FROM memhelper_meta WHERE key = ?',
            self::COMPACT_KEY
        ) ?: 0);
        return time() - $last > self::COMPACT_INTERVAL_SECONDS;
    }

    private function markCompacted(): void
    {
        try {
            $this->db->delete('memhelper_meta', ['key' => self::COMPACT_KEY]);
            $this->db->insert('memhelper_meta', [
                'key' => self::COMPACT_KEY,
                'value' => '',
                'updated_at' => time()
            ]);
        } catch (\Throwable) {
            // best effort — at worst compaction runs again next day
        }
    }

    /**
     * @param string|null $source Provenance stamp the worker attributes to
     *   every add/update produced by this diff. Distillation passes the
     *   `memhelper_state.slug` of the source row; compaction passes a
     *   `compact:<iso-ts>` marker; null = "do not record provenance".
     */
    private function applyDiff(array $diff, ?string $source = null): void
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
                        self::normaliseTagList($action['tags'] ?? null),
                        $source
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

    /**
     * Normalise whatever the ai dropped in the `tags` field of a diff action
     * to a clean `list<string>` (or null for "untouched"). Accepts an array,
     * a comma-separated string, or null. Empty / whitespace entries dropped,
     * duplicates collapsed, everything lower-cased to ease search.
     */
    private static function normaliseTagList(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        if (is_string($raw)) {
            $raw = preg_split('/[,;]+/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $t) {
            if (!is_scalar($t)) continue;
            $t = trim(mb_strtolower((string) $t));
            if ($t === '') continue;
            $out[$t] = true;
        }
        return array_keys($out);
    }

    /**
     * Read the tag list from a frontmatter array. Falls back to a legacy
     * `metadata.type` value (the pre-tags categorisation) so entries created
     * by older worker versions still expose something useful.
     *
     * @return list<string>
     */
    private static function readTags(array $frontmatter): array
    {
        $rawTags = $frontmatter['metadata']['tags'] ?? null;
        $tags = self::normaliseTagList($rawTags) ?? [];
        if ($tags === []) {
            $legacyType = $frontmatter['metadata']['type'] ?? null;
            if (is_string($legacyType) && $legacyType !== '') {
                $tags = [mb_strtolower($legacyType)];
            }
        }
        return $tags;
    }

    /**
     * Read the provenance source list from a frontmatter array. Sources are
     * deterministic stamps the worker writes (not the AI) — for `distill`
     * the source is the `memhelper_state.slug` that fed the AI; for
     * `compact` it's `compact:<iso-timestamp>`; manual edits remain unmarked.
     *
     * @return list<string>
     */
    private static function readSources(array $frontmatter): array
    {
        $raw = $frontmatter['metadata']['sources'] ?? null;
        if ($raw === null) {
            return [];
        }
        if (is_string($raw)) {
            $raw = [$raw];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $s) {
            if (!is_scalar($s)) continue;
            $s = trim((string) $s);
            if ($s === '') continue;
            $out[$s] = true;
        }
        return array_keys($out);
    }

    // ====================================================================
    //  Internal — markdown CRUD (used by extraction/compaction)
    // ====================================================================

    /**
     * @param list<string>|null $tags    null = keep existing tags on update,
     *                                    []   = explicitly clear,
     *                                    list = replace
     * @param string|null       $source  if set, appended (unique) to the
     *                                    entry's provenance list
     */
    private function saveMemory(
        string $slug,
        string $content,
        ?string $description,
        ?array $tags,
        ?string $source = null
    ): void {
        self::assertValidSlug($slug);
        $path = $this->pathForSlug($slug);
        $existing = is_file($path) ? self::splitFrontmatter((string) file_get_contents($path))[0] : [];
        if ($tags === null) {
            $tags = self::readTags($existing);
        }
        $sources = self::readSources($existing);
        if ($source !== null && $source !== '' && !in_array($source, $sources, true)) {
            $sources[] = $source;
        }
        $metadata = [];
        if ($tags !== []) {
            $metadata['tags'] = $tags;
        }
        if ($sources !== []) {
            $metadata['sources'] = $sources;
        }
        $frontmatter = [
            'name' => $slug,
            'description' => $description ?? ($existing['description'] ?? ''),
            'metadata' => $metadata
        ];
        $serialized = self::renderFrontmatter($frontmatter) . trim($content) . "\n";
        if (file_put_contents($path, $serialized) === false) {
            throw new RuntimeException('memhelper: failed to write memory file: ' . $path);
        }
        // sync to the internal db immediately so the next grab() call
        // sees the new entry without waiting for the next worker tick.
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

    private function listMemories(): array
    {
        $out = [];
        foreach (glob($this->output . '/*.md') ?: [] as $file) {
            $head = (string) file_get_contents($file, false, null, 0, 4096);
            [$fm] = self::splitFrontmatter($head);
            $out[] = [
                'slug' => basename($file, '.md'),
                'description' => (string) ($fm['description'] ?? ''),
                'tags' => self::readTags($fm)
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

    private function callAi(string $prompt): string
    {
        $apiKey = (string) ($this->aiConfig['api_key'] ?? '');
        $ai = aihelper::create(
            provider: (string) $this->aiConfig['provider'],
            model: (string) $this->aiConfig['model'],
            api_key: $apiKey !== '' ? $apiKey : null,
            max_tries: 3
        );
        $result = $ai->ask(prompt: $prompt);
        if (!($result['success'] ?? false)) {
            throw new RuntimeException('memhelper: aihelper call failed: ' . self::stringifyAiResponse($result['response'] ?? null));
        }
        return self::stringifyAiResponse($result['response'] ?? '');
    }

    /**
     * aihelper::ask() returns ['response' => ...] where the value may be a
     * plain string OR an already-parsed JSON structure (array) — see
     * aihelper's parseJson() path. naive `(string) $arr` would yield the
     * literal "Array" so we re-serialise json shapes back to a string the
     * downstream decodeDiff() understands.
     */
    private static function stringifyAiResponse(mixed $response): string
    {
        if (is_array($response)) {
            $encoded = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $encoded !== false ? $encoded : '';
        }
        return $response === null ? '' : (string) $response;
    }

    private static function decodeDiff(string $raw): array
    {
        $raw = trim($raw);
        // strip markdown fences (```json … ``` or ``` … ```) — handle both
        // start-only and start+end fences anywhere in the response.
        if (str_contains($raw, '```')) {
            $raw = (string) preg_replace('/```(?:json)?\r?\n?/', '', $raw);
            $raw = trim($raw);
        }
        // first try: outer-most json array
        $start = strpos($raw, '[');
        $end = strrpos($raw, ']');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        // second try: outer-most json object — some providers wrap the array
        // in {"actions": [...]}, {"diff": [...]} or {"facts": [...]}.
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                foreach (['actions', 'diff', 'facts', 'entries', 'memories', 'items'] as $key) {
                    if (isset($decoded[$key]) && is_array($decoded[$key])) {
                        return $decoded[$key];
                    }
                }
                // bare object with action keys → treat as single action
                if (isset($decoded['action']) || isset($decoded['slug'])) {
                    return [$decoded];
                }
            }
        }
        return [];
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
        try {
            $parsed = Yaml::parse($yamlBlock);
            return [is_array($parsed) ? $parsed : [], $body];
        } catch (\Throwable) {
            // unparseable frontmatter — degrade gracefully, treat the whole
            // thing as body so the entry is still searchable.
            return [[], $raw];
        }
    }

    private static function renderFrontmatter(array $fm): string
    {
        // Symfony's dumper handles nested lists, multi-line strings and
        // scalar quoting correctly — replacing the hand-rolled emitter
        // is what makes tags + sources round-trip without truncation.
        $yaml = Yaml::dump($fm, 4, 2);
        return "---\n" . $yaml . "---\n\n";
    }
}
