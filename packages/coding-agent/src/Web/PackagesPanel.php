<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Web;

use Closure;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\HttpTransport;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Retrying;
use Pig\Ai\Utils\PigUserAgent;
use Pig\CodingAgent\Config;
use Pig\CodingAgent\Extensions\ExtensionUi;
use Pig\CodingAgent\Extensions\WebPanel;
use Pig\CodingAgent\Packages\ComposerSource;
use Pig\CodingAgent\Packages\GitSource;
use Pig\CodingAgent\Packages\LocalSource;
use Pig\CodingAgent\Packages\PackageError;
use Pig\CodingAgent\Packages\PackageManager;
use Pig\CodingAgent\Packages\PackageSource;
use Pig\CodingAgent\ProjectTrust;
use Pig\CodingAgent\Rpc\RpcClient;
use Pig\CodingAgent\Settings;
use Pig\Tui\Process;
use Throwable;

/**
 * `pig web`'s Packages panel: the installed packages, each with its version and what it brings, and
 * Install, Update and Remove — the page's way to `pig install`, `pig update` and `pig remove`, as
 * DeepSeek Harness's web settings have a plugin manager (the developer's ask). pig's own; upstream
 * has no web page.
 *
 * **The commands do the work.** Each button runs `bin/pig install|update|remove <source>` as a child
 * process, on the loop: the CLI is the one implementation, and a clone or a `composer require` that
 * takes a minute does not hold up the page. A removal therefore takes back what the install wrote
 * to the settings exactly as `pig remove` does (`PackageManager::removeAndPersist()`) — decoded,
 * changed and encoded again, never edited as text.
 *
 * **Two tabs**: Installed, and the market — GitHub's topic `pig-package`, as pigagent.dev/packages
 * is: every repository with the topic, most stars first, each with Install. The market is a lazy
 * section, asked for only when its tab is opened, so a slow or rate-limited GitHub costs nothing
 * to someone who came to remove a package.
 *
 * Admin-only with accounts, like every panel (`AdminMiddleware`): a package is code the server runs.
 */
final class PackagesPanel
{
    public const string ID = 'packages';

    public const array TITLE = ['en' => 'Packages', 'zh-CN' => '插件'];

    /** The market's lazy section. */
    public const string MARKET = 'market';

    /** GitHub's search for the topic pigagent.dev's gallery is built from. */
    public const string MARKET_URL = 'https://api.github.com/search/repositories?q=topic:pig-package&sort=stars&order=desc&per_page=50';

    /** A clone or a Composer run that has not finished in this long is not going to. */
    public const float COMMAND_TIMEOUT = 600.0;

