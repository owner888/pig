<?php

declare(strict_types=1);

namespace PigAntigravity;

use Pig\Ai\Api;
use Pig\Ai\Model;
use Pig\Ai\Pricing;

/**
 * The models this provider sells, as pig last saw them — the fallback the extension registers
 * before `Catalog` has a chance to replace them with what the deployment says today.
 *
 * This table used to be `Pig\Ai\Models::ANTIGRAVITY_MODELS`, the one subscription table in the
 * core registry that had no `generate-models.php` row because models.dev does not carry this
 * deployment. It is the extension's now, like everything else about this provider; what the
 * deployment's catalogue endpoint answers is in `Catalog`, and `scripts/fetch-antigravity-models.php`
 * in pig's repository prints this shape from it.
 *
 * `claude-sonnet-4-6` and friends are Anthropic's own ids under this provider: `Provider::$resold`
 * is true for exactly that reason, so `--model sonnet` keeps meaning Anthropic's.
 */
final class Models
{
    public const string PROVIDER = 'antigravity';

    /**
     * id => [name, context window, max output, reasoning, accepts images, thinkingLevelMap]
     *
     * @var array<string, array{0: string, 1: int, 2: int, 3: bool, 4: bool, 5: array<string, ?string>}>
     */
    private const array TABLE = [
        'gemini-3.8-flash' => ['Gemini 3.8 Flash (Antigravity)', 1_048_576, 65_536, true, true, ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null]],
        'gemini-3.7-flash' => ['Gemini 3.7 Flash (Antigravity)', 1_048_576, 65_536, true, true, ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null]],
        'gemini-3.6-flash' => ['Gemini 3.6 Flash (Antigravity)', 1_048_576, 65_536, true, true, ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null]],
        'gemini-3.5-flash' => ['Gemini 3.5 Flash (Antigravity)', 1_048_576, 65_536, true, true, ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => null]],
        'gemini-3.5-flash-lite' => ['Gemini 3.5 Flash Lite (Antigravity)', 1_048_576, 65_536, true, true, ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null]],
        'gemini-3.1-flash-lite' => ['Gemini 3.1 Flash Lite (Antigravity)', 1_048_576, 65_536, true, true, ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null]],
        'gemini-3-flash' => ['Gemini 3 Flash (Antigravity)', 1_048_576, 65_536, true, true, ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null]],
        'gemini-2.5-flash' => ['Gemini 2.5 Flash (Antigravity)', 1_048_576, 65_536, true, true, ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null]],
        'gemini-2.5-flash-lite' => ['Gemini 2.5 Flash Lite (Antigravity)', 1_048_576, 65_536, true, true, ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null]],
        'claude-opus-4-6' => ['Claude Opus 4.6 (Antigravity)', 250_000, 64_000, true, true, ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null]],
        'claude-sonnet-4-6' => ['Claude Sonnet 4.6 (Antigravity)', 200_000, 64_000, true, true, ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null]],
        'gemini-3.1-pro' => ['Gemini 3.1 Pro (Antigravity)', 1_048_576, 65_535, true, true, ['off' => null, 'minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null]],
        'gemini-2.5-pro' => ['Gemini 2.5 Pro (Antigravity)', 1_048_576, 65_535, true, true, ['off' => null, 'minimal' => null, 'low' => null, 'medium' => null, 'high' => 'high', 'xhigh' => null]],
        'gpt-oss-120b' => ['GPT-OSS 120B (Antigravity)', 131_072, 32_768, true, false, ['off' => null, 'minimal' => null, 'low' => null, 'medium' => 'medium', 'high' => null, 'xhigh' => null]],    ];

    /** @return list<Model> */
    public static function fallback(): array
    {
        $models = [];

        foreach (self::TABLE as $id => [$name, $window, $maxTokens, $reasoning, $images, $thinking]) {
            $models[] = new Model(
                $id,
                $name,
                Api::Extension,
                self::PROVIDER,
                AntigravityApi::ENDPOINT,
                $window,
                $maxTokens,
                $reasoning,
                $images ? ['text', 'image'] : ['text'],
                new Pricing(),
                thinkingLevelMap: $thinking,
            );
        }

        return $models;
    }
}
