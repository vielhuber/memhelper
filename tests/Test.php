<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use vielhuber\memhelper\memhelper;

final class Test extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/memhelper-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0775, true);
        $this->writeConfig();
    }

    protected function tearDown(): void
    {
        putenv('MEMHELPER_CONFIG=');
        self::removeTree($this->tmpDir);
    }

    private function writeConfig(?string $extraFilesDir = null): void
    {
        $cfgPath = $this->tmpDir . '/config.yaml';
        $yaml = "output: " . $this->tmpDir . "\n";
        if ($extraFilesDir !== null) {
            $yaml .= "input_files:\n    - " . $extraFilesDir . "\n";
        }
        $yaml .= "input_dbs:\n" .
                 "    - driver: sqlite\n" .
                 "      path: " . $this->tmpDir . "/.index.sqlite\n";
        file_put_contents($cfgPath, $yaml);
        putenv('MEMHELPER_CONFIG=' . $cfgPath);
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
        memhelper::work();
        $this->assertSame('', memhelper::enhance([
            ['role' => 'user', 'content' => 'hello']
        ]));
    }

    public function testRelevantMemoryIsRetrievedForMatchingTopic(): void
    {
        file_put_contents(
            $this->tmpDir . '/editor-preferences.md',
            "---\nname: editor-preferences\ndescription: editor of choice\nmetadata:\n  type: user\n---\n\nEditor is Helix.\n"
        );
        memhelper::work();
        $block = memhelper::enhance([
            ['role' => 'user', 'content' => 'what is my editor?']
        ]);
        $this->assertStringContainsString('=== Memory ===', $block);
        $this->assertStringContainsString('editor-preferences', $block);
        $this->assertStringContainsString('Helix', $block);
    }

    public function testIndexedFilesAreSearchedAlongsideMemories(): void
    {
        $filesDir = $this->tmpDir . '/docs';
        mkdir($filesDir);
        file_put_contents($filesDir . '/notes.txt', 'Charly is a multi-agent orchestrator built in php.');
        $this->writeConfig($filesDir);
        memhelper::work();
        $block = memhelper::enhance([
            ['role' => 'user', 'content' => 'what is the orchestrator about?']
        ]);
        $this->assertStringContainsString('=== Memory ===', $block);
        $this->assertStringContainsString('notes.txt', $block);
    }

    public function testConversationIsEnqueuedForWorker(): void
    {
        memhelper::enhance([
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
        memhelper::enhance([]);
        $this->assertSame([], glob($this->tmpDir . '/.data/queue/*.json') ?: []);
    }

    public function testStringConversationIsAccepted(): void
    {
        $this->seedEditorMemory();
        memhelper::work();
        $block = memhelper::enhance('what is my editor?');
        $this->assertStringContainsString('Helix', $block);
    }

    public function testGeminiPartsShapeIsAccepted(): void
    {
        $this->seedEditorMemory();
        memhelper::work();
        $block = memhelper::enhance([
            ['role' => 'user', 'parts' => [['text' => 'what is my editor?']]]
        ]);
        $this->assertStringContainsString('Helix', $block);
    }

    public function testAnthropicContentBlocksAreAccepted(): void
    {
        $this->seedEditorMemory();
        memhelper::work();
        $block = memhelper::enhance([
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'what is my editor?']
            ]]
        ]);
        $this->assertStringContainsString('Helix', $block);
    }

    public function testListOfStringsIsAccepted(): void
    {
        $this->seedEditorMemory();
        memhelper::work();
        $block = memhelper::enhance([
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
}
