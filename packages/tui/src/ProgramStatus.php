<?php

declare(strict_types=1);

namespace Pig\Tui;

/**
 * Program Status Protocol (OSC 7501): a program tells the terminal whether it is idle, working,
 * blocked on the user, done, or failed. Only the root record is supported.
 *
 * Spec: https://www.superlogical.com/rex/docs/build/program-status
 *
 * Upstream's `program-status.ts`: the `ProgramStatus` interface is this class, and its three
 * module-level exports are the constant and the two static methods.
 */
final readonly class ProgramStatus
{
    /** Feature detection query. A supporting terminal replies with the same body. */
    public const string QUERY = "\x1b]7501;?\x1b\\";

    private const string APP_PATTERN = '/^[A-Za-z0-9_.+-]{1,32}$/';

    private const string CONTROL_CHARACTERS = '/[\x00-\x1f\x7f]+|\xc2[\x80-\x9f]+/';

    /** Decoded `msg` limit. Its base64 encoding stays under the 2732-byte encoded limit. */
    private const int MAX_MESSAGE_BYTES = 2048;

    /**
     * @param 'idle'|'working'|'blocked'|'done'|'error'|'clear' $state `clear` removes the status instead of reporting one
     * @param string|null $app stable program name, `[A-Za-z0-9_.+-]{1,32}`; other values are omitted
     * @param 'permission'|'question'|'auth'|null $kind what a blocked program waits for; omitted for other states
     * @param string|null $message one human-readable line; control characters become spaces, longer text is cut to the spec limit
     */
    public function __construct(
        public string $state,
        public ?string $app = null,
        public ?string $kind = null,
        public ?string $message = null,
    ) {
    }

    /** The reply to {@see QUERY}. Later spec revisions may add pairs after the `?`. */
    public static function isReply(string $sequence): bool
    {
        return preg_match('/^\x1b\]7501;\?[^\x07\x1b]*(?:\x07|\x1b\\\\)$/', $sequence) === 1;
    }

    /** Encode a status report. Terminals discard reports whose text contains control characters, so they are replaced. */
    public function format(): string
    {
        $pairs = ["state={$this->state}"];

        if ($this->app !== null && preg_match(self::APP_PATTERN, $this->app) === 1) {
            $pairs[] = "app={$this->app}";
        }

        if ($this->state === 'blocked' && $this->kind !== null) {
            $pairs[] = "kind={$this->kind}";
        }

        $message = self::truncateUtf8(trim((string) preg_replace(self::CONTROL_CHARACTERS, ' ', $this->message ?? '')), self::MAX_MESSAGE_BYTES);

        if ($message !== '') {
            $pairs[] = 'msg=' . base64_encode($message);
        }

        return "\x1b]7501;" . implode(':', $pairs) . "\x1b\\";
    }

    private static function truncateUtf8(string $text, int $maxBytes): string
    {
        if (strlen($text) <= $maxBytes) {
            return $text;
        }

        return mb_strcut($text, 0, $maxBytes, 'UTF-8');
    }
}
