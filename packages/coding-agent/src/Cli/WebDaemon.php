<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Cli;

use Pig\CodingAgent\Config;
use Pig\CodingAgent\Web\WebLogin;
use Pig\Tui\Process;
use Pig\Tui\Style;

/**
 * `pig web start|stop|status|restart [-d] [--port N] [--host H]` — the web UI as a daemon — and
 * `pig web password`, the password the page is behind (`Web\WebLogin`).
 *
 * Step ① of the web mode's new process model (the entry in docs/dev/design/web.md under "Three ways in"):
 * the subcommand and the daemon, over `--mode web` exactly as it stands. The session pool and
 * the per-tab children are steps ② and ③ and change what runs *inside* the daemon, not this.
 *
 * Five decisions, each the smaller of two:
 *
 * - **The daemon is `pig --mode web` re-exec'd, not a second mode.** `start -d` double-forks,
 *   `setsid()`s, redirects the three standard streams and then `pcntl_exec()`s the same binary
 *   with `--mode web` — so a daemonised server and a foreground one run byte-identical code,
 *   and `WebMode`, which was tested as the foreground, needs no second test for the background.
 *   The two `fork()`s are the classic recipe: the first so the child can `setsid()` (a group
 *   leader cannot), the second so the grandchild can never reacquire a controlling terminal.
 * - **One pid file, under `Config::home()`**, so `PIG_CODING_AGENT_DIR` moves it with everything else and two
 *   developers on one machine do not fight over `/tmp/pig-web.pid`. One daemon per home; a
 *   second `start` says so rather than binding the next port up, because two daemons on
 *   adjacent ports is the thing nobody can tell apart from the browser.
 * - **`status` trusts the pid file only after `kill -0`.** A pid file left by a daemon that died
 *   (power cut, `kill -9`) names a pid the kernel has since reused or freed; `kill(pid, 0)`
 *   answers whether *something* is there, and a stale file is removed on the way past rather
 *   than reported as a running server. This is `Loop::poll()`'s `is_resource()` rule one
 *   layer up: check the thing, not the record of the thing.
 * - **`stop` is SIGTERM, a wait, then SIGKILL.** `WebMode` has no signal handler yet, so
 *   SIGTERM is the default disposition — immediate exit — which is fine for a server whose
 *   children are the ones holding state. The wait is bounded (3s) and the kill is the floor
 *   under it: a `stop` that can hang is a `stop` nobody trusts.
 * - **`ext-posix` is probed, not required.** `posix_setsid()` and `posix_kill()` are the two
 *   calls there is no other spelling of; everything else in pig gets by on `pcntl`. A build
 *   without posix (rare — it ships enabled everywhere pcntl does) refuses `start -d` and `stop`
 *   by name and still runs `start` in the foreground, rather than making the whole binary
 *   require an extension for one subcommand.
 *
 * Standard streams in the daemon go to a log file beside the pid file, not to `/dev/null`:
 * a server that dies at 3am with its last words thrown away is the one failure this class
 * exists to make debuggable. The log is truncated on each `start`, so it is the current run's.
 *
 * **`install` / `uninstall` make it a macOS service**, a launchd LaunchAgent at
 * `~/Library/LaunchAgents/dev.pigagent.web.plist`, so the server is running again when the person
 * logs in after a restart. Three things decided:
 *
 * - **launchd runs `pig --mode web` itself, not `pig web start -d`.** launchd is the
 *   supervisor: it wants a process that stays in the foreground, and `KeepAlive` restarts it
 *   when it dies. A double-forked daemon would look to launchd like a job that exited at once.
 * - **The install is a snapshot of this shell.** launchd starts a job with
 *   `PATH=/usr/bin:/bin:/usr/sbin:/sbin`, no proxy and `/` as the directory, so the plist
 *   carries the absolute `php` and `pig`, the PATH (where `fd`, `rg` and `git` are), the proxy
 *   variables pig reads, the agent directory this command used, and the directory it was typed
 *   in. Keys exported in the shell are deliberately **not** copied: a plist is a world-readable
 *   file, and `auth.json` is where a key belongs.
 * - **While the service is installed, `start`, `stop`, `status` and `restart` ask launchctl.**
 *   A launchd job has no pid file, and a SIGTERM to it is a crash `KeepAlive` undoes ten
 *   seconds later; `stop` is `bootout`, which holds until the next login or `start`.
 */