    private const array STRINGS = [
        'en' => [
            'install' => 'Install…',
            'install_prompt' => 'Package source: git:github.com/owner/repo, composer:vendor/name, or a local path',
            'update' => 'Update',
            'update_all' => 'Update all',
            'remove' => 'Remove',
            'remove_confirm' => 'Remove {name}? Its files go, and so does what its install wrote to settings.json.',
            'installed' => 'Installed {source}. New conversations load it; /reload loads it in an open one.',
            'updated' => 'Updated. New conversations load the new version; /reload loads it in an open one.',
            'removed' => 'Removed {name}.',
            'empty' => 'No packages installed. Install one, or find one at pigagent.dev/packages.',
            'user' => 'global',
            'project' => 'this project',
            'missing' => 'not installed',
            'extensions' => '{n} extension(s)',
            'skills' => '{n} skill(s)',
            'prompts' => '{n} prompt(s)',
            'themes' => '{n} theme(s)',
            'restart' => 'A package\'s own panels and pages appear after `pig web restart`.',
            'installed_heading' => 'Installed ({n})',
            'market_heading' => 'Market',
            'market_note' => 'GitHub repositories with the topic pig-package, most stars first — the list pigagent.dev/packages shows.',
            'market_loading' => 'Asking GitHub…',
            'market_empty' => 'No repository has the topic pig-package yet.',
            'market_failed' => 'GitHub did not answer: {error}',
            'is_installed' => 'Installed',
        ],
        'zh-CN' => [
            'install' => '安装…',
            'install_prompt' => '插件来源：git:github.com/owner/repo、composer:vendor/name，或本地路径',
            'update' => '更新',
            'update_all' => '全部更新',
            'remove' => '删除',
            'remove_confirm' => '删除 {name}？它的文件会被删除，安装时写进 settings.json 的内容也会一并删除。',
            'installed' => '已安装 {source}。新对话会加载它；已打开的对话用 /reload 加载。',
            'updated' => '已更新。新对话会加载新版本；已打开的对话用 /reload 加载。',
            'removed' => '已删除 {name}。',
            'empty' => '还没有安装插件。点「安装」，或到 pigagent.dev/packages 找一个。',
            'user' => '全局',
            'project' => '本项目',
            'missing' => '未安装',
            'extensions' => '{n} 个扩展',
            'skills' => '{n} 个技能',
            'prompts' => '{n} 个提示模板',
            'themes' => '{n} 个主题',
            'restart' => '插件自己的面板和页面要在 `pig web restart` 之后才出现。',
            'installed_heading' => '已安装（{n}）',
            'market_heading' => '插件市场',
            'market_note' => 'GitHub 上添加了 pig-package topic 的仓库，按 star 排序，和 pigagent.dev/packages 是同一份。',
            'market_loading' => '正在查询 GitHub…',
            'market_empty' => '还没有仓库添加 pig-package topic。',
            'market_failed' => 'GitHub 没有响应：{error}',
            'is_installed' => '已安装',
        ],
    ];

    /**
     * @param (Closure(list<string>, string): array{0: int, 1: string, 2: string})|null $run runs a
     *        `bin/pig` command line in a directory: exit code, stdout, stderr — a test's seam
     * @param HttpTransport|null $http what asks GitHub for the market — another test seam
     */
    public function __construct(
        private readonly string $cwd,
        private readonly ?string $home = null,
        private readonly ?Closure $run = null,
        private readonly ?HttpTransport $http = null,
    ) {
    }

    public function webPanel(): WebPanel
    {
        return new WebPanel(
            self::ID,
            self::TITLE,
            fn (array $request): array => $request['section'] === self::MARKET ? $this->market($request['locale']) : $this->render($request['locale']),
            fn (string $action, array $args, array $request, ExtensionUi $ui): ?string => $this->act($action, $args, $request['locale'], $ui),
            icon: '🧩',
            commands: [self::ID],
            placement: 'header',
        );
    }

