<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Export\SecretScan;

/** What `/share` lists before a page leaves the machine, and what it blacks out. */
final class SecretScanTest extends TestCase
{
    public function testAKnownValueIsFoundAsItIsAndAsTheExportEscapesIt(): void
    {
        $page = '<pre>token=house-token-0001</pre><p>pass &quot;a&amp;b-secret-0001&quot;</p>';

        $hits = SecretScan::find($page, ['house-token-0001', 'a&b-secret-0001', 'short']);

        $this->assertSame(
            [['kind' => 'Stored credential', 'value' => 'a&amp;b-secret-0001', 'count' => 1], ['kind' => 'Stored credential', 'value' => 'house-token-0001', 'count' => 1]],
            $hits,
        );
    }

    public function testTheProvidersKeyShapesAreFoundWithoutBeingKnown(): void
    {
        $anthropic = 'sk-ant-api03-' . str_repeat('A', 30);
        $openai = 'sk-proj-' . str_repeat('b', 30);
        $github = 'ghp_' . str_repeat('C', 36);
        $aws = 'AKIA' . str_repeat('D', 16);
        $google = 'AIza' . str_repeat('e', 35);
        $pem = "-----BEGIN OPENSSH PRIVATE KEY-----\nabc\n-----END OPENSSH PRIVATE KEY-----";
        $page = "{$anthropic} {$openai} {$openai} {$github} {$aws} {$google}\n{$pem}";

        $kinds = array_column(SecretScan::find($page, []), 'count', 'kind');
        ksort($kinds);

        $this->assertSame(['AWS access key' => 1, 'Anthropic key' => 1, 'GitHub token' => 1, 'Google API key' => 1, 'OpenAI-style key' => 2, 'Private key' => 1], $kinds);
    }

    public function testAnInlineImageIsNotSearched(): void
    {
        // base64 is noise a key shape can match by chance.
        $page = '<img src="data:image/png;base64,AKIA' . str_repeat('Z', 16) . 'sk-proj-' . str_repeat('q', 30) . '">';

        $this->assertSame([], SecretScan::find($page, []));
    }

    public function testRedactingLeavesNoTailOfALongerFinding(): void
    {
        // Longest first: a stored value that is the start of a longer key does not leave its end behind.
        $key = 'sk-proj-' . str_repeat('x', 30);
        $page = "key {$key} and prefix sk-proj-xxxxxxxx";
        $hits = SecretScan::find($page, ['sk-proj-xxxxxxxx']);

        $this->assertSame($key, $hits[0]['value']);
        $this->assertSame('key [redacted] and prefix [redacted]', SecretScan::redact($page, $hits));
    }

    public function testAFindingIsListedWithoutItsWhole(): void
    {
        $this->assertSame('GitHub token ghp_CC…CCCC (2 times)', SecretScan::describe(['kind' => 'GitHub token', 'value' => 'ghp_' . str_repeat('C', 36), 'count' => 2]));
        $this->assertSame('Stored credential •••••••••••• (once)', SecretScan::describe(['kind' => 'Stored credential', 'value' => 'abcdefghijkl', 'count' => 1]));
    }

    public function testTheKnownValuesAreTheStoredOnesAndTheEnvironmentsCredentials(): void
    {
        $env = ['GROQ_API_KEY' => 'gsk-0123456789', 'GH_TOKEN' => 'gho-0123456789', 'HOME' => '/home/someone-long', 'TINY_TOKEN' => 'x', 'DB_PASSWORD' => 'correct-horse-1'];

        $this->assertSame(
            ['stored-key-0001', 'gsk-0123456789', 'gho-0123456789', 'correct-horse-1'],
            SecretScan::knownSecrets(['stored-key-0001', 'gsk-0123456789'], $env),
        );
    }
}