final class WebDaemon
{
    public const int DEFAULT_PORT = 8088;
    public const string DEFAULT_HOST = '127.0.0.1';

    /** How long `stop` waits for SIGTERM before reaching for SIGKILL. */
    private const float STOP_TIMEOUT = 3.0;

    /** The launchd job's label, and the plist's file name. */
    public const string LAUNCHD_LABEL = 'dev.pigagent.web';

    /** What the service carries over from the shell `install` ran in, when set. */
    private const array CARRIED_VARIABLES = [
        'PATH',
        'HTTPS_PROXY', 'https_proxy', 'HTTP_PROXY', 'http_proxy', 'NO_PROXY', 'no_proxy',
    ];

    /**
     * @param list<string> $argv the arguments after `pig web`
     * @param string $binary the `pig` to re-exec for `-d`; `bin/pig` passes `$argv[0]`
     * @param (callable(list<string>): never)|null $exec stand-in for `pcntl_exec()` in tests
     * @param (callable(list<string>): array{0: int, 1: string})|null $launchctl stand-in for
     *        running `launchctl <args>`: the exit code and what it printed
     * @param string|null $launchAgents where the plist goes; `~/Library/LaunchAgents` when null
     * @param string|null $platform `PHP_OS_FAMILY` when null; a test says `Darwin`
     */
    private readonly string $binary;

    public function __construct(
        string $binary,
        private readonly ?string $home = null,
        private readonly mixed $exec = null,
        private readonly mixed $launchctl = null,
        private readonly ?string $launchAgents = null,
        private readonly ?string $platform = null,
    ) {
        // Absolute before anything else happens: the daemon `chdir('/')`s before it execs, and
        // `$argv[0]` is `bin/pig` when pig was started from its checkout — which from `/` names
        // nothing. Found live: `start -d` printed ✔, the grandchild's exec failed on `/bin/pig`,
        // and `status` two seconds later said "not running".
        $this->binary = realpath($binary) ?: $binary;
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $command = $argv[0] ?? 'start';
        $rest = array_slice($argv, 1);

        if (in_array($command, ['-h', '--help', 'help'], true)) {
            $this->help();

            return 0;
        }

        if ($this->isInstalled() && in_array($command, ['start', 'stop', 'status', 'restart'], true)) {
            return match ($command) {
                'start' => $this->serviceStart($rest),
                'stop' => $this->serviceStop(),
                'status' => $this->serviceStatus(),
                'restart' => $this->serviceRestart($rest),
            };
        }

        return match ($command) {
            'start' => $this->start($rest),
            'stop' => $this->stop(),
            'status' => $this->status(),
            'restart' => $this->restart($rest),
            'password' => $this->password(),
            'install' => $this->install($rest),
            'uninstall' => $this->uninstall(),
            default => $this->unknown($command),
        };
    }

    public function plistFile(): string
    {
        $directory = $this->launchAgents ?? Config::userHome() . '/Library/LaunchAgents';

        return $directory . '/' . self::LAUNCHD_LABEL . '.plist';
    }

    /** Installed means the plist is there, on the one platform that reads it. */
    public function isInstalled(): bool
    {
        return $this->isMacos() && is_file($this->plistFile());
    }

    public function pidFile(): string
    {
        return ($this->home ?? Config::home()) . '/web.pid';
    }

    public function logFile(): string
    {
        return ($this->home ?? Config::home()) . '/web.log';
    }