    /** @return list<array<string, mixed>> */
    public function render(string $locale): array
    {
        $manager = new PackageManager($this->cwd, $this->settings(), $this->home);
        $counts = $this->resourceCounts($manager);
        $items = [];

        foreach ($manager->listConfiguredPackages() as $package) {
            $source = $package['source'];
            $local = $package['scope'] === 'project';
            $path = $package['installedPath'];
            $resources = $counts[$package['scope'] . "\0" . $source] ?? [];
            $brings = [];

            foreach (['extensions', 'skills', 'prompts', 'themes'] as $type) {
                if (($resources[$type] ?? 0) > 0) {
                    $brings[] = self::say($locale, $type, ['n' => $resources[$type]]);
                }
            }

            $args = ['source' => $source, 'local' => $local];
            $actions = [];

            if (!PackageSource::parse($source) instanceof LocalSource) {
                $actions[] = ['label' => self::say($locale, 'update'), 'action' => 'update', 'args' => $args];
            }

            $actions[] = [
                'label' => self::say($locale, 'remove'),
                'action' => 'remove',
                'args' => $args,
                'tone' => 'danger',
                'confirm' => self::say($locale, 'remove_confirm', ['name' => self::name($source)]),
            ];

            $items[] = [
                'title' => self::name($source),
                'detail' => implode(' · ', [$source, ...$brings]),
                'meta' => implode(' · ', array_filter([
                    $path === null ? self::say($locale, 'missing') : $this->version($path),
                    self::say($locale, $package['scope']),
                ])),
                'tone' => $path === null ? 'warning' : null,
                'actions' => $actions,
            ];
        }

        $buttons = [['label' => self::say($locale, 'install'), 'action' => 'install']];

        if ($items !== []) {
            $buttons[] = ['label' => self::say($locale, 'update_all'), 'action' => 'update'];
        }

        // Two tabs: what is installed, and the market — asked of GitHub only when its tab opens.
        return [
            ['type' => 'tabs', 'tabs' => [
                ['label' => self::say($locale, 'installed_heading', ['n' => count($items)]), 'blocks' => [
                    ['type' => 'actions', 'actions' => $buttons],
                    ['type' => 'list', 'items' => $items, 'empty' => self::say($locale, 'empty')],
                    ['type' => 'text', 'text' => self::say($locale, 'restart'), 'tone' => 'muted'],
                ]],
                ['label' => self::say($locale, 'market_heading'), 'blocks' => [
                    ['type' => 'text', 'text' => self::say($locale, 'market_note'), 'tone' => 'muted'],
                    ['type' => 'lazy', 'section' => self::MARKET, 'placeholder' => self::say($locale, 'market_loading')],
                ]],
            ]],
        ];
    }

    /**
     * The market: GitHub's repositories with the topic `pig-package`, most stars first, each with
     * Install — or marked installed when a configured package already is that repository.
     *
     * @return list<array<string, mixed>>
     */
    public function market(string $locale): array
    {
        try {
            [$response, $body] = Retrying::fetch($this->http ?? new HttpClient(15.0), new Request('GET', self::MARKET_URL, [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => PigUserAgent::get(),
            ]));

            if (!$response->isSuccessful()) {
                throw new PackageError("HTTP {$response->status}" . ($response->status === 403 ? ' (rate limit; try again in a minute)' : ''));
            }

            $answer = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            return [['type' => 'text', 'text' => self::say($locale, 'market_failed', ['error' => $error->getMessage()]), 'tone' => 'error']];
        }

        $installed = [];

        foreach ((new PackageManager($this->cwd, $this->settings(), $this->home))->listConfiguredPackages() as $package) {
            $parsed = PackageSource::parse($package['source']);

            if ($parsed instanceof GitSource) {
                $installed[strtolower("{$parsed->host}/{$parsed->path}")] = true;
            }
        }

        $items = [];

        foreach (is_array($answer['items'] ?? null) ? $answer['items'] : [] as $repo) {
            if (!is_array($repo) || !is_string($repo['full_name'] ?? null)) {
                continue;
            }

            $source = "git:github.com/{$repo['full_name']}";
            $here = isset($installed[strtolower("github.com/{$repo['full_name']}")]);
            $items[] = [
                'title' => $repo['name'] ?? $repo['full_name'],
                'detail' => trim(($repo['full_name']) . (is_string($repo['description'] ?? null) && $repo['description'] !== '' ? " · {$repo['description']}" : '')),
                'meta' => '★ ' . (int) ($repo['stargazers_count'] ?? 0) . ($here ? ' · ' . self::say($locale, 'is_installed') : ''),
                'active' => $here,
                'actions' => $here ? [] : [['label' => self::say($locale, 'install'), 'action' => 'install', 'args' => ['source' => $source]]],
            ];
        }

        return [['type' => 'list', 'items' => $items, 'empty' => self::say($locale, 'market_empty')]];
    }

