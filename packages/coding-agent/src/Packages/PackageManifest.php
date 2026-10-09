<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Packages;

/**
 * The `pig` manifest in a package's `composer.json` — upstream's `pi` key in `package.json`,
 * under `extra` because that is where composer keeps what is not its own:
 *
 *     "extra": { "pig": { "extensions": ["./src/Extension.php"], "skills": ["./resources/skills"],
 *                         "prompts": ["./prompts/*.md"], "themes": ["./themes/*.json"] } }
 *
 * Paths are relative to the package root; an entry may be a glob, or a `!`, `+`, `-` override
 * pattern as the settings use them. A field that is not a list of strings is as good as absent.
 * Null when there is no `composer.json`, it is not JSON, or it has no `extra.pig`.
 */
final readonly class PackageManifest
{
    /**
     * @param list<string>|null $extensions
     * @param list<string>|null $skills
     * @param list<string>|null $prompts
     * @param list<string>|null $themes
     */
    public function __construct(
        public ?array $extensions = null,
        public ?array $skills = null,
        public ?array $prompts = null,
        public ?array $themes = null,
    ) {
    }

    public static function read(string $packageRoot): ?self
    {
        $path = $packageRoot . '/composer.json';

        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        // A BOM is not JSON, and an editor on Windows writes one without asking.
        $decoded = json_decode(ltrim($raw, "\xEF\xBB\xBF"), true);
        $pig = is_array($decoded) ? ($decoded['extra']['pig'] ?? null) : null;

        if (!is_array($pig)) {
            return null;
        }

        $fields = [];

        foreach (ResolvedPaths::TYPES as $type) {
            $entries = $pig[$type] ?? null;
            // `array_all` is 8.4 and the floor is 8.3.
            $fields[$type] = is_array($entries) && array_is_list($entries) && array_filter($entries, is_string(...)) === $entries ? $entries : null;
        }

        return new self($fields['extensions'], $fields['skills'], $fields['prompts'], $fields['themes']);
    }

    /** @return list<string>|null */
    public function of(string $type): ?array
    {
        return match ($type) {
            'extensions' => $this->extensions,
            'skills' => $this->skills,
            'prompts' => $this->prompts,
            'themes' => $this->themes,
            default => null,
        };
    }
}