    /**
     * The daemon's pid, or null when there is none — a pid file naming a process that has gone
     * is removed here and reads as none, not as a running server.
     */
    public function runningPid(): ?int
    {
        $file = $this->pidFile();

        if (!is_file($file)) {
            return null;
        }

        $pid = (int) trim((string) file_get_contents($file));

        if ($pid <= 0 || !self::alive($pid)) {
            unlink($file);

            return null;
        }

        return $pid;
    }

    // ---- the four commands ----------------------------------------------------------------

    /** @param list<string> $args */
    private function start(array $args): int
    {
        $daemon = in_array('-d', $args, true) || in_array('--daemon', $args, true);
        [$host, $port, $problem] = self::hostAndPort($args);

        if ($problem !== null) {
            fwrite(STDERR, Style::red("Error: {$problem}\n"));

            return 1;
        }

        if (($pid = $this->runningPid()) !== null) {
            fwrite(STDERR, Style::yellow("The web UI is already running (pid {$pid}). `pig web stop` first, or `pig web restart`.\n"));

            return 1;
        }

        $argv = $this->serverArgv($host, $port);

        if (!$daemon) {
            // Foreground: become `pig --mode web`. No pid file, because there is a terminal
            // holding this one and Ctrl+C is its `stop`.
            return $this->exec($argv);
        }

        if (!self::hasPosix()) {
            fwrite(STDERR, Style::red("Error: `pig web start -d` needs ext-posix (for setsid). Run `pig web start` in the foreground, or install the extension.\n"));

            return 1;
        }

        $home = $this->home ?? Config::home();
        if (!is_dir($home) && !mkdir($home, 0700, true) && !is_dir($home)) {
            fwrite(STDERR, Style::red("Error: cannot create {$home}\n"));

            return 1;
        }

        // Made here rather than by the daemon, whose output goes to its log: the code is printed
        // below, and the daemon reads the same file.
        $code = WebLogin::at($home)->setupCode();

        try {
            $pid = $this->daemonise($argv);
        } catch (\RuntimeException $e) {
            fwrite(STDERR, Style::red("Error: {$e->getMessage()}\n"));

            return 1;
        }

        echo Style::green('✔ Web UI daemon started') . Style::dim(" (pid {$pid}) at ") . Style::bold("http://{$host}:{$port}") . "\n";
        echo Style::dim("  log: {$this->logFile()}\n  stop: pig web stop\n");
        self::sayCode($code);

        return 0;
    }

    private function stop(): int
    {
        $pid = $this->runningPid();

        if ($pid === null) {
            echo Style::dim("The web UI is not running.\n");

            return 0;
        }

        if (!self::hasPosix()) {
            fwrite(STDERR, Style::red("Error: `pig web stop` needs ext-posix (for kill). The daemon is pid {$pid}; `kill {$pid}` stops it.\n"));

            return 1;
        }

        posix_kill($pid, SIGTERM);

        $deadline = microtime(true) + self::STOP_TIMEOUT;
        while (self::alive($pid) && microtime(true) < $deadline) {
            usleep(50_000);
        }

        if (self::alive($pid)) {
            posix_kill($pid, SIGKILL);
            usleep(100_000);
        }

        if (is_file($this->pidFile())) {
            unlink($this->pidFile());
        }

        echo Style::green("✔ Web UI daemon stopped") . Style::dim(" (pid {$pid})") . "\n";

        return 0;
    }

    private function status(): int
    {
        $pid = $this->runningPid();

        if ($pid === null) {
            echo "pig web: " . Style::dim('not running') . "\n";

            return 3; // LSB's "program is not running"
        }

        echo "pig web: " . Style::green('running') . Style::dim(" (pid {$pid}, log {$this->logFile()})") . "\n";
        self::sayCode(WebLogin::at($this->home ?? Config::home())->setupCode());

        return 0;
    }

