[![build status](https://github.com/vielhuber/memhelper/actions/workflows/ci.yml/badge.svg)](https://github.com/vielhuber/memhelper/actions)
[![GitHub Tag](https://img.shields.io/github/v/tag/vielhuber/memhelper)](https://github.com/vielhuber/memhelper/tags)
[![Code Style](https://img.shields.io/badge/code_style-psr--12-ff69b4.svg)](https://www.php-fig.org/psr/psr-12/)
[![License](https://img.shields.io/github/license/vielhuber/memhelper)](https://github.com/vielhuber/memhelper/blob/main/LICENSE.md)
[![Last Commit](https://img.shields.io/github/last-commit/vielhuber/memhelper)](https://github.com/vielhuber/memhelper/commits)
[![PHP Version Support](https://img.shields.io/packagist/php-v/vielhuber/memhelper)](https://packagist.org/packages/vielhuber/memhelper)
[![Packagist Downloads](https://img.shields.io/packagist/dt/vielhuber/memhelper)](https://packagist.org/packages/vielhuber/memhelper)

# 🧠 memhelper 🧠

Markdown-first memory layer for LLM agents. One static call enhances a prompt with the relevant facts from your memory store. A separate supervisor worker handles all writes — extracting new facts from queued conversations, refreshing the search index across every configured database, and periodically compacting duplicates and obsolete entries. Memory entries are plain `.md` files with a tiny YAML frontmatter — readable, editable, git-versionable. The search index is replicated across as many SQLite, MySQL and PostgreSQL backends as you list in `config.yaml`.

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

input_files:
    - /path/to/external/docs
    - /path/to/external/notes

input_dbs:
    - driver: sqlite
      path: /path/to/database.db

    - driver: mysql
      host: 127.0.0.1
      port: 3306
      user: root
      password:
      database: memhelper

    - driver: postgres
      host: 127.0.0.1
      port: 5432
      user: root
      password:
      database: database
```

## usage

```php
use vielhuber\memhelper\memhelper;

$memory = new memhelper('/path/to/config.yaml');
$prompt = $memory->enhance($conversation) . $prompt;
```

`$conversation` accepts any common shape — OpenAI, Anthropic, Google Gemini, a plain string, a list of strings, or any custom array where each entry carries a `content`, `text` or `message` key. Anything that yields no extractable text is silently dropped.

## worker

```ini
[program:memhelper-worker]
command=php /app/vendor/vielhuber/memhelper/bin/memhelper-worker
autostart=true
autorestart=true
```

## tests

```bash
./vendor/bin/phpunit
```
