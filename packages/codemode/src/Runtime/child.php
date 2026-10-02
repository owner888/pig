<?php

/**
 * The codemode sandbox — the child process a script runs in. Upstream's `prelude-source.ts`
 * (what the script sees) and `worker.ts` (the bridge) in one file, because there is one process
 * instead of a worker holding a VM.
 *
 * Started by `Sandbox` as `php -n -d disable_functions=… -d open_basedir=… -d memory_limit=… child.php`,
 * so the script has no shell, no filesystem, no sockets and a bounded heap; everything it can do
 * besides compute goes over the pipe. **This file must stay self-contained**: no autoloader, no
 * `require`, because `open_basedir` forbids reaching the repository and that is the point.
 *
 * Wire, one JSON object per line. Host → child: `{"boot": {...}}` first, then `{"result": {id,
 * ok, value|error}}`. Child → host: `{"call": {id, name, args}}`, `{"global": {id, name, args}}`,
 * `{"output": {kind, text|data, mimeType}}`, `{"done": {ok, value|error, writes}}`.
 *
 * `strict_types` is deliberately off: the script is the model's, and a `1` where a `1.0` was
 * declared is not what a sandbox should refuse over.
 */

declare(ticks=1);

// `display_errors` is off on the command line already: `ini_set` is among the disabled functions,
// as it has to be, or the script could turn the sandbox off from inside.
error_reporting(E_ALL);

final class __CodemodeBridge
{
    /** @var resource */
    private static $out;

    private static int $nextId = 1;

    private static bool $finished = false;

    /** @var array<string, mixed> */
    public static array $store = [];

    /** @var array<string, mixed> */
    public static array $storeSet = [];

    /** @var list<string> */
    public static array $storeDeleted = [];

    public const int MAX_STORE_VALUE_CHARS = 256 * 1024;

    public const int MAX_STORE_TOTAL_CHARS = 1024 * 1024;

    public static function init(): void
    {
        self::$out = STDOUT;
    }

