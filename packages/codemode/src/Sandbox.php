<?php

declare(strict_types=1);

namespace Pig\Codemode;

use Closure;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Pig\Async\Deferred;
use Pig\Async\Loop;
use Pig\Tui\Process;
use RuntimeException;

/**
 * Runs one script in a child `php` with nothing but the pipe — upstream's `CodemodeSandbox`
 * (`runtime/host.ts`), with a process where it has a worker holding a QuickJS VM.
 *
 * **What the isolation is, stated rather than implied.** The child is started with `php -n`
 * (no `php.ini`), `disable_functions` naming every way out of the process — shells, sockets,
 * files, `ini_set`, `dl`, `eval`-adjacent loaders — `open_basedir` pointing nowhere, and a
 * `memory_limit`. That is a blacklist, and upstream's wasm is a whitelist: a function this list
 * forgot is a function the script can call. The list is the conservative union of PHP's
 * process, file, stream and network families; what it buys is zero dependencies, and what it
 * costs is that this is a *sandbox for a model's mistakes*, not for an adversary's. The docblock
 * on `DISABLED_FUNCTIONS` is where to add to it.
 *
 * Everything the script asks for comes over the pipe as one JSON line: a tool call, a global
 * (`search_tools`, `describe_tool`), an output item. The host answers each `call`/`global` in a
 * fiber of its own, so a script's `parallel([...])` really is concurrent.
 */
final class Sandbox
{
    /** Heap limit for the child; upstream's 256MB for the VM. */
    public const int DEFAULT_MEMORY_LIMIT_BYTES = 256 * 1024 * 1024;

    /**
     * Every way out of the process that PHP core and the usual extensions offer. Grouped, so an
     * addition goes in the right row. `eval` is a language construct and cannot be disabled —
     * the child uses it to run the script — and `include`/`require` are constructs too, which
     * is what `open_basedir` is for.
     */
    public const array DISABLED_FUNCTIONS = [
        // processes
        'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'proc_close', 'proc_terminate', 'proc_get_status', 'proc_nice',
        'popen', 'pclose', 'pcntl_exec', 'pcntl_fork', 'pcntl_signal', 'pcntl_alarm', 'posix_kill', 'posix_setuid', 'posix_setgid',
        'putenv', 'apache_setenv', 'mail', 'dl', 'ini_set', 'ini_alter', 'ini_restore', 'set_include_path', 'set_time_limit',
        'ignore_user_abort', 'register_tick_function', 'pcntl_async_signals',
        // files and directories
        'fopen', 'file_get_contents', 'file_put_contents', 'file', 'readfile', 'fpassthru', 'unlink', 'rename', 'copy', 'mkdir', 'rmdir',
        'tempnam', 'tmpfile', 'touch', 'chmod', 'chown', 'chgrp', 'symlink', 'link', 'readlink', 'realpath', 'glob', 'scandir', 'opendir',
        'readdir', 'rewinddir', 'closedir', 'dir', 'chdir', 'chroot', 'getcwd', 'is_file', 'is_dir', 'is_link', 'is_readable', 'is_writable',
        'is_writeable', 'is_executable', 'file_exists', 'filesize', 'filemtime', 'fileatime', 'filectime', 'fileperms', 'fileowner',
        'filegroup', 'fileinode', 'filetype', 'stat', 'lstat', 'disk_free_space', 'disk_total_space', 'diskfreespace', 'parse_ini_file',
        'highlight_file', 'show_source', 'php_strip_whitespace', 'move_uploaded_file', 'is_uploaded_file', 'fdatasync', 'fsync',
        'ftruncate', 'flock', 'fnmatch', 'umask', 'clearstatcache', 'linkinfo', 'lchown', 'lchgrp',
        // streams, sockets and the network
        'fsockopen', 'pfsockopen', 'stream_socket_client', 'stream_socket_server', 'stream_socket_accept', 'stream_socket_pair',
        'stream_select', 'stream_context_create', 'stream_context_set_option', 'stream_context_set_params', 'stream_context_get_default',
        'stream_context_set_default', 'stream_wrapper_register', 'stream_wrapper_restore', 'stream_wrapper_unregister', 'stream_filter_register',
        'stream_filter_append', 'stream_filter_prepend', 'stream_set_blocking', 'stream_set_timeout', 'stream_get_contents',
        'socket_create', 'socket_connect', 'socket_bind', 'socket_listen', 'socket_accept', 'socket_create_listen', 'socket_create_pair',
        'socket_import_stream', 'socket_export_stream', 'curl_init', 'curl_exec', 'curl_multi_init', 'curl_multi_exec', 'curl_multi_add_handle',
        'curl_setopt', 'curl_setopt_array', 'curl_copy_handle', 'gethostbyname', 'gethostbynamel', 'gethostbyaddr', 'gethostname',
        'dns_get_record', 'dns_check_record', 'dns_get_mx', 'checkdnsrr', 'getmxrr', 'net_get_interfaces', 'header', 'headers_sent',
        'setcookie', 'setrawcookie', 'http_response_code',
        // loading code and reading the host
        'opcache_compile_file', 'opcache_invalidate', 'opcache_reset', 'get_cfg_var', 'php_ini_loaded_file', 'php_ini_scanned_files',
        'getenv', 'phpinfo', 'php_uname', 'getmypid', 'getmyuid', 'getmygid', 'getmyinode', 'getlastmod', 'get_current_user', 'sys_get_temp_dir',
        'posix_getpwuid', 'posix_getpwnam', 'posix_getgrgid', 'posix_getgrnam', 'posix_uname', 'posix_getcwd', 'posix_getlogin', 'posix_ttyname',
        'posix_getpid', 'posix_getppid', 'posix_mkfifo', 'posix_mknod', 'posix_access', 'ftp_connect', 'ftp_ssl_connect', 'ldap_connect',
        'imap_open', 'pg_connect', 'pg_pconnect', 'mysqli_connect', 'mysqli_real_connect', 'mysqli_init', 'odbc_connect', 'odbc_pconnect',
        'dba_open', 'dba_popen', 'shmop_open', 'shm_attach', 'sem_get', 'msg_get_queue', 'ftok', 'posix_setsid', 'posix_setpgid',
        'leak', 'syslog', 'openlog', 'error_log', 'set_exception_handler', 'debug_zval_refcount', 'gc_collect_cycles',
    ];

