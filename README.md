[![build status](https://github.com/vielhuber/memhelper/actions/workflows/ci.yml/badge.svg)](https://github.com/vielhuber/memhelper/actions)
[![GitHub Tag](https://img.shields.io/github/v/tag/vielhuber/memhelper)](https://github.com/vielhuber/memhelper/tags)
[![Code Style](https://img.shields.io/badge/code_style-psr--12-ff69b4.svg)](https://www.php-fig.org/psr/psr-12/)
[![License](https://img.shields.io/github/license/vielhuber/memhelper)](https://github.com/vielhuber/memhelper/blob/main/LICENSE.md)
[![Last Commit](https://img.shields.io/github/last-commit/vielhuber/memhelper)](https://github.com/vielhuber/memhelper/commits)
[![PHP Version Support](https://img.shields.io/packagist/php-v/vielhuber/memhelper)](https://packagist.org/packages/vielhuber/memhelper)
[![Packagist Downloads](https://img.shields.io/packagist/dt/vielhuber/memhelper)](https://packagist.org/packages/vielhuber/memhelper)

# 🧠 memhelper 🧠

Markdown-first memory layer for LLM agents. Exposes a `grab(query)` MCP tool that returns the curated facts most relevant to a natural-language question. A separate supervisor worker handles all writes — refreshing the search index across every configured input source, distilling new sources via an LLM, and periodically compacting duplicates and obsolete entries. Memory entries are plain `.md` files with a tiny YAML frontmatter — readable, editable, git-versionable. Cross-references between entries are written as `[[slug]]` wiki-links and followed one hop at retrieval time, so a single query surfaces related neighbours automatically.

## installation

```bash
composer require vielhuber/memhelper
```

```yaml
ai:
    provider: openai
    model: gpt-5
    api_key: sk-...

output: /path/to/memory
max_source_bytes: 20000
existing_memory_limit: 200

input_files:
    - /path/to/external/docs
    - /path/to/external/notes

input_dbs:
    - driver: sqlite
      path: /path/to/database.db
      include_tables: [chats_messages] # optional

    - driver: mysql
      host: 127.0.0.1
      port: 3306
      user: root
      password:
      database: memhelper
      exclude_tables: [analytics_events] # optional

    - driver: postgres
      host: 127.0.0.1
      port: 5432
      user: root
      password:
      database: database
```

## usage

### library

```php
use vielhuber\memhelper\memhelper;

$memory = new memhelper(
    configPath: '/path/to/config.yaml',
    logPath: '/var/log/memory.log'
);

$facts = $memory->grab(
    query: 'how is the user\'s dog named?',
    limit: 10
);
// → [['slug' => 'pet-roger', 'tags' => ['pet', 'dog'], 'description' => '...',
//     'body' => '...', 'sources' => ['dbrow:…'], 'score' => -3.41, 'via' => null], ...]
```

## worker

```ini
[program:memhelper-worker]
command=php /app/vendor/vielhuber/memhelper/bin/memhelper-worker /path/to/memory.yaml /var/log/memory.log
autostart=true
autorestart=true
```

## tests

```bash
./vendor/bin/phpunit
```

### mcp

```json
{
    "mcpServers": {
        "memory": {
            "command": "php",
            "args": ["./vendor/bin/mcp-server.php"],
            "env": {
                "MEMHELPER_CONFIG": "/path/to/memory.yaml",
                "MEMHELPER_LOG": "/var/log/memory.log"
            }
        }
    }
}
```
