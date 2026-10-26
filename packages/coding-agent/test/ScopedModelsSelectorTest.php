<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\Model;
use Pig\CodingAgent\Interactive\ScopedModelsSelector;

final class ScopedModelsSelectorTest extends TestCase
{
    /** @return iterable<string, array{string, string, ?string}> */
    public static function names(): iterable
    {
        // Upstream #5916's cases: a name that only restates the id stays hidden…
        yield 'casing and separators' => ['claude-sonnet-4-5', 'Claude Sonnet 4.5', null];
        yield 'a namespace in the name' => ['openai/gpt-oss-120b', 'OpenAI: gpt-oss-120b', null];
        yield 'a version suffix dropped' => ['eu.anthropic.claude-sonnet-4-5-20250929-v1:0', 'Claude Sonnet 4.5 (EU)', null];
        yield 'no name' => ['gpt-5', '', null];
        // …one with words the id lacks is shown…
        yield 'a renamed override' => ['minimax/minimax-m3', 'MiniMax M3 [work@openrouter, parasail]', 'MiniMax M3 [work@openrouter, parasail]'];
        yield 'a marketing name' => ['gemini-3.1-flash-lite-image', 'Nano Banana 2 Lite', 'Nano Banana 2 Lite'];
        // …and a one-character word has to be a whole token of the id, not any digit in it.
        yield 'a lone digit' => ['mistral-small-2603', 'Mistral Small 4', 'Mistral Small 4'];
    }

    #[DataProvider('names')]
    public function testANameIsShownOnlyWhenItSaysMoreThanTheId(string $id, string $name, ?string $expected): void
    {
        $model = new Model($id, $name, Api::AnthropicMessages, 'anthropic', 'http://127.0.0.1:1', 1_000, 100);

        $this->assertSame($expected, ScopedModelsSelector::distinctName($model));
    }
}
