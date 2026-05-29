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
    - /path/to/memory/attached
    - /path/to/extra/docs

input_dbs:
    - driver: sqlite
      path: /path/to/memhelper.db

    - driver: mysql
      host: 127.0.0.1
      port: 3306
      user: root
      password:
      database: memhelper

    - driver: postgres
      host: 127.0.0.1
      port: 5432
      user: postgres
      password:
      database: memhelper
```

## usage

```php
use vielhuber\memhelper\memhelper;

$prompt = memhelper::enhance($conversation) . $prompt;
```

## worker

```ini
[program:memhelper-worker]
command=php /app/vendor/vielhuber/memhelper/bin/memhelper-worker 30
autostart=true
autorestart=true
```

## tests

```bash
./vendor/bin/phpunit
```