    /**
     * `pig web password`: set or change the web UI's password. Asked twice with the terminal's echo
     * off, or read from standard input when that is not a terminal (`… | pig web password`).
     * Every browser logged in before has to log in again.
     */
    private function password(): int
    {
        $login = WebLogin::at($this->home ?? Config::home());
        $terminal = stream_isatty(STDIN);

        $first = self::ask($terminal ? 'New password: ' : null);
        $second = $terminal ? self::ask('Once more: ') : $first;

        if ($first !== $second) {
            fwrite(STDERR, Style::red("Error: the two passwords differ.\n"));

            return 1;
        }

        try {
            $login->setPassword($first);
        } catch (\InvalidArgumentException $e) {
            fwrite(STDERR, Style::red("Error: {$e->getMessage()}\n"));

            return 1;
        }

        echo Style::green('✔ Web UI password set.') . Style::dim(' Browsers logged in before log in again.') . "\n";

        return 0;
    }

    /** One line from standard input; with `$prompt`, typed without echo. */
    private static function ask(?string $prompt): string
    {
        if ($prompt === null) {
            return rtrim((string) fgets(STDIN), "\r\n");
        }

        fwrite(STDOUT, $prompt);
        shell_exec('stty -echo 2>/dev/null');
        try {
            $line = rtrim((string) fgets(STDIN), "\r\n");
        } finally {
            shell_exec('stty echo 2>/dev/null');
            fwrite(STDOUT, "\n");
        }

        return $line;
    }

    /** The setup code, while there is no password yet: what the first visit to the page asks for. */
    public static function sayCode(?string $code): void
    {
        if ($code === null) {
            return;
        }

        echo Style::yellow('  First visit: set a password with the setup code ') . Style::bold($code) . Style::dim(' (or run `pig web password`)') . "\n";
    }

    /** @param list<string> $args */
    private function restart(array $args): int
    {
        if ($this->runningPid() !== null) {
            $status = $this->stop();
            if ($status !== 0) {
                return $status;
            }
        }

        // A restart is a daemon restart: nobody types `restart` for a foreground server, and
        // one in the foreground would have had no pid file to stop.
        if (!in_array('-d', $args, true) && !in_array('--daemon', $args, true)) {
            $args[] = '-d';
        }

        return $this->start($args);
    }

    // ---- the macOS service ------------------------------------------------------------------

    /** @param list<string> $args */
    private function install(array $args): int
    {
        if (!$this->isMacos()) {
            fwrite(STDERR, Style::red("Error: `pig web install` makes a macOS launchd service; this is " . ($this->platform ?? PHP_OS_FAMILY) . ". Run `pig web start -d` instead.\n"));

            return 1;
        }

        [$host, $port, $problem] = self::hostAndPort($args);
        if ($problem !== null) {
            fwrite(STDERR, Style::red("Error: {$problem}\n"));

            return 1;
        }

        if (($pid = $this->runningPid()) !== null) {
            fwrite(STDERR, Style::yellow("A web UI daemon is already running (pid {$pid}) and would hold the port. `pig web stop` first.\n"));

            return 1;
        }

        $home = $this->home ?? Config::home();
        foreach ([$home, dirname($this->plistFile())] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                fwrite(STDERR, Style::red("Error: cannot create {$directory}\n"));

                return 1;
            }
        }

        // A second install replaces the first: the old job is taken down before its plist is
        // rewritten, or launchd would keep running what the old file said.
        if ($this->serviceLoaded()) {
            $this->launchctl(['bootout', $this->serviceTarget()]);
        }

        // `--no-browser`, or every login and every restart `KeepAlive` makes opens a browser tab.
        $cwd = getcwd() ?: '.';
        if (file_put_contents($this->plistFile(), $this->plist([...$this->serverArgv($host, $port), '--no-browser'], $cwd)) === false) {
            fwrite(STDERR, Style::red("Error: cannot write {$this->plistFile()}\n"));

            return 1;
        }