    public static function send(array $message): void
    {
        fwrite(self::$out, json_encode($message, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
        fflush(self::$out);
    }

    /** @var array<int, array{ok: bool, value?: mixed, error?: string}> answers that arrived for a fiber not yet asking */
    private static array $arrived = [];

    /**
     * Ask the host to run something and wait for the answer. The pipe is the only clock.
     *
     * Inside a `parallel()` arm the wait is a `Fiber::suspend()`, so the driver can send the other
     * arms' calls before any answer comes back; at the top level it reads the pipe directly.
     */
    public static function ask(string $kind, string $name, mixed $args): mixed
    {
        $id = self::$nextId++;
        self::send([$kind => ['id' => $id, 'name' => $name, 'args' => $args]]);

        $result = Fiber::getCurrent() !== null && __CodemodeParallel::driving()
            ? Fiber::suspend($id)
            : self::awaitResult($id);

        if (($result['ok'] ?? false) === true) {
            return $result['value'] ?? null;
        }

        throw new __CodemodeToolError((string) ($result['error'] ?? 'tool call failed'), (string) $name);
    }

    /** Read the pipe until the answer to `$id` is there; answers to other ids are kept. */
    public static function awaitResult(int $id): array
    {
        if (isset(self::$arrived[$id])) {
            $result = self::$arrived[$id];
            unset(self::$arrived[$id]);

            return $result;
        }

        while (($line = fgets(STDIN)) !== false) {
            $message = json_decode($line, true);

            if (!is_array($message) || !isset($message['result'])) {
                continue;
            }

            $result = $message['result'];
            $for = (int) ($result['id'] ?? 0);

            if ($for === $id) {
                return $result;
            }

            self::$arrived[$for] = $result;
        }

        // The host went away: nothing can ever answer, so stop here.
        exit(0);
    }

    /** The next answer to arrive, whoever it is for. */
    public static function nextResult(): ?array
    {
        if (self::$arrived !== []) {
            $id = array_key_first(self::$arrived);
            $result = self::$arrived[$id];
            unset(self::$arrived[$id]);

            return $result;
        }

        while (($line = fgets(STDIN)) !== false) {
            $message = json_decode($line, true);

            if (is_array($message) && isset($message['result'])) {
                return $message['result'];
            }
        }

        return null;
    }

    public static function done(bool $ok, mixed $payload): void
    {
        if (self::$finished) {
            return;
        }

        self::$finished = true;
        self::send(['done' => [
            'ok' => $ok,
            ...($ok ? ['value' => $payload] : ['error' => $payload]),
            'writes' => ['set' => (object) self::$storeSet, 'delete' => self::$storeDeleted],
        ]]);
    }

    public static function finished(): bool
    {
        return self::$finished;
    }

    public static function describeError(Throwable $error): array
    {
        $frames = self::frames($error);

        return [
            'name' => $error::class,
            'message' => $error->getMessage(),
            'stack' => $error::class . ': ' . $error->getMessage() . ' in script line ' . self::scriptLine($error) . ($frames !== '' ? "\n{$frames}" : ''),
        ];
    }

    private static function scriptLine(Throwable $error): int
    {
        // Thrown from the script itself: its file is the eval'd one. Thrown from the prelude on
        // the script's behalf (an unknown tool): the first script frame below is the line.
        if (str_contains($error->getFile(), 'eval()')) {
            return $error->getLine();
        }

        foreach ($error->getTrace() as $frame) {
            if (str_contains((string) ($frame['file'] ?? ''), 'eval()')) {
                return (int) ($frame['line'] ?? 0);
            }
        }

        return $error->getLine();
    }

    private static function frames(Throwable $error): string
    {
        $lines = [];

        foreach ($error->getTrace() as $frame) {
            $file = (string) ($frame['file'] ?? '');

            if (!str_contains($file, 'eval()')) {
                continue;
            }

            // The prelude's own frames (`__CodemodeTools->__call`, the closure the script is the
            // body of) are plumbing; a script-level `{closure}` is named as the script.
            $function = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');

            if (str_starts_with($function, '__Codemode')) {
                continue;
            }

            $function = preg_replace('/^\{closure:.*child\.php:\d+\}$/', '{script}', $function) ?? $function;
            $lines[] = "    at {$function} (script line " . ($frame['line'] ?? '?') . ')';
        }

        return implode("\n", $lines);
    }
}

/** A nested tool call that failed, was blocked, or got invalid arguments. */
final class __CodemodeToolError extends RuntimeException
{
    public function __construct(string $message, public readonly string $tool)
    {
        parent::__construct($message);
    }
}

/** Thrown by `exit_script()` to unwind the script after it already reported success. */
final class __CodemodeExit extends Exception
{
}

/**
 * What `$tools` is: every nested tool as a method, under its identifier and its raw name.
 * A call sends the arguments to the host and blocks until the result is back.
 */
final class __CodemodeTools
{
    /** @var array<string, string> identifier or raw name => raw name */
    private array $names = [];

    public function __construct(array $tools)
    {
        foreach ($tools as $tool) {
            $this->names[$tool['phpName']] ??= $tool['name'];
            $this->names[$tool['name']] ??= $tool['name'];
        }
    }

    public function __call(string $name, array $arguments): mixed
    {
        $raw = $this->names[$name] ?? null;

        if ($raw === null) {
            throw new Error($this->noSuchTool($name));
        }

        $input = $arguments[0] ?? [];

        if (!is_array($input)) {
            throw new TypeError("Tool \"{$name}\" takes one array argument");
        }

        return __CodemodeBridge::ask('call', $raw, $input === [] ? new stdClass() : $input);
    }

    public function has(string $name): bool
    {
        return isset($this->names[$name]);
    }

    /**
     * An error that says how to recover — upstream's `guard()` proxy, which turns `tools.Bash` into
     * "Did you mean tools.bash?" instead of a later "not a function". Names are compared with case
     * and punctuation removed, so `$tools->readTextFile()` finds `read_text_file`; failing an exact
     * match, a name that contains or is contained by what was typed; failing that, the whole list
     * when it is short enough to read.
     */
    private function noSuchTool(string $name): string
    {
        $comparable = static fn (string $n): string => preg_replace('/[^a-z0-9]/', '', strtolower($n)) ?? '';
        $wanted = $comparable($name);
        $names = array_values(array_unique(array_values($this->names)));
        $exact = array_values(array_filter($names, static fn (string $n): bool => $comparable($n) === $wanted));
        $close = $exact !== [] ? $exact : array_values(array_filter($names, static fn (string $n): bool => $wanted !== '' && (str_contains($comparable($n), $wanted) || str_contains($wanted, $comparable($n)))));

        $message = "\$tools->{$name}() does not exist.";

        if ($close !== []) {
            $message .= ' Did you mean ' . implode(', ', array_map(static fn (string $n): string => '$tools->' . $n . '()', array_slice($close, 0, 5))) . '?';
        } elseif (count($names) <= 20) {
            $message .= ' Available: ' . implode(', ', $names) . '.';
        }

        return $message . ' ALL_TOOLS lists every tool; search_tools($query) finds tools by topic. Check with $tools->has(\'name\').';
    }
}

// ---- the helpers the script sees ------------------------------------------------------------

/** Appends a text item. Non-strings are JSON-encoded. */
function text(mixed $value): void
{
    __CodemodeBridge::send(['output' => ['kind' => 'text', 'text' => __codemode_format($value)]]);
}

/** Appends an image: a base64 `data:` URL, `['image_url' => ...]`, or an MCP image block. */
function image(mixed $value): void
{
    $url = null;
    $data = null;
    $mime = null;

    if (is_string($value)) {
        $url = $value;
    } elseif (is_array($value)) {
        if (isset($value['image_url']) && is_string($value['image_url'])) {
            $url = $value['image_url'];
        } elseif (($value['type'] ?? null) === 'image' && is_string($value['data'] ?? null)) {
            $data = $value['data'];
            $mime = (string) ($value['mimeType'] ?? 'image/png');
        }
    }

    if ($url !== null && $url !== '') {
        if (preg_match('#^data:([^;,]+);base64,(.+)$#s', $url, $m) !== 1) {
            throw new TypeError('image expects a base64 data: URL');
        }

        [$mime, $data] = [$m[1], $m[2]];
    }

    if ($data === null || $data === '') {
        throw new TypeError('image expects a non-empty image URL string, an array with image_url, or a raw MCP image block');
    }

    __CodemodeBridge::send(['output' => ['kind' => 'image', 'data' => $data, 'mimeType' => $mime]]);
}

/** Immediately ends the current script successfully (like an early return from the top level). */
function exit_script(): never
{
    __CodemodeBridge::done(true, null);

    throw new __CodemodeExit();
}

/** Stores a JSON-serializable value under a key for later codemode calls in the same session. Null deletes. */
function store(string $key, mixed $value): void
{
    if ($value === null) {
        unset(__CodemodeBridge::$store[$key], __CodemodeBridge::$storeSet[$key]);

        if (!in_array($key, __CodemodeBridge::$storeDeleted, true)) {
            __CodemodeBridge::$storeDeleted[] = $key;
        }

        return;
    }

    $json = json_encode($value, JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        throw new TypeError("store(): the value for \"{$key}\" is not JSON-serializable: " . json_last_error_msg());
    }

    // What the store is for, said when the limit is hit — a model that stored an image or a
    // whole file sees why rather than a number.
    $hint = 'store() is for small state such as IDs or summaries. Show images with image(), keep large data in variables, or write it to a file with a tool.';

    if (strlen($json) > __CodemodeBridge::MAX_STORE_VALUE_CHARS) {
        throw new LengthException("store(\"{$key}\") value has " . strlen($json) . ' characters of JSON, more than the limit of ' . __CodemodeBridge::MAX_STORE_VALUE_CHARS . '. ' . $hint);
    }

    $total = strlen($json);

    foreach (__CodemodeBridge::$store as $k => $v) {
        if ($k !== $key) {
            $total += strlen((string) json_encode($v));
        }
    }

    if ($total > __CodemodeBridge::MAX_STORE_TOTAL_CHARS) {
        throw new LengthException('store is full: stored values would exceed ' . __CodemodeBridge::MAX_STORE_TOTAL_CHARS . ' characters of JSON. Delete keys with store($key, null). ' . $hint);
    }

    $decoded = json_decode($json, true);
    __CodemodeBridge::$store[$key] = $decoded;
    __CodemodeBridge::$storeSet[$key] = $decoded;
    __CodemodeBridge::$storeDeleted = array_values(array_filter(__CodemodeBridge::$storeDeleted, static fn (string $k): bool => $k !== $key));
}

/** The stored value for a key, or null. */
function load(string $key): mixed
{
    return __CodemodeBridge::$store[$key] ?? null;
}

/** The nested tools that best match the query (BM25, default limit 8), as `['name' => ..., 'description' => ...]`. */
function search_tools(string $query, array $options = []): array
{
    return __CodemodeBridge::ask('global', 'search_tools', [$query, $options]);
}

/** The description and declaration of a nested tool, or null. */
function describe_tool(string $name): ?string
{
    return __CodemodeBridge::ask('global', 'describe_tool', [$name]);
}

/**
 * Run several nested tool calls at once and answer their results in order — the script's
 * `Promise.all`. Each item is a closure; one that throws makes the whole call throw. The host runs
 * them concurrently (they are I/O), so three slow tools cost one slow tool.
 *
 * @param list<Closure(): mixed> $calls
 */
function parallel(array $calls): array
{
    return __codemode_parallel($calls, false);
}

/**
 * Like `parallel()`, but a failure does not stop the rest: each item is
 * `['ok' => true, 'value' => ...]` or `['ok' => false, 'error' => '...']` — `Promise.allSettled`.
 *
 * @param list<Closure(): mixed> $calls
 */
function parallel_settled(array $calls): array
{
    return __codemode_parallel($calls, true);
}

function __codemode_parallel(array $calls, bool $settled): array
{
    // Keys are kept: `parallel(['a' => fn () => …, 'b' => fn () => …])` answers `['a' => …, 'b' => …]`.
    // `Promise.all` takes a list and has no such case; a PHP array has keys, and the first live
    // script written against this used them.
    return __CodemodeParallel::run($calls, $settled);
}

/**
 * The fan-out: every arm is a `Fiber`, started in order. An arm that calls a tool suspends with
 * the request already on the pipe; once every arm is started or finished, answers are read off
 * the pipe and each resumes the arm waiting for it. So the host sees all the calls before it has
 * answered any — which is what lets it run them at once.
 */
final class __CodemodeParallel
{
    private static int $depth = 0;

    public static function driving(): bool
    {
        return self::$depth > 0;
    }

    public static function run(array $calls, bool $settled): array
    {
        foreach ($calls as $call) {
            if (!$call instanceof Closure) {
                throw new TypeError('parallel() expects a list of closures');
            }
        }

        self::$depth++;
        /** @var array<int, Fiber> arms still running, by the id they wait for */
        $waiting = [];
        /** @var array<int, array{ok: bool, value?: mixed, error?: string}> by arm index */
        $results = [];
        /** @var array<int, int|string> request id => arm key */
        $arms = [];

        $settle = static function (int|string $index, Fiber $fiber, mixed $resumeWith) use (&$waiting, &$results, &$arms, $settled): void {
            try {
                $suspended = $resumeWith === null ? $fiber->start() : $fiber->resume($resumeWith);
            } catch (__CodemodeExit $exit) {
                throw $exit;
            } catch (Throwable $error) {
                if (!$settled) {
                    throw $error;
                }

                $results[$index] = ['ok' => false, 'error' => $error->getMessage()];

                return;
            }

            if ($fiber->isTerminated()) {
                $results[$index] = ['ok' => true, 'value' => $fiber->getReturn()];

                return;
            }

            // Suspended on a request id: remember who is waiting for it.
            $waiting[(int) $suspended] = $fiber;
            $arms[(int) $suspended] = $index;
        };

        try {
            foreach ($calls as $index => $call) {
                $settle($index, new Fiber($call), null);
            }

            while ($waiting !== []) {
                $result = __CodemodeBridge::nextResult();

                if ($result === null) {
                    exit(0);   // the host went away
                }

                $id = (int) ($result['id'] ?? 0);
                $fiber = $waiting[$id] ?? null;

                if ($fiber === null) {
                    continue;   // an answer for somebody outside this fan-out; cannot happen while we drive
                }

                $index = $arms[$id];
                unset($waiting[$id], $arms[$id]);
                $settle($index, $fiber, $result);
            }
        } finally {
            self::$depth--;
        }

        // Back in the caller's order and under the caller's keys.
        $ordered = [];

        foreach (array_keys($calls) as $key) {
            $ordered[$key] = $settled ? $results[$key] : $results[$key]['value'];
        }

        return $ordered;
    }
}

function __codemode_format(mixed $value): string
{
    if (is_string($value)) {
        return $value;
    }

    if ($value instanceof Throwable) {
        return $value::class . ': ' . $value->getMessage();
    }

    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

    return $json === false ? var_export($value, true) : $json;
}

// ---- boot ------------------------------------------------------------------------------------

__CodemodeBridge::init();

$bootLine = fgets(STDIN);
$boot = is_string($bootLine) ? json_decode($bootLine, true) : null;

if (!is_array($boot) || !isset($boot['boot'])) {
    __CodemodeBridge::send(['done' => ['ok' => false, 'error' => ['kind' => 'sandbox', 'message' => 'no boot message'], 'writes' => ['set' => new stdClass(), 'delete' => []]]]);
    exit(1);
}

$boot = $boot['boot'];
__CodemodeBridge::$store = is_array($boot['store'] ?? null) ? $boot['store'] : [];
$tools = new __CodemodeTools(is_array($boot['tools'] ?? null) ? $boot['tools'] : []);

define('ALL_TOOLS', array_map(
    static fn (array $tool): array => ['name' => $tool['phpName'], 'description' => (string) ($tool['description'] ?? '')],
    is_array($boot['tools'] ?? null) ? $boot['tools'] : [],
));

// Output that is not ours — an `echo`, a warning — is output too, as `console.log` is upstream.
ob_start(static function (string $buffer): string {
    if ($buffer !== '') {
        __CodemodeBridge::send(['output' => ['kind' => 'text', 'text' => rtrim($buffer, "\n")]]);
    }

    return '';
}, 1);

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return true;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(static function (): void {
    $error = error_get_last();

    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) && !__CodemodeBridge::finished()) {
        $message = $error['message'];
        $kind = str_contains($message, 'Allowed memory size') ? 'memory' : 'script';
        __CodemodeBridge::done(false, ['kind' => $kind, 'name' => 'Error', 'message' => $message, 'stack' => "Error: {$message} in script line {$error['line']}"]);
    }

    while (ob_get_level() > 0) {
        ob_end_flush();
    }
});

$code = (string) ($boot['code'] ?? '');
// The script is the body of a function: `return` works at the top level, and `$tools` is in scope.
$body = preg_replace('/^\s*<\?php\s*/', '', $code) ?? $code;

try {
    $run = eval('return static function ($tools) { ' . $body . "\n};");
    $value = $run($tools);
    ob_get_level() > 0 && ob_flush();
    __CodemodeBridge::done(true, $value);
} catch (__CodemodeExit) {
    ob_get_level() > 0 && ob_flush();
    // Already reported.
} catch (Throwable $error) {
    ob_get_level() > 0 && ob_flush();
    __CodemodeBridge::done(false, ['kind' => 'script', ...__CodemodeBridge::describeError($error)]);
}

exit(0);