    /**
     * A button: null when it worked (said with `notify()`), else what went wrong.
     *
     * @param array<string, mixed> $args
     */
    public function act(string $action, array $args, string $locale, ExtensionUi $ui): ?string
    {
        $source = is_string($args['source'] ?? null) ? $args['source'] : null;
        $local = ($args['local'] ?? false) === true ? ['-l'] : [];

        switch ($action) {
            case 'install':
                // From the market the source is the button's; the header's button asks for one.
                $source = trim($source ?? (string) $ui->input(self::say($locale, 'install'), self::say($locale, 'install_prompt')));

                if ($source === '') {
                    return null;
                }

                return $this->pig(['install', $source], $ui, self::say($locale, 'installed', ['source' => $source]));

            case 'update':
                return $this->pig(['update', ...($source === null ? ['--extensions'] : [$source])], $ui, self::say($locale, 'updated'));

            case 'remove':
                if ($source === null) {
                    return 'Which package?';
                }

                return $this->pig(['remove', $source, ...$local], $ui, self::say($locale, 'removed', ['name' => self::name($source)]));
        }

        return "No action '{$action}'.";
    }

    /**
     * Run `bin/pig <arguments>` and say how it went.
     *
     * @param list<string> $arguments
     */
    private function pig(array $arguments, ExtensionUi $ui, string $done): ?string
    {
        [$exit, , $stderr] = $this->run !== null
            ? ($this->run)($arguments, $this->cwd)
            : Process::runAsync([PHP_BINARY, RpcClient::defaultBinary(), ...$arguments], self::COMMAND_TIMEOUT, $this->cwd);

        if ($exit !== 0) {
            $lines = array_values(array_filter(array_map(trim(...), explode("\n", self::plain($stderr)))));

            return $lines === [] ? 'pig ' . implode(' ', $arguments) . " failed ({$exit})." : end($lines);
        }

        $ui->notify($done, 'info');

        return null;
    }

    /**
     * How many of each kind of resource each package brings, by scope and source as
     * `listConfiguredPackages()` names them.
     *
     * @return array<string, array<string, int>>
     */
    private function resourceCounts(PackageManager $manager): array
    {
        try {
            $resolved = $manager->resolve(static fn (): string => 'skip');
        } catch (PackageError) {
            return [];
        }

        $counts = [];

        foreach (['extensions', 'skills', 'prompts', 'themes'] as $type) {
            foreach ($resolved->of($type) as $resource) {
                if ($resource->enabled && $resource->metadata->origin === 'package') {
                    $key = $resource->metadata->scope . "\0" . $resource->metadata->source;
                    $counts[$key][$type] = ($counts[$key][$type] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }

    /** A git checkout's tag or commit; nothing for anything else. */
    private function version(string $path): ?string
    {
        if (!is_dir("{$path}/.git")) {
            return null;
        }

        [$exit, $stdout] = Process::runAsync(['git', 'describe', '--tags', '--always'], 10.0, $path);

        return $exit === 0 && trim($stdout) !== '' ? trim($stdout) : null;
    }

    /** `pig-antigravity` for `git:github.com/owner888/pig-antigravity`, `vendor/name` for Composer. */
    public static function name(string $source): string
    {
        $parsed = PackageSource::parse($source);

        return match (true) {
            $parsed instanceof ComposerSource => $parsed->name,
            $parsed instanceof GitSource => basename($parsed->path),
            default => basename(rtrim($parsed->path, '/')),
        };
    }

    /** What `pig` decides for the project at the command line: its trust as saved, else as found. */
    private function settings(): Settings
    {
        $home = $this->home ?? Config::home();
        $trusted = ProjectTrust::decision($this->cwd, $home) ?? !ProjectTrust::hasResources($this->cwd);

        return Settings::load($this->cwd, $this->home, $trusted);
    }

    /** The CLI's colours out of its messages. */
    private static function plain(string $text): string
    {
        return (string) preg_replace('/\e\[[0-9;]*m/', '', $text);
    }

    /** @param array<string, scalar> $params */
    public static function say(string $locale, string $key, array $params = []): string
    {
        $text = (str_starts_with(strtolower($locale), 'zh') ? self::STRINGS['zh-CN'] : self::STRINGS['en'])[$key];

        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }

        return $text;
    }
}
