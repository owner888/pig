<?php

declare(strict_types=1);

namespace PigAntigravity;


use Pig\Ai\Api;
use Pig\Ai\Model;
use Pig\Ai\Models as Registry;
use Pig\Ai\Pricing;
use PigAntigravity\AntigravityApi as Antigravity;
use Pig\CodingAgent\Config;

/**
 * Antigravity's catalogue, as `Discovery` — and the `pi-antigravity` extension in pi — leaves it on disk.
 *
 * the extension's own `Models::fallback()` and `Routing`'s two tables are a print
 * of the deployment's catalogue endpoint, taken whenever `scripts/fetch-antigravity-models.php`
 * was last run. `Discovery` asks the same endpoint every four hours and writes the answer
 * down, in pig's own `models-store.json`, as pi-antigravity does in pi's. So this is not a second
 * opinion about what Antigravity sells — it is the same source, more recently read, which is why it
 * replaces the fallback (`install()`).
 *
 * **Where it actually lives was the find.** The developer named three files —
 * `auth.json`, `antigravity-accounts.json`, `antigravity-model-catalog.json` — and the
 * extension's source mentions only the first two: `antigravity-model-catalog.json` is a file an
 * *older* version of it wrote, and the one on the developer's machine is twenty-six days staler
 * than the live data. What the current extension does is hand the catalogue to pi, which files
 * it in `models-store.json` under the provider and the extension's own key:
 *
 * ```
 * models-store.json
 *   antigravity
 *     models[]                       what pi offers, provider/api/baseUrl filled in
 *     pi-antigravity
 *       catalog { models[], routing } the three tables, minus the enums
 *       checkedAt                     when it was last asked for
 *       modelEnums { runtime => enum }
 * ```
 *
 * pig's own store is read first, then pi's, then the older file. The older file is kept as a fallback rather than dropped
 * because it is what a machine that has not run the newer extension still has, and reading a
 * stale catalogue is better than reading none — but it carries **no `modelEnums`**, so what it
 * can contribute is routing for models whose runtime ids pig's generated enum table already
 * names, and nothing else. `orphans()` is what decides that.
 *
 * **Nothing throws and nothing is half-applied.** A file that is wrong loses only itself: the
 * built-in tables are still there, pig still starts, and every problem names the file. That is
 * `CustomModels`' rule, and this one needs the all-or-nothing half more than that class does —
 * a routing table installed with one target missing turns that thinking level into an error at
 * the moment somebody picks it, which is as far from the file as a failure can get.
 */