        [$code, $said] = $this->launchctl(['bootstrap', $this->domain(), $this->plistFile()]);
        if ($code !== 0) {
            // A plist launchd refused would be loaded again at the next login and refused again
            // there, with nobody watching; taking it back keeps "installed" meaning "running".
            unlink($this->plistFile());
            fwrite(STDERR, Style::red("Error: launchctl bootstrap failed ({$code}): " . trim($said) . "\n"));

            return 1;
        }

        echo Style::green('✔ Web UI installed as a login service') . Style::dim(' at ') . Style::bold("http://{$host}:{$port}") . "\n";
        echo Style::dim("  plist: {$this->plistFile()}\n  log: {$this->logFile()}\n  directory: {$cwd}\n");
        echo Style::dim("  It starts when you log in and is restarted if it dies. `pig web uninstall` removes it.\n");
        self::sayCode(WebLogin::at($home)->setupCode());

        return 0;
    }

    private function uninstall(): int
    {
        if (!$this->isInstalled()) {
            echo Style::dim("The web UI is not installed as a service.\n");

            return 0;
        }

        if ($this->serviceLoaded()) {
            [$code, $said] = $this->launchctl(['bootout', $this->serviceTarget()]);
            if ($code !== 0) {
                fwrite(STDERR, Style::red("Error: launchctl bootout failed ({$code}): " . trim($said) . "\n"));

                return 1;
            }
        }

        unlink($this->plistFile());
        echo Style::green('✔ Web UI service removed') . Style::dim(" ({$this->plistFile()})") . "\n";

        return 0;
    }

    /** @param list<string> $args */
    private function serviceStart(array $args): int
    {
        if (($refusal = self::optionsTheServiceIgnores($args)) !== null) {
            fwrite(STDERR, Style::red("Error: {$refusal}\n"));

            return 1;
        }

        if (!$this->serviceLoaded()) {
            return $this->bootstrap('started');
        }

        if (($pid = $this->servicePid()) !== null) {
            fwrite(STDERR, Style::yellow("The web UI service is already running (pid {$pid}). `pig web restart` restarts it.\n"));

            return 1;
        }

        return $this->kickstart(false, 'started');
    }

    private function serviceStop(): int
    {
        if (!$this->serviceLoaded()) {
            echo Style::dim("The web UI is not running.\n");

            return 0;
        }

        [$code, $said] = $this->launchctl(['bootout', $this->serviceTarget()]);
        if ($code !== 0) {
            fwrite(STDERR, Style::red("Error: launchctl bootout failed ({$code}): " . trim($said) . "\n"));

            return 1;
        }

        echo Style::green('✔ Web UI service stopped') . "\n";
        echo Style::dim("  It starts again when you log in, or with `pig web start`; `pig web uninstall` removes it.\n");

        return 0;
    }

    private function serviceStatus(): int
    {
        if (!$this->serviceLoaded()) {
            echo "pig web: " . Style::dim('installed as a service, not running') . "\n";

            return 3;
        }

        $pid = $this->servicePid();
        if ($pid === null) {
            echo "pig web: " . Style::yellow('service loaded, not running') . Style::dim(" (launchd will retry; log {$this->logFile()})") . "\n";

            return 3;
        }

        echo "pig web: " . Style::green('running') . Style::dim(" (pid {$pid}, launchd service, log {$this->logFile()})") . "\n";
        self::sayCode(WebLogin::at($this->home ?? Config::home())->setupCode());

        return 0;
    }

    /** @param list<string> $args */
    private function serviceRestart(array $args): int
    {
        if (($refusal = self::optionsTheServiceIgnores($args)) !== null) {
            fwrite(STDERR, Style::red("Error: {$refusal}\n"));

            return 1;
        }

        return $this->serviceLoaded() ? $this->kickstart(true, 'restarted') : $this->bootstrap('started');
    }

    private function bootstrap(string $verb): int
    {
        [$code, $said] = $this->launchctl(['bootstrap', $this->domain(), $this->plistFile()]);
        if ($code !== 0) {
            fwrite(STDERR, Style::red("Error: launchctl bootstrap failed ({$code}): " . trim($said) . "\n"));

            return 1;
        }

        echo Style::green("✔ Web UI service {$verb}") . Style::dim(" (log {$this->logFile()})") . "\n";

        return 0;
    }

    private function kickstart(bool $kill, string $verb): int
    {
        [$code, $said] = $this->launchctl($kill ? ['kickstart', '-k', $this->serviceTarget()] : ['kickstart', $this->serviceTarget()]);
        if ($code !== 0) {
            fwrite(STDERR, Style::red("Error: launchctl kickstart failed ({$code}): " . trim($said) . "\n"));

            return 1;
        }

        echo Style::green("✔ Web UI service {$verb}") . Style::dim(" (log {$this->logFile()})") . "\n";

        return 0;
    }

    /**
     * The host and port are the plist's, so a flag that would change them for one start is
     * refused by name rather than ignored: the server would come up where it always does.
     *
     * @param list<string> $args
     */
    private static function optionsTheServiceIgnores(array $args): ?string
    {
        foreach ($args as $arg) {
            if (in_array($arg, ['--port', '-p', '--host'], true) || str_starts_with($arg, '--port=') || str_starts_with($arg, '--host=')) {
                return 'the service listens where `pig web install` put it; `pig web install --port N --host H` changes that.';
            }
        }

        return null;
    }

    private function serviceLoaded(): bool
    {
        return $this->launchctl(['print', $this->serviceTarget()])[0] === 0;
    }

    /** The job's pid from `launchctl print`, or null when launchd has it loaded and not running. */
    private function servicePid(): ?int
    {
        [$code, $said] = $this->launchctl(['print', $this->serviceTarget()]);

        return $code === 0 && preg_match('/^\s*pid = (\d+)\s*$/m', $said, $m) === 1 ? (int) $m[1] : null;
    }

    private function domain(): string
    {
        $uid = function_exists('posix_getuid') ? posix_getuid() : (int) trim(Process::run(['id', '-u'])[1]);

        return "gui/{$uid}";
    }

    private function serviceTarget(): string
    {
        return $this->domain() . '/' . self::LAUNCHD_LABEL;
    }

    /**
     * @param list<string> $args
     * @return array{0: int, 1: string} the exit code, and standard output and error together
     */
    private function launchctl(array $args): array
    {
        if ($this->launchctl !== null) {
            return ($this->launchctl)($args);
        }

        [$code, $out, $err] = Process::run(['launchctl', ...$args], 15.0);

        return [$code, $out . $err];
    }

    private function isMacos(): bool
    {
        return ($this->platform ?? PHP_OS_FAMILY) === 'Darwin';
    }

    /**
     * The LaunchAgent, written out by hand: a plist is XML and the escaping is the whole of it.
     *
     * @param list<string> $argv the server's argv, `pig` first; `php` goes in front of it
     */
    private function plist(array $argv, string $cwd): string
    {
        $string = static fn (string $value): string => '<string>' . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</string>';

        $arguments = implode("\n", array_map(static fn (string $a): string => "        {$string($a)}", [PHP_BINARY, ...$argv]));

        // The home is written whether or not it was set, so the service writes its log and
        // reads its settings where this command did.
        $variables = ["        <key>PIG_CODING_AGENT_DIR</key>\n        {$string($this->home ?? Config::home())}"];
        foreach (self::CARRIED_VARIABLES as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') {
                $variables[] = "        <key>{$name}</key>\n        {$string($value)}";
            }
        }
        $environment = implode("\n", $variables);
        $log = $string($this->logFile());

        return <<<PLIST
        <?xml version="1.0" encoding="UTF-8"?>
        <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
        <plist version="1.0">
        <dict>
            <key>Label</key>
            {$string(self::LAUNCHD_LABEL)}
            <key>ProgramArguments</key>
            <array>
        {$arguments}
            </array>
            <key>EnvironmentVariables</key>
            <dict>
        {$environment}
            </dict>
            <key>WorkingDirectory</key>
            {$string($cwd)}
            <key>RunAtLoad</key>
            <true/>
            <key>KeepAlive</key>
            <true/>
            <key>ProcessType</key>
            <string>Interactive</string>
            <key>StandardOutPath</key>
            {$log}
            <key>StandardErrorPath</key>
            {$log}
        </dict>
        </plist>

        PLIST;
    }

    /**
     * `pig --mode web` for this directory, host and port — what a foreground start becomes,
     * what the daemon execs, and what launchd runs.
     *
     * @return list<string>
     */
    private function serverArgv(string $host, int $port): array
    {
        // `--cwd` pinned to where the command was typed, because the daemon `chdir('/')`s
        // before it execs (a daemon must not hold a directory somebody wants to unmount) and
        // launchd starts a job in `/`; the session would otherwise belong to `/`. Found live:
        // `cwd: /` in `/api/state`.
        return [$this->binary, '--mode', 'web', '--cwd', getcwd() ?: '.', '--web-host', $host, '--web-port', (string) $port];
    }

    private function unknown(string $command): int
    {
        fwrite(STDERR, Style::red("Error: unknown command `pig web {$command}`.\n\n"));
        $this->help(STDERR);

        return 1;
    }

    // ---- the daemon ------------------------------------------------------------------------

    /**
     * Double-fork into the background and exec `$argv` there; returns the daemon's pid to the
     * parent. The grandchild never returns from here.
     *
     * @param list<string> $argv
     */
    private function daemonise(array $argv): int
    {
        // The pid has to travel back from the grandchild, and the parent cannot `wait()` for a
        // grandchild. A pipe is the channel: the grandchild writes its pid before exec and the
        // parent reads it after the middle child has exited.
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false) {
            throw new \RuntimeException('Could not create a pipe to the daemon.');
        }
        [$parentEnd, $childEnd] = $pair;

        $first = pcntl_fork();
        if ($first === -1) {
            throw new \RuntimeException('fork() failed.');
        }

        if ($first > 0) {
            // Parent: reap the middle child, then read the grandchild's pid.
            fclose($childEnd);
            pcntl_waitpid($first, $status);
            $pid = (int) trim((string) fgets($parentEnd));
            fclose($parentEnd);

            if ($pid <= 0) {
                throw new \RuntimeException("The daemon did not start; see {$this->logFile()}.");
            }

            // The pid arrives *before* the exec, so a daemon whose exec failed has already
            // reported itself alive. Give it a moment and ask the kernel: a ✔ for a process
            // that is gone is the first version of this, and it cost a live run to find.
            usleep(300_000);
            if (!self::alive($pid)) {
                if (is_file($this->pidFile())) {
                    unlink($this->pidFile());
                }
                $tail = is_file($this->logFile()) ? trim((string) file_get_contents($this->logFile())) : '';

                throw new \RuntimeException("The daemon exited at once" . ($tail !== '' ? ": {$tail}" : "; see {$this->logFile()}."));
            }

            return $pid;
        }

        // Middle child: new session, fork again, exit — so the grandchild is not a session
        // leader and can never reacquire a controlling terminal.
        fclose($parentEnd);
        posix_setsid();

        $second = pcntl_fork();
        if ($second === -1) {
            exit(1);
        }
        if ($second > 0) {
            exit(0);
        }

        // Grandchild: the daemon. Write the pid file and tell the parent, then point the three
        // standard streams at the log and become `pig --mode web`.
        $pid = getmypid();
        file_put_contents($this->pidFile(), $pid . "\n");
        fwrite($childEnd, $pid . "\n");
        fclose($childEnd);

        chdir('/');
        umask(0022);

        $log = $this->logFile();
        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);
        // Reopened in order, so they land on fds 0, 1, 2 — the ones the exec'd process inherits.
        $GLOBALS['__pig_stdin'] = fopen('/dev/null', 'r');
        $GLOBALS['__pig_stdout'] = fopen($log, 'w');
        $GLOBALS['__pig_stderr'] = fopen($log, 'a');

        $this->exec($argv);
    }

    /**
     * Replace this process with `$argv`; never returns on success. Separate so a test can
     * hand in a closure and watch what would have been exec'd.
     *
     * @param list<string> $argv
     */
    private function exec(array $argv): never
    {
        if ($this->exec !== null) {
            ($this->exec)($argv);
        }

        // `pcntl_exec` wants the program and its arguments apart, and PHP scripts are run through
        // the interpreter — `$argv[0]` is `bin/pig`, not an ELF.
        $php = PHP_BINARY;
        pcntl_exec($php, $argv);

        // Only reached when exec failed.
        fwrite(STDERR, "exec failed: {$php} " . implode(' ', $argv) . "\n");
        exit(127);
    }

    // ---- helpers ---------------------------------------------------------------------------

    private static function alive(int $pid): bool
    {
        if (self::hasPosix()) {
            return posix_kill($pid, 0) || posix_get_last_error() === 1; // EPERM: alive, not ours
        }

        // Without posix: `kill -0` through the shell. Same question, slower.
        exec('kill -0 ' . $pid . ' 2>/dev/null', $out, $code);

        return $code === 0;
    }

    private static function hasPosix(): bool
    {
        return function_exists('posix_setsid') && function_exists('posix_kill');
    }

    /**
     * @param list<string> $args
     * @return array{string, int, ?string} host, port, and a complaint when one was malformed
     */
    private static function hostAndPort(array $args): array
    {
        $host = self::DEFAULT_HOST;
        $port = self::DEFAULT_PORT;

        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];

            if ($arg === '--port' || $arg === '-p') {
                $value = $args[++$i] ?? '';
                if (!ctype_digit($value) || (int) $value < 1 || (int) $value > 65535) {
                    return [$host, $port, "--port wants a number from 1 to 65535, not '{$value}'"];
                }
                $port = (int) $value;
            } elseif (str_starts_with($arg, '--port=')) {
                $value = substr($arg, 7);
                if (!ctype_digit($value) || (int) $value < 1 || (int) $value > 65535) {
                    return [$host, $port, "--port wants a number from 1 to 65535, not '{$value}'"];
                }
                $port = (int) $value;
            } elseif ($arg === '--host') {
                $host = $args[++$i] ?? '';
                if ($host === '') {
                    return [$host, $port, '--host wants an address'];
                }
            } elseif (str_starts_with($arg, '--host=')) {
                $host = substr($arg, 7);
            }
        }

        return [$host, $port, null];
    }

    /** @param resource $to */
    private function help($to = STDOUT): void
    {
        fwrite($to, <<<TXT
        pig web — the browser UI as a server

          pig web start [-d] [--port N] [--host H]   start (foreground, or -d for a daemon)
          pig web stop                                stop the daemon
          pig web status                              is it running? (exit 0 yes, 3 no)
          pig web restart [--port N] [--host H]       stop, then start -d
          pig web password                            set or change the web UI's password
          pig web install [--port N] [--host H]       macOS: a login service (launchd) that starts
                                                      at login and restarts if it dies
          pig web uninstall                           remove the login service

        Defaults: --host 127.0.0.1 --port 8088. The daemon writes its pid and log under
        \$PIG_CODING_AGENT_DIR (~/.pig/agent): web.pid and web.log. While the service is
        installed, start/stop/status/restart go through launchctl; `install` again changes
        its port or host. The service gets this shell's PATH and proxy, not its API keys:
        sign in with /login or keep keys in auth.json.

        TXT);
    }
}
