<?php
declare(strict_types=1);

foreach (
    [
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../../autoload.php',
        __DIR__ . '/../../../../autoload.php'
    ]
    as $autoload_path
) {
    if (is_file($autoload_path)) {
        require_once $autoload_path;
        break;
    }
}

use vielhuber\simplemcp\simplemcp;

// simplemcp's static-auth path requires an env file with MCP_TOKEN. for stdio
// transport (the only mode charly uses) the token is never consulted — but the
// dotenv loader still insists the file exists. drop an empty one beside the
// project when missing so re-launches stay silent.
$project_dir = getcwd();
if ($project_dir === false) {
    $project_dir = dirname(__DIR__);
}
$env_path = $project_dir . '/.env';
if (!is_file($env_path) && @file_put_contents($env_path, "MCP_TOKEN=\n") === false) {
    fwrite(STDERR, 'memhelper-mcp-server: failed to create ' . $env_path . ' (check permissions on the project directory)' . PHP_EOL);
    exit(1);
}

new simplemcp(
    name: 'memhelper-mcp-server',
    log: 'mcp-server.log',
    discovery: '.',
    auth: 'static',
    env: $env_path
);
