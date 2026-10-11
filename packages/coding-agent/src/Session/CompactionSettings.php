<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Session;

/**
 * The three numbers compaction runs on — upstream's `CompactionSettings` and
 * `DEFAULT_COMPACTION_SETTINGS` (`compaction/compaction.ts`).
 *
 * The session builds one from `Settings` for the model in use (`compaction.enabled`,
 * `compaction.reserveTokens`, `compaction.keepRecentTokens` with their `modelOverrides`), and it
 * travels with the preparation (`CompactionPreparation::$settings`) so an extension sees the
 * effective values rather than the ordinary ones.
 */
final readonly class CompactionSettings
{
    /**
     * @param bool $enabled          whether the threshold triggers compaction by itself
     * @param int  $reserveTokens    room left for the answer; also what sizes the summary's own output
     * @param int  $keepRecentTokens roughly how much of the recent conversation survives untouched
     */
    public function __construct(
        public bool $enabled = true,
        public int $reserveTokens = Compaction::RESERVE_TOKENS,
        public int $keepRecentTokens = Compaction::KEEP_RECENT_TOKENS,
    ) {
    }
}
