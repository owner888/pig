<?php

declare(strict_types=1);

namespace PigAntigravity;

/**
 * The web UI's accounts panel, as blocks for `ExtensionApi::registerWebPanel()`: the sign-ins
 * `antigravity-accounts.json` keeps — which one is live, when each token runs out — with a button
 * to use, remove or rotate them, and the live account's quota pools, which need a network call and
 * so are a lazy section of their own.
 *
 * It used to be written into the page (`accounts.js`, its strings in the page's `i18n.js`); the
 * page now draws whatever an extension describes, and what is Antigravity's lives here. Its strings
 * are here too, in the two languages the page ships.
 */
final class AccountsPanel
{
    public const string ID = 'antigravity.accounts';

    /** The section the quota pools are drawn from. */
    public const string USAGE = 'usage';

    public const array TITLE = ['en' => 'Antigravity Accounts', 'zh-CN' => 'Antigravity 账号管理'];

    /** The name of its entry in the header's ⋯ menu. */
    public const array LABEL = ['en' => 'Accounts', 'zh-CN' => '账号'];

    private const array STRINGS = [
        'en' => [
            'rotate' => 'Rotate',
            'rotate_title' => 'Switch to the next account',
            'no_accounts' => 'No Antigravity accounts. Run /login antigravity in the terminal.',
            'active' => 'active',
            'use' => 'Use',
            'remove' => 'Remove',
            'remove_title' => 'Forget this account',
            'remove_confirm' => 'Forget {email}? Its tokens are removed from this machine.',
            'token_expired' => 'token expired',
            'token_left_m' => 'token {m}m left',
            'token_left_h' => 'token {h}h left',
            'quota_active' => 'Quota — Active Account',
            'quota_fetching' => 'Fetching…',
            'quota_empty_groups' => 'No quota groups returned.',
            'quota_resets_now' => 'resets now',
            'quota_resets_m' => 'resets in {m}m',
            'quota_resets_hm' => 'resets in {h}h {m}m',
            'quota_resets_d' => 'resets in {d}d',
            'not_signed_in' => 'No Antigravity account is signed in.',
            'no_such_account' => "No account called '{id}'.",
            'nothing_to_rotate' => 'Only one Antigravity account; nothing to rotate to.',
            'gemini models' => 'Gemini Models',
            'claude and gpt models' => 'Claude and GPT models',
            'claude models' => 'Claude Models',
            'gpt models' => 'GPT Models',
            'weekly limit remaining' => 'Weekly Limit Remaining',
            'five hour limit remaining' => 'Five Hour Limit Remaining',
            'daily limit remaining' => 'Daily Limit Remaining',
            'hourly limit remaining' => 'Hourly Limit Remaining',
            'monthly limit remaining' => 'Monthly Limit Remaining',
        ],
        'zh-CN' => [
            'rotate' => '轮换',
            'rotate_title' => '切换到下一个可用账号',
            'no_accounts' => '暂无 Antigravity 账号。请在终端执行 /login antigravity。',
            'active' => '使用中',
            'use' => '启用',
            'remove' => '移除',
            'remove_title' => '从本机移除此账号',
            'remove_confirm' => '确定要移除账号 {email} 吗？其登录凭据将从本机移除。',
            'token_expired' => '令牌已过期',
            'token_left_m' => '令牌剩余 {m} 分钟',
            'token_left_h' => '令牌剩余 {h} 小时',
            'quota_active' => '配额 — 当前使用中账号',
            'quota_fetching' => '正在获取配额…',
            'quota_empty_groups' => '未返回配额信息。',
            'quota_resets_now' => '立即重置',
            'quota_resets_m' => '{m} 分钟后重置',
            'quota_resets_hm' => '{h} 小时 {m} 分后重置',
            'quota_resets_d' => '{d} 天后重置',
            'not_signed_in' => '没有已登录的 Antigravity 账号。',
            'no_such_account' => '没有名为 {id} 的账号。',
            'nothing_to_rotate' => '只有一个 Antigravity 账号，无可轮换。',
            'gemini models' => 'Gemini 模型',
            'claude and gpt models' => 'Claude 与 GPT 模型',
            'claude models' => 'Claude 模型',
            'gpt models' => 'GPT 模型',
            'weekly limit remaining' => '周限额剩余',
            'five hour limit remaining' => '5 小时限额剩余',
            'daily limit remaining' => '日限额剩余',
            'hourly limit remaining' => '小时限额剩余',
            'monthly limit remaining' => '月限额剩余',
        ],
    ];

    /** Quota names the service spells two ways. */
    private const array SAME_NAME = [
        'claude & gpt models' => 'claude and gpt models',
        '5 hour limit remaining' => 'five hour limit remaining',
        '24 hour limit remaining' => 'daily limit remaining',
        '1 hour limit remaining' => 'hourly limit remaining',
    ];

    /** A string in `$locale` — Chinese for any `zh`, else English — with its `{params}` filled in. */
    public static function say(string $locale, string $key, array $params = []): string
    {
        $strings = str_starts_with(strtolower($locale), 'zh') ? self::STRINGS['zh-CN'] : self::STRINGS['en'];
        $text = $strings[$key] ?? self::STRINGS['en'][$key] ?? $key;

        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }

        return $text;
    }

    /**
     * The accounts, the rotate button, and the quota section to be filled in.
     *
     * @param list<array{id: string, email: string, projectId: ?string, expires: ?int, active: bool}> $rows
     * @param list<string> $problems what the store could not read
     * @return list<array<string, mixed>>
     */
    public static function blocks(array $rows, array $problems, string $locale, ?int $nowMs = null): array
    {
        $nowMs ??= (int) (microtime(true) * 1000);
        $blocks = [];

        if (count($rows) > 1) {
            $blocks[] = ['type' => 'actions', 'actions' => [
                ['label' => self::say($locale, 'rotate'), 'action' => 'rotate', 'title' => self::say($locale, 'rotate_title')],
            ]];
        }

        $blocks[] = [
            'type' => 'list',
            'empty' => self::say($locale, 'no_accounts'),
            'items' => array_map(static function (array $row) use ($locale, $nowMs): array {
                $expired = $row['expires'] !== null && $row['expires'] <= $nowMs;

                return [
                    'title' => $row['email'],
                    'detail' => $row['active'] ? self::say($locale, 'active') : null,
                    'meta' => self::expiryLabel($row['expires'], $locale, $nowMs),
                    'tone' => $expired ? 'error' : 'muted',
                    'active' => $row['active'],
                    'actions' => [
                        ...($row['active'] ? [] : [['label' => self::say($locale, 'use'), 'action' => 'activate', 'args' => ['id' => $row['id']]]]),
                        [
                            'label' => self::say($locale, 'remove'),
                            'action' => 'remove',
                            'args' => ['id' => $row['id']],
                            'tone' => 'danger',
                            'title' => self::say($locale, 'remove_title'),
                            'confirm' => self::say($locale, 'remove_confirm', ['email' => $row['email']]),
                        ],
                    ],
                ];
            }, $rows),
        ];

        foreach ($problems as $problem) {
            $blocks[] = ['type' => 'text', 'text' => $problem, 'tone' => 'error'];
        }

        if ($rows !== []) {
            $blocks[] = ['type' => 'heading', 'text' => self::say($locale, 'quota_active')];
            $blocks[] = ['type' => 'lazy', 'section' => self::USAGE, 'placeholder' => self::say($locale, 'quota_fetching')];
        }

        return $blocks;
    }

    /**
     * `QuotaClient::fetchUsage()`'s answer: the plan, then a card of meters per pool.
     *
     * @param array{planLabel?: ?string, groups?: list<array{displayName: string, buckets: list<array{displayName: string, remainingFraction: float, resetTime: ?string}>}>, quotaError?: ?string} $usage
     * @return list<array<string, mixed>>
     */
    public static function usageBlocks(array $usage, string $locale, ?int $nowMs = null): array
    {
        $nowMs ??= (int) (microtime(true) * 1000);
        $blocks = [];

        if (($usage['planLabel'] ?? null) !== null && $usage['planLabel'] !== '') {
            $blocks[] = ['type' => 'text', 'text' => $usage['planLabel'], 'tone' => 'muted'];
        }

        $groups = $usage['groups'] ?? [];

        if ($groups === []) {
            $blocks[] = ['type' => 'text', 'text' => $usage['quotaError'] ?? self::say($locale, 'quota_empty_groups'), 'tone' => 'muted'];

            return $blocks;
        }

        foreach ($groups as $group) {
            $blocks[] = ['type' => 'card', 'title' => self::quotaName($group['displayName'], $locale), 'blocks' => array_map(
                static fn (array $bucket): array => [
                    'type' => 'meter',
                    'label' => self::quotaName($bucket['displayName'], $locale),
                    'value' => $bucket['remainingFraction'],
                    'detail' => self::resetLabel($bucket['resetTime'] ?? null, $locale, $nowMs),
                ],
                $group['buckets'],
            )];
        }

        return $blocks;
    }

    /** How long the token has, as the old page said it: minutes under an hour, else hours. */
    public static function expiryLabel(?int $expiresMs, string $locale, int $nowMs): ?string
    {
        if ($expiresMs === null || $expiresMs === 0) {
            return null;
        }

        $left = $expiresMs - $nowMs;

        if ($left <= 0) {
            return self::say($locale, 'token_expired');
        }

        $minutes = (int) round($left / 60000);

        return $minutes < 60
            ? self::say($locale, 'token_left_m', ['m' => $minutes])
            : self::say($locale, 'token_left_h', ['h' => (int) round($minutes / 60)]);
    }

    /** When a pool fills again: now, minutes, hours and minutes under two days, else days. */
    public static function resetLabel(?string $iso, string $locale, int $nowMs): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }

        $at = strtotime($iso);

        if ($at === false) {
            return $iso;
        }

        $left = $at * 1000 - $nowMs;

        if ($left <= 0) {
            return self::say($locale, 'quota_resets_now');
        }

        $minutes = (int) round($left / 60000);

        if ($minutes < 60) {
            return self::say($locale, 'quota_resets_m', ['m' => $minutes]);
        }

        $hours = intdiv($minutes, 60);

        return $hours < 48
            ? self::say($locale, 'quota_resets_hm', ['h' => $hours, 'm' => $minutes % 60])
            : self::say($locale, 'quota_resets_d', ['d' => (int) round($hours / 24)]);
    }

    /** A pool's name in `$locale` when it is one the panel knows, else as the service sent it. */
    public static function quotaName(string $name, string $locale): string
    {
        $key = strtolower(trim($name));
        $key = self::SAME_NAME[$key] ?? $key;

        return isset(self::STRINGS['en'][$key]) ? self::say($locale, $key) : trim($name);
    }
}