    /**
     * @var list<array{name: string, phpName: string, description: string, execute: Closure(array, AbortSignal): mixed}>
     */
    private array $tools;

    /** @var array<string, Closure(array, AbortSignal): mixed> */
    private array $globals;

    /**
     * @param list<array{name: string, description?: string, execute: Closure(array, AbortSignal): mixed}> $tools
     * @param array<string, Closure(array, AbortSignal): mixed> $globals `search_tools`, `describe_tool`
     */
    public function __construct(
        array $tools,
        array $globals = [],
        private readonly ?float $timeout = null,
        private readonly int $memoryLimitBytes = self::DEFAULT_MEMORY_LIMIT_BYTES,
        private readonly ?string $phpBinary = null,
    ) {
        $this->tools = [];

        foreach ($tools as $tool) {
            $this->tools[] = [
                'name' => $tool['name'],
                'phpName' => Identifier::of($tool['name']),
                'description' => (string) ($tool['description'] ?? ''),
                'execute' => $tool['execute'],
            ];
        }

        $this->globals = $globals;
    }

    public static function childPath(): string
    {
        return __DIR__ . '/Runtime/child.php';
    }

    /** The child's command line, for tests and for `/doctor` to show what the isolation is. */
    public function command(): array
    {
        return [
            $this->phpBinary ?? PHP_BINARY,
            '-d', 'disable_functions=' . implode(',', self::DISABLED_FUNCTIONS),
            '-d', 'open_basedir=' . self::childPath(),
            '-d', 'memory_limit=' . $this->memoryLimitBytes,
            '-d', 'max_execution_time=0',
            '-d', 'display_errors=0',
            '-d', 'html_errors=0',
            '-d', 'allow_url_fopen=0',
            '-d', 'allow_url_include=0',
            '-d', 'disable_classes=',
            '-d', 'opcache.enable_cli=0',
            self::childPath(),
        ];
    }

