<?php

declare(strict_types=1);

namespace Pig\Extensions\GeminiVideo;

use Closure;
use InvalidArgumentException;
use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;
use Pig\Async\AbortSignal;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Hooks\HookContext;
use Throwable;

/**
 * Video understanding through Gemini, for whichever model pig is talking to.
 *
 * - The `analyze_video` tool: the model hands Gemini a local video and gets its answer back.
 * - `/gemini-video <path> [prompt]`: the same analysis, put into the conversation as a message to
 *   talk about afterwards, without starting a turn.
 *
 * How it gets there — the Antigravity subscription or a Gemini API key — is {@see GeminiVideo}'s.
 */
return static function (ExtensionApi $pi): void {
    require_once __DIR__ . '/src/GeminiVideo.php';

    $video = new GeminiVideo(static fn (string $name): mixed => $pi->getSettings()?->get("geminiVideo.{$name}"));

    $pi->registerTool(new CustomTool(
        name: 'analyze_video',
        label: 'Analyze Video',
        description: 'Watch a local video file with Google Gemini, which understands video (motion, audio, on-screen text, UI flows), '
            . 'and return its answer as text. Use this whenever you need to know what is in a video — the current model cannot see video itself. '
            . 'Supported: ' . implode(', ', array_keys(GeminiVideo::MIME_TYPES)) . '. Large files are uploaded, which can take a minute or more.',
        parameters: [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Path to the video file, absolute or relative to the working directory',
                ],
                'prompt' => [
                    'type' => 'string',
                    'description' => 'What to ask about the video. Be specific (e.g. "list every UI screen shown and what the user taps"). '
                        . 'Defaults to a detailed description of content, actions and UI details.',
                ],
            ],
            'required' => ['path'],
        ],
        execute: static function (string $id, array $params, ?Closure $onUpdate, HookContext $ctx, ?AbortSignal $signal) use ($video): AgentToolResult {
            $path = trim((string) ($params['path'] ?? ''));
            if ($path === '') {
                throw new InvalidArgumentException('path must not be empty');
            }

            $prompt = trim((string) ($params['prompt'] ?? '')) ?: GeminiVideo::DEFAULT_PROMPT;
            $progress = static function (string $line) use ($onUpdate): void {
                $onUpdate?->__invoke(new AgentToolResult([new TextContent($line)]));
            };

            $result = $video->analyze($path, $prompt, $ctx, $progress, $signal);

            return new AgentToolResult(
                [new TextContent($result['text'])],
                ['path' => $path, 'model' => $result['model'], 'route' => $result['route'], 'bytes' => $result['bytes']],
            );
        },
    ));

    $pi->registerCommand(
        'gemini-video',
        static function (string $args, HookContext $ctx) use ($pi, $video): void {
            $parts = preg_split('/\s+/', trim($args), 2) ?: [];
            $path = trim($parts[0] ?? '', "'\"");
            $prompt = trim($parts[1] ?? '') ?: GeminiVideo::DEFAULT_PROMPT;

            if ($path === '') {
                $ctx->ui->notify('Usage: /gemini-video <video file> [prompt]');

                return;
            }

            $progress = static fn (string $line) => $ctx->ui->setStatus('gemini-video', "🎬 {$line}");

            try {
                $progress('Preparing…');
                $result = $video->analyze($path, $prompt, $ctx, $progress, $ctx->signal);
            } catch (Throwable $e) {
                $ctx->ui->notify($e->getMessage(), 'error');

                return;
            } finally {
                $ctx->ui->setStatus('gemini-video', null);
            }

            // Into the conversation and on screen, so the model can see it and talk about it next —
            // but not a turn of its own: the person asked to watch, not to be answered.
            $pi->sendMessage(
                'gemini-video',
                '🎬 Gemini (' . ($result['route'] === 'antigravity' ? 'antigravity · ' : '') . "{$result['model']}) on " . basename($path) . ":\n\n{$result['text']}",
                display: true,
                details: ['path' => $path, 'prompt' => $prompt, 'model' => $result['model'], 'route' => $result['route']],
                triggerTurn: false,
            );
        },
        'Have Gemini watch a video and put its answer in the conversation (/gemini-video <path> [prompt])',
    );
};
