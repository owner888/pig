<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\Agent\AgentTool;
use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;

/** A scratch directory to run tools against, cleaned up afterwards. */
abstract class ToolTestCase extends TestCase
{
    protected string $cwd;

    #[\Override]
    protected function setUp(): void
    {
        $this->cwd = sys_get_temp_dir() . '/pig-tools-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->cwd, 0o755, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        self::remove($this->cwd);
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }

            rmdir($path);

            return;
        }

        if (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }

    /** Write a file under the scratch directory, making any directories it needs. */
    protected function file(string $name, string $contents = ''): string
    {
        $path = $this->cwd . '/' . $name;
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0o755, true);
        }

        file_put_contents($path, $contents);

        return $path;
    }

    /** @param array<string, mixed> $arguments */
    protected function run(AgentTool $tool, array $arguments): AgentToolResult
    {
        return $tool->execute('call-1', $arguments);
    }

    /** Everything the model would be shown, as one string. */
    protected function output(AgentToolResult $result): string
    {
        $text = '';

        foreach ($result->content as $block) {
            if ($block instanceof TextContent) {
                $text .= $block->text;
            }
        }

        return $text;
    }
}