    /**
     * Run `$code`. Answers upstream's shape: `ok`, `output` (text and image items in order),
     * `value` (what the script returned), `storeWrites`, or `error` with a `kind`
     * (`script`, `timeout`, `aborted`, `memory`, `sandbox`).
     *
     * @param array<string, mixed> $store what `load()` sees
     * @return array{ok: bool, output: list<array{type: 'text', text: string}|array{type: 'image', data: string, mimeType: string}>, value?: mixed, storeWrites: array{set: array<string, mixed>, delete: list<string>}, error?: array{kind: string, name?: string, message: string, stack?: string}}
     */
    public function execute(string $code, ?AbortSignal $signal = null, array $store = []): array
    {
        $signal?->throwIfAborted();

        $pipes = [];
        $problem = null;
        set_error_handler(static function (int $no, string $message) use (&$problem): bool {
            $problem = $message;

            return true;
        });

        try {
            $process = proc_open($this->command(), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PATH' => '']);
        } finally {
            restore_error_handler();
        }

        if (!is_resource($process)) {
            return $this->failed('sandbox', 'Could not start the sandbox: ' . ($problem ?? 'proc_open failed'));
        }

        [$stdin, $stdout, $stderr] = $pipes;
        stream_set_blocking($stdout, false);
        stream_set_blocking($stderr, false);

        $done = new Deferred();
        $output = [];
        $storeWrites = ['set' => [], 'delete' => []];
        $stderrText = '';
        $buffer = '';
        $loop = Loop::get();
        $pid = (int) (proc_get_status($process)['pid'] ?? 0);

        $finish = static function (array $result) use ($done): void {
            if (!$done->isComplete()) {
                $done->complete($result);
            }
        };

        $write = static function (array $message) use ($stdin): void {
            if (is_resource($stdin)) {
                fwrite($stdin, json_encode($message, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
                fflush($stdin);
            }
        };

        $answer = function (string $kind, array $request) use ($write, $signal): void {
            $id = (int) ($request['id'] ?? 0);
            $name = (string) ($request['name'] ?? '');
            $args = $request['args'] ?? [];

            // Each request in a fiber of its own: that is what makes `parallel()` parallel.
            Async::spawn(function () use ($kind, $id, $name, $args, $write, $signal): void {
                try {
                    $value = $kind === 'call'
                        ? $this->callTool($name, is_array($args) ? $args : [], $signal)
                        : $this->callGlobal($name, is_array($args) ? $args : [], $signal);
                    $write(['result' => ['id' => $id, 'ok' => true, 'value' => $value]]);
                } catch (\Throwable $error) {
                    $write(['result' => ['id' => $id, 'ok' => false, 'error' => $error->getMessage()]]);
                }
            });
        };

        $handleLine = function (string $line) use (&$output, &$storeWrites, $answer, $finish): void {
            $message = json_decode($line, true);

            if (!is_array($message)) {
                return;
            }

            if (isset($message['call'])) {
                $answer('call', $message['call']);
            } elseif (isset($message['global'])) {
                $answer('global', $message['global']);
            } elseif (isset($message['output'])) {
                $item = $message['output'];
                $output[] = ($item['kind'] ?? '') === 'image'
                    ? ['type' => 'image', 'data' => (string) ($item['data'] ?? ''), 'mimeType' => (string) ($item['mimeType'] ?? 'image/png')]
                    : ['type' => 'text', 'text' => (string) ($item['text'] ?? '')];
            } elseif (isset($message['done'])) {
                $done_ = $message['done'];
                $writes = $done_['writes'] ?? [];
                $storeWrites = [
                    'set' => is_array($writes['set'] ?? null) ? $writes['set'] : [],
                    'delete' => is_array($writes['delete'] ?? null) ? array_values(array_filter($writes['delete'], 'is_string')) : [],
                ];

                if (($done_['ok'] ?? false) === true) {
                    $finish(['ok' => true, 'value' => $done_['value'] ?? null]);
                } else {
                    $error = is_array($done_['error'] ?? null) ? $done_['error'] : ['kind' => 'script', 'message' => 'script failed'];
                    $finish(['ok' => false, 'error' => $error]);
                }
            }
        };

        $outWatcher = $loop->onReadable($stdout, static function () use ($stdout, &$buffer, $handleLine, $finish, &$stderrText, $process): void {
            $chunk = fread($stdout, 65536);

            if (is_string($chunk) && $chunk !== '') {
                $buffer .= $chunk;

                while (($at = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $at);
                    $buffer = substr($buffer, $at + 1);
                    $handleLine($line);
                }
            }

            if (feof($stdout)) {
                // Gone without a `done`: a crash the shutdown handler could not report.
                $status = proc_get_status($process);
                $detail = trim($stderrText) !== '' ? trim($stderrText) : 'the sandbox exited' . (isset($status['exitcode']) ? " with code {$status['exitcode']}" : '');
                $finish(['ok' => false, 'error' => ['kind' => 'sandbox', 'message' => $detail]]);
            }
        });
        $errWatcher = $loop->onReadable($stderr, static function () use ($stderr, &$stderrText): void {
            $chunk = fread($stderr, 65536);

            if (is_string($chunk)) {
                $stderrText .= $chunk;
            }
        });

        $timer = $this->timeout !== null
            ? $loop->delay($this->timeout, static fn () => $finish(['ok' => false, 'error' => ['kind' => 'timeout', 'message' => 'the script ran out of time']]))
            : null;
        $abortListener = $signal?->onAbort(static fn () => $finish(['ok' => false, 'error' => ['kind' => 'aborted', 'message' => $signal->reason()]]));

        $write(['boot' => ['code' => $code, 'tools' => array_map(static fn (array $t): array => ['name' => $t['name'], 'phpName' => $t['phpName'], 'description' => $t['description']], $this->tools), 'store' => $store === [] ? new \stdClass() : $store]]);

        try {
            $result = $done->future->await();
        } finally {
            if ($timer !== null) {
                $loop->cancel($timer);
            }

            if ($signal !== null && $abortListener !== null) {
                $signal->removeListener($abortListener);
            }

            $loop->cancel($outWatcher);
            $loop->cancel($errWatcher);

            foreach ([$stdin, $stdout, $stderr] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            if ($pid > 0) {
                Process::killTree($pid, 9);
            }

            proc_close($process);
        }

        $result['output'] = $output;
        $result['storeWrites'] = $storeWrites;

        // A memory-limit death arrives as `script` from the shutdown handler with the message
        // PHP prints; name it so the model is told to aggregate rather than retry.
        if (($result['ok'] ?? false) === false && str_contains((string) ($result['error']['message'] ?? ''), 'Allowed memory size')) {
            $result['error']['kind'] = 'memory';
        }

        return $result;
    }

    /** @param array<string, mixed> $args */
    private function callTool(string $name, array $args, ?AbortSignal $signal): mixed
    {
        foreach ($this->tools as $tool) {
            if ($tool['name'] === $name) {
                return ($tool['execute'])($args, $signal ?? (new \Pig\Async\AbortController())->signal);
            }
        }

        throw new RuntimeException("Unknown tool \"{$name}\"");
    }

    /** @param list<mixed> $args */
    private function callGlobal(string $name, array $args, ?AbortSignal $signal): mixed
    {
        $global = $this->globals[$name] ?? null;

        if ($global === null) {
            throw new RuntimeException("Unknown global \"{$name}\"");
        }

        return $global($args, $signal ?? (new \Pig\Async\AbortController())->signal);
    }

    /** @return array{ok: false, output: list<never>, storeWrites: array{set: array<never, never>, delete: list<never>}, error: array{kind: string, message: string}} */
    private function failed(string $kind, string $message): array
    {
        return ['ok' => false, 'output' => [], 'storeWrites' => ['set' => [], 'delete' => []], 'error' => ['kind' => $kind, 'message' => $message]];
    }
}
