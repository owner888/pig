<?php

declare(strict_types=1);

namespace Pig\Ai\Test;

use PHPUnit\Framework\TestCase;
use Pig\Ai\ModelJson;
use Pig\Ai\Models;
use Pig\Scripts\BuiltinCatalog;

require_once __DIR__ . '/../../../scripts/BuiltinCatalog.php';

/**
 * The shipped catalogue — `packages/ai/data/models/*.json`, in pi's model format — against the rows
 * and rules it is built from. A row edited without `php scripts/build-models.php` is red here.
 */
final class ModelsCatalogueTest extends TestCase
{
    public function testTheShippedFilesAreWhatTheRowsBuild(): void
    {
        $files = BuiltinCatalog::files();
        $shipped = array_map('basename', glob(Models::CATALOGUE_DIR . '/*.json') ?: []);
        sort($shipped);
        $expected = array_keys($files);
        sort($expected);

        $this->assertSame($expected, $shipped, 'run php scripts/build-models.php');

        foreach ($files as $name => $text) {
            $this->assertSame($text, (string) file_get_contents(Models::CATALOGUE_DIR . '/' . $name), "{$name} is stale: run php scripts/build-models.php");
        }
    }

    public function testEveryEntryReadsBackAsTheModelItWasWrittenFrom(): void
    {
        foreach ([...BuiltinCatalog::chat(), ...BuiltinCatalog::classifiers(), ...BuiltinCatalog::images()] as $model) {
            $entry = json_decode((string) json_encode(ModelJson::encode($model), JSON_PRESERVE_ZERO_FRACTION), true);

            $this->assertEquals($model, ModelJson::decode($entry), "{$model->provider}/{$model->id}");
        }
    }

    public function testAnEntryIsPisModelShape(): void
    {
        $sonnet = null;

        foreach (json_decode((string) file_get_contents(Models::CATALOGUE_DIR . '/anthropic.json'), true) as $entry) {
            $sonnet ??= $entry;
        }

        $this->assertIsArray($sonnet);
        $this->assertSame(['type', 'id', 'name', 'api', 'provider', 'baseUrl', 'reasoning'], array_slice(array_keys($sonnet), 0, 7));
        $this->assertSame('chat', $sonnet['type']);
        $this->assertSame('anthropic-messages', $sonnet['api']);
        $this->assertSame(['input', 'output', 'cacheRead', 'cacheWrite'], array_slice(array_keys($sonnet['cost']), 0, 4));
        $this->assertArrayHasKey('contextWindow', $sonnet);
        $this->assertArrayHasKey('maxTokens', $sonnet);
        $this->assertTrue($sonnet['compat']['supportsStrictTools'] ?? null);
    }
}
