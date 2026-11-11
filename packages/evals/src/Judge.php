<?php

declare(strict_types=1);

namespace Pig\Evals;

use Closure;

/**
 * Deterministic judges and transcript helpers — upstream's `StructuredOutputJudge`, `ToolCallJudge`,
 * `createJudge` and `toolCalls` from `vitest-evals`.
 */
final class Judge
{
    /**
     * Upstream's `StructuredOutputJudge({ expected, match: "strict", allowExtras: false })`.
     *
     * @return array{name: string, evaluate: Closure(array<string, mixed>): array{score: float|int, metadata?: array<string, mixed>}}
     */
    public static function structuredOutput(mixed $expected, string $match = 'strict', bool $allowExtras = false): array
    {
        return self::create('StructuredOutputJudge', static function (array $context) use ($expected, $match, $allowExtras): array {
            $actual = $context['output'] ?? null;
            $ok = self::matches($expected, $actual, $match, $allowExtras);

            return [
                'score' => $ok ? 1 : 0,
                'metadata' => $ok ? [] : ['expected' => $expected, 'actual' => $actual],
            ];
        });
    }

    /**
     * Upstream's `ToolCallJudge({ expectedTools: [{ name, arguments }] })`.
     *
     * @param list<array{name: string, arguments?: array<string, mixed>}> $expectedTools
     * @return array{name: string, evaluate: Closure(array<string, mixed>): array{score: float|int, metadata?: array<string, mixed>}}
     */
    public static function toolCalls(array $expectedTools): array
    {
        return self::create('ToolCallJudge', static function (array $context) use ($expectedTools): array {
            $calls = self::extractToolCalls($context['result'] ?? $context);

            foreach ($expectedTools as $expected) {
                $found = false;

                foreach ($calls as $call) {
                    if ($call['name'] !== $expected['name']) {
                        continue;
                    }

                    if (isset($expected['arguments']) && !self::matches($expected['arguments'], $call['arguments'], 'strict', false)) {
                        continue;
                    }

                    $found = true;

                    break;
                }

                if (!$found) {
                    return ['score' => 0, 'metadata' => ['expectedTools' => $expectedTools, 'actualCalls' => $calls]];
                }
            }

            return ['score' => 1];
        });
    }

    /**
     * Upstream's `createJudge(name, fn)`.
     *
     * @param Closure(array<string, mixed>): array{score: float|int, metadata?: array<string, mixed>} $evaluate
     * @return array{name: string, evaluate: Closure(array<string, mixed>): array{score: float|int, metadata?: array<string, mixed>}}
     */
    public static function create(string $name, Closure $evaluate): array
    {
        return ['name' => $name, 'evaluate' => $evaluate];
    }

    /**
     * Upstream's `toolCalls(result)`: extracts tool calls paired with their status from the run's
     * transcript events.
     *
     * @param array<string, mixed> $result
     * @return list<array{id: string, name: string, arguments: array<string, mixed>, status: 'ok'|'error'}>
     */
    public static function extractToolCalls(array $result): array
    {
        $events = $result['events'] ?? $result['session']['events'] ?? [];

        if (!is_array($events)) {
            return [];
        }

        $statuses = [];

        foreach ($events as $event) {
            if (is_array($event) && ($event['type'] ?? null) === 'tool_result' && isset($event['toolCallId'])) {
                $statuses[(string) $event['toolCallId']] = isset($event['error']) ? 'error' : 'ok';
            }
        }

        $calls = [];

        foreach ($events as $event) {
            if (!is_array($event) || ($event['type'] ?? null) !== 'tool_call') {
                continue;
            }

            $id = (string) ($event['id'] ?? '');
            $calls[] = [
                'id' => $id,
                'name' => (string) ($event['name'] ?? ''),
                'arguments' => is_array($event['arguments'] ?? null) ? $event['arguments'] : [],
                'status' => $statuses[$id] ?? 'ok',
            ];
        }

        return $calls;
    }

    private static function matches(mixed $expected, mixed $actual, string $match, bool $allowExtras): bool
    {
        unset($match);

        if (is_array($expected)) {
            if (!is_array($actual)) {
                return false;
            }

            if (array_is_list($expected)) {
                if (!array_is_list($actual) || count($expected) !== count($actual)) {
                    return false;
                }

                foreach ($expected as $i => $item) {
                    if (!self::matches($item, $actual[$i], 'strict', $allowExtras)) {
                        return false;
                    }
                }

                return true;
            }

            if (!$allowExtras && count(array_diff(array_keys($actual), array_keys($expected))) > 0) {
                return false;
            }

            foreach ($expected as $key => $value) {
                if (!array_key_exists($key, $actual) || !self::matches($value, $actual[$key], 'strict', $allowExtras)) {
                    return false;
                }
            }

            return true;
        }

        if (is_int($expected) || is_float($expected)) {
            return (is_int($actual) || is_float($actual)) && (float) $expected === (float) $actual;
        }

        return $expected === $actual;
    }
}
