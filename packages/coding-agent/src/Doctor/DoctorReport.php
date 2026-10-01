<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Doctor;

/**
 * Structured diagnostic snapshot of the environment, tools, credentials, and session.
 */
final readonly class DoctorReport
{
    /**
     * @param array{version: string, ok: bool, extensions: array<string, bool>} $php
     * @param array{stty: ?string, git: ?string, fd: ?string, rg: ?string, clipboardText: ?string, clipboardImage: ?string} $binaries
     * @param array{path: ?string, readable: bool, valid: bool, providers: list<array{provider: string, type: string, detail: string, active: bool}>, antigravityAccounts: int} $auth
     * @param array{enabled: bool, url: ?string, scheme: ?string} $proxy
     * @param array{cwd: string, model: string, provider: string, thinkingLevel: string, gitBranch: ?string, gitDirty: bool, sessionFile: ?string, sessionSize: ?int, messageCount: int} $session
     */
    public function __construct(
        public array $php,
        public array $binaries,
        public array $auth,
        public array $proxy,
        public array $session,
    ) {
    }
}