final readonly class Catalog
{
    /** Where the extension files its catalogue inside pi's store, and what it calls itself. */
    private const string PROVIDER = 'antigravity';
    private const string EXTENSION = 'pi-antigravity';

    /** Upstream's `ThinkingLevelMapSchema`, the same seven keys `CustomModels` keeps. */
    private const array LEVELS = ['off', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'];

    /**
     * @param list<Model>                                                                       $models
     * @param array<string, array{default: string, off?: string, levels: array<string, string>}> $routing
     * @param array<string, string>                                                              $enums
     * @param list<string>                                                                      $problems
     * @param int $checkedAt when the deployment was last asked, in milliseconds; 0 when nobody said
     * @param array{models: list<array<string, mixed>>, routing: array<string, array<string, mixed>>}|null $catalog
     *        the tables in the extension's own shape, as read — what `Discovery` keeps as the current
     *        catalog once this is installed
     */
    private function __construct(
        public array $models,
        public array $routing,
        public array $enums,
        public array $problems,
        public int $checkedAt = 0,
        public ?array $catalog = null,
    ) {
    }

    /** Nothing at all, for `--no-save` and for a session that should not read the person's files. */
    public static function none(): self
    {
        return new self([], [], [], []);
    }

    /**
     * A catalog `Discovery` built, through the same checks as one read from a file.
     *
     * @param array{models: list<array<string, mixed>>, routing: array<string, array<string, mixed>>} $catalog
     * @param array<string, string> $enums
     */
    public static function fromCatalog(string $source, array $catalog, array $enums, int $checkedAt): self
    {
        return self::tables($source, $catalog['models'], $catalog['routing'], $enums, $checkedAt);
    }

    /** pig's own store, which `Discovery` writes in pi's shape: `~/.pig/agent/models-store.json`. */
    public static function storePath(): string
    {
        return Config::home() . '/models-store.json';
    }

    /**
     * pig's own store, then pi's, then pi's older catalogue file, or nothing.
     *
     * A fallback rather than a merge, for `CustomModels::discover()`'s reason: one of them is
     * the current answer and the other is a copy of what the answer used to be, and merging two
     * snapshots of one table is how a model that was withdrawn comes back. pig's own comes first
     * because it is the one `Discovery` refreshes; pi's is what a machine that has only run pi has.
     */
    public static function discover(): self
    {
        $problems = [];

        foreach ([self::storePath(), Config::piHome() . '/models-store.json', Config::piHome() . '/antigravity-model-catalog.json'] as $path) {
            $found = self::load($path);

            if ($found->models !== []) {
                // The earlier files' own problems are kept: they were there and unreadable, which
                // is worth saying even when a later one answered.
                return new self($found->models, $found->routing, $found->enums, [...$problems, ...$found->problems], $found->checkedAt, $found->catalog);
            }

            $problems = [...$problems, ...$found->problems];
        }

        return new self([], [], [], $problems);
    }

    /** Either shape, told apart by what is in it rather than by the file's name. */
    public static function load(string $path): self
    {
        // Not having one is the normal case — most machines have never run the extension — so a
        // missing file is silent, as it is in `CustomModels`. A file that is there and cannot be
        // read is different and says so.
        if (!is_file($path)) {
            return self::none();
        }

        $raw = is_readable($path) ? file_get_contents($path) : false;

        if ($raw === false) {
            return new self([], [], [], ["{$path} could not be read"]);
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            return new self([], [], [], ["{$path} is not valid JSON: {$error->getMessage()}"]);
        }

        if (!is_array($decoded)) {
            return new self([], [], [], ["{$path} is not an object"]);
        }

        // Which shape this is, told apart by what is in the file rather than by its name — a
        // store nests the tables under the provider and the extension, the older file *is* the
        // tables. Anything that is neither is passed over in silence: a `models-store.json`
        // written by a pi that never ran the extension is the normal case, and a warning on
        // every start is how people learn to skip the warnings that matter.
        $extension = $decoded[self::PROVIDER][self::EXTENSION] ?? null;
        $enums = [];
        $checkedAt = 0;

        if (is_array($extension)) {
            $catalogue = $extension['catalog'] ?? null;
            $enums = is_array($extension['modelEnums'] ?? null) ? $extension['modelEnums'] : [];
            // pi-antigravity's `hydrateAntigravityCatalog()`: "typeof persisted.checkedAt === "number"
            // && persisted.checkedAt > 0 ? persisted.checkedAt : 0".
            $checkedAt = (is_int($extension['checkedAt'] ?? null) || is_float($extension['checkedAt'] ?? null)) && $extension['checkedAt'] > 0
                ? (int) $extension['checkedAt']
                : 0;
        } elseif (is_array($decoded['models'] ?? null) && is_array($decoded['routing'] ?? null)) {
            $catalogue = $decoded;
        } else {
            return self::none();
        }

        if (!is_array($catalogue) || !is_array($catalogue['models'] ?? null) || !is_array($catalogue['routing'] ?? null)) {
            // The one case worth a line: the extension wrote its section and what is in it is
            // not a catalogue. Silence there would be a catalogue silently not taking effect.
            return new self([], [], [], ["{$path} has an Antigravity section with no \"models\" and \"routing\" in it"]);
        }

        return self::tables($path, $catalogue['models'], $catalogue['routing'], $enums, $checkedAt);
    }

    /**
     * Hand the models to the registry and the tables to the router.
     *
     * Both or neither: a model whose routing did not go in is a model `Routing::resolve()`
     * refuses, which is a worse answer than the built-in row it would have replaced.
     */
    public function install(): void
    {
        if ($this->models === [] || $this->routing === []) {
            return;
        }

        // pi-antigravity's `applyAntigravityCatalog()` replaces the provider's model list, and the
        // routing is replaced with it: a row kept from the list before has no routing any more.
        Routing::useTables($this->routing, $this->enums);
        Registry::forgetProvider(Models::PROVIDER);
        Registry::register($this->models, replace: true);
    }

    /**
     * @param array<mixed> $models
     * @param array<mixed> $routing
     * @param array<mixed> $enums
     */
    private static function tables(string $path, array $models, array $routing, array $enums, int $checkedAt = 0): self
    {
        $problems = [];
        $strings = [];

        foreach ($enums as $runtime => $enum) {
            if (is_string($runtime) && is_string($enum)) {
                $strings[$runtime] = $enum;
            }
        }

        $entries = [];

        foreach ($routing as $id => $entry) {
            if (!is_string($id) || !is_array($entry)) {
                continue;
            }

            $built = self::entry($entry);

            if ($built === null) {
                $problems[] = "{$path}: the routing for \"{$id}\" names no default model";

                continue;
            }

            $entries[$id] = $built;
        }

        $orphans = Routing::orphans($entries, $strings);

        if ($orphans !== []) {
            // All of it, not the models whose routing happened to be complete. The two tables
            // are one snapshot, and keeping half of one is how the routing and the model list
            // come to disagree about which models exist.
            return new self([], [], [], [
                ...$problems,
                "{$path}: " . implode(', ', $orphans) . ' — routing names models with no enum, so the '
                . 'built-in tables are being used instead. Regenerate both with '
                . 'scripts/fetch-antigravity-models.php.',
            ]);
        }

        $built = [];

        foreach ($models as $model) {
            if (!is_array($model)) {
                continue;
            }

            $one = self::model($path, $model, $entries);

            if (is_string($one)) {
                $problems[] = $one;

                continue;
            }

            $built[] = $one;
        }

        return new self($built, $entries, $strings, $problems, $checkedAt, ['models' => array_values($models), 'routing' => $routing]);
    }

    /**
     * The file's shape turned into `Routing`'s.
     *
     * `defaultRequestId` becomes `default` and `routing` becomes `levels`; `off` keeps its name
     * and its **absence**, which is the one thing about this shape that matters — a model with
     * no `off` is one that cannot have thinking turned off, and an `off` of `''` is not the same
     * statement.
     *
     * @param array<mixed> $entry
     *
     * @return array{default: string, off?: string, levels: array<string, string>}|null
     */
    private static function entry(array $entry): ?array
    {
        $default = $entry['defaultRequestId'] ?? null;

        if (!is_string($default) || $default === '') {
            return null;
        }

        $built = ['default' => $default];

        if (is_string($entry['off'] ?? null) && $entry['off'] !== '') {
            $built['off'] = $entry['off'];
        }

        $levels = [];

        foreach (is_array($entry['routing'] ?? null) ? $entry['routing'] : [] as $level => $target) {
            if (is_string($level) && is_string($target) && $target !== '') {
                $levels[$level] = $target;
            }
        }

        $built['levels'] = $levels;

        return $built;
    }

    /**
     * One model, or the line saying why not.
     *
     * @param array<mixed>                                                                      $entry
     * @param array<string, array{default: string, off?: string, levels: array<string, string>}> $routing
     */
    private static function model(string $path, array $entry, array $routing): Model|string
    {
        $id = $entry['id'] ?? null;

        if (!is_string($id) || $id === '') {
            return "{$path}: a model with no \"id\"";
        }

        // A model the routing cannot reach is a model every turn would fail on. The catalogue
        // carries the Antigravity IDE's own models — tab completion, its chat surfaces — and
        // this is the same filter `fetch-antigravity-models.php` applies for the same reason.
        if (!isset($routing[$id])) {
            return "{$path}, model \"{$id}\": nothing routes to it, so it is not one this can be asked for";
        }

        $window = $entry['contextWindow'] ?? null;
        $maxTokens = $entry['maxTokens'] ?? null;

        if (!is_int($window) || $window <= 0 || !is_int($maxTokens) || $maxTokens <= 0) {
            return "{$path}, model \"{$id}\": \"contextWindow\" and \"maxTokens\" must be positive whole numbers";
        }

        $input = [];

        foreach (is_array($entry['input'] ?? null) ? $entry['input'] : ['text'] as $accepted) {
            if ($accepted === 'text' || $accepted === 'image') {
                $input[] = $accepted;
            }
        }

        return new Model(
            $id,
            is_string($entry['name'] ?? null) && $entry['name'] !== '' ? $entry['name'] : $id,
            Api::Extension,
            Models::PROVIDER,
            Antigravity::ENDPOINT,
            $window,
            $maxTokens,
            ($entry['reasoning'] ?? false) === true,
            $input === [] ? ['text'] : $input,
            self::pricing(is_array($entry['cost'] ?? null) ? $entry['cost'] : []),
            thinkingLevelMap: self::thinkingLevelMap($entry['thinkingLevelMap'] ?? null),
            // As `Models::fallback()` does: upstream's generator default for a provider it has no
            // limits for — the resize profile on an image-taking model.
            inputLimits: \Pig\Ai\Models::inputLimits(Models::PROVIDER, $input === [] ? ['text'] : $input, $window),
        );
    }

    /**
     * Dollars per million tokens, as `CustomModels` reads the same four names.
     *
     * A price that is not a number is read as free here rather than refused, which is the
     * opposite of that class's rule and for the reason that separates the two files: a
     * `models.json` is written by a person who can be told to fix it, and this one is written by
     * another program — refusing the model would take it out of `/model` over a field nobody
     * can edit. The built-in rows report zero for all fourteen anyway, so a bad `cost` here is
     * no worse than not reading the file at all.
     *
     * @param array<mixed> $cost
     */
    private static function pricing(array $cost): Pricing
    {
        $number = static fn (string $key): float => is_int($cost[$key] ?? null) || is_float($cost[$key] ?? null)
            ? (float) $cost[$key]
            : 0.0;

        return new Pricing($number('input'), $number('output'), $number('cacheRead'), $number('cacheWrite'));
    }

    /**
     * Absent, null and a string are three different states — see `Model::hasThinkingLevel()`.
     *
     * @return array<string, string|null>
     */
    private static function thinkingLevelMap(mixed $map): array
    {
        if (!is_array($map)) {
            return [];
        }

        $kept = [];

        foreach (self::LEVELS as $level) {
            if (!array_key_exists($level, $map)) {
                continue;
            }

            if ($map[$level] === null || is_string($map[$level])) {
                $kept[$level] = $map[$level];
            }
        }

        return $kept;
    }
}
