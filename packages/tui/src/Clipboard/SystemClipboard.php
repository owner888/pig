<?php

declare(strict_types=1);

namespace Pig\Tui\Clipboard;

use Pig\Tui\Process;

/**
 * The real clipboard, read by asking whatever tool this machine has.
 *
 * There is no portable way to do this. macOS has `pbpaste` for text and, for a picture,
 * either `pngpaste` if it happens to be installed or AppleScript, which is always there.
 * Linux has `wl-paste` under Wayland and `xclip` under X11, and WSL has neither for
 * images because a Windows screenshot never reaches the Linux clipboard — that one needs
 * PowerShell.
 *
 * Every one of them is optional. A machine with none of them has no clipboard as far as
 * this is concerned, which is a capability, not a failure: `null` comes back and the
 * caller carries on.
 */
final class SystemClipboard implements Clipboard
{
    /** In the order we would rather have them, best first. */
    private const array IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    /** Listing what is on the clipboard should be instant; fetching it may not be. */
    private const float LIST_TIMEOUT = 1.0;
    private const float POWERSHELL_TIMEOUT = 5.0;

    public function __construct(private readonly ?string $platform = null)
    {
    }

    #[\Override]
    public function text(): ?string
    {
        $text = match ($this->platform()) {
            'darwin' => Process::capture(['pbpaste']),
            'windows' => self::powerShell('Get-Clipboard -Raw'),
            default => $this->linuxText(),
        };

        return $text === null || $text === '' ? null : $text;
    }

    #[\Override]
    public function image(): ?ClipboardImage
    {
        // Termux has a clipboard but no image on it, and asking costs a process launch
        // on every paste.
        if (getenv('TERMUX_VERSION') !== false) {
            return null;
        }

        return match ($this->platform()) {
            'darwin' => $this->macImage(),
            'windows' => $this->windowsImage(),
            default => $this->linuxImage(),
        };
    }

    private function platform(): string
    {
        if ($this->platform !== null) {
            return $this->platform;
        }

        return match (true) {
            PHP_OS_FAMILY === 'Darwin' => 'darwin',
            PHP_OS_FAMILY === 'Windows' => 'windows',
            default => 'linux',
        };
    }

    private function linuxText(): ?string
    {
        if (self::isWayland()) {
            $text = Process::capture(['wl-paste', '--no-newline']);

            if ($text !== null) {
                return $text;
            }
        }

        $text = Process::capture(['xclip', '-selection', 'clipboard', '-o']);

        if ($text !== null) {
            return $text;
        }

        // A Windows screenshot or copy never reaches the Linux clipboard under WSL.
        return self::isWsl() ? self::powerShell('Get-Clipboard -Raw') : null;
    }

    /**
     * A picture off the macOS clipboard.
     *
     * `pngpaste` writes it straight to stdout and is the fast path, but it is a Homebrew
     * package and most machines do not have it. AppleScript can do the same thing and
     * ships with the system, so it is the one that always works — by way of a temporary
     * file, because `osascript` has no way to put bytes on stdout.
     */
    private function macImage(): ?ClipboardImage
    {
        $bytes = Process::capture(['pngpaste', '-']);

        if ($bytes !== null && $bytes !== '') {
            return new ClipboardImage($bytes, 'image/png');
        }

        return self::viaTemporaryFile(
            'pig-clip-',
            '.png',
            static fn (string $path): ?string => Process::capture([
                'osascript',
                '-e', 'set png to (the clipboard as «class PNGf»)',
                '-e', 'set f to open for access POSIX file "' . $path . '" with write permission',
                '-e', 'set eof f to 0',
                '-e', 'write png to f',
                '-e', 'close access f',
            ]),
            'image/png',
        );
    }

    private function linuxImage(): ?ClipboardImage
    {
        if (self::isWayland() || self::isWsl()) {
            $image = $this->waylandImage();

            if ($image !== null) {
                return $image;
            }
        }

        $image = $this->x11Image();

        if ($image !== null) {
            return $image;
        }

        return self::isWsl() ? $this->windowsImage() : null;
    }

    private function waylandImage(): ?ClipboardImage
    {
        $types = Process::capture(['wl-paste', '--list-types'], self::LIST_TIMEOUT);

        if ($types === null) {
            return null;
        }

        $type = self::preferred(preg_split('/\r?\n/', trim($types)) ?: []);

        if ($type === null) {
            return null;
        }

        $bytes = Process::capture(['wl-paste', '--type', $type, '--no-newline']);

        return $bytes === null || $bytes === '' ? null : new ClipboardImage($bytes, $type);
    }

    /**
     * X11, which will hand over a format it never advertised.
     *
     * So the advertised list only decides what to ask for *first*; if that comes back
     * empty the rest are tried anyway, which is how a picture copied by an application
     * with an idiosyncratic TARGETS list still gets pasted.
     */
    private function x11Image(): ?ClipboardImage
    {
        $targets = Process::capture(['xclip', '-selection', 'clipboard', '-t', 'TARGETS', '-o'], self::LIST_TIMEOUT);
        $advertised = $targets === null ? [] : (preg_split('/\r?\n/', trim($targets)) ?: []);
        $preferred = self::preferred($advertised);

        if ($targets !== null && $preferred === null) {
            return null;
        }

        $order = $preferred === null
            ? self::IMAGE_TYPES
            : [$preferred, ...array_diff(self::IMAGE_TYPES, [$preferred])];

        foreach ($order as $type) {
            $bytes = Process::capture(['xclip', '-selection', 'clipboard', '-t', $type, '-o']);

            if ($bytes !== null && $bytes !== '') {
                return new ClipboardImage($bytes, $type);
            }
        }

        return null;
    }

    private function windowsImage(): ?ClipboardImage
    {
        return self::viaTemporaryFile(
            'pig-clip-',
            '.png',
            static function (string $path): ?string {
                $windowsPath = Process::capture(['wslpath', '-w', $path], self::LIST_TIMEOUT);
                $target = $windowsPath === null ? $path : trim($windowsPath);

                return self::powerShell(implode('; ', [
                    'Add-Type -AssemblyName System.Windows.Forms',
                    'Add-Type -AssemblyName System.Drawing',
                    '$img = [System.Windows.Forms.Clipboard]::GetImage()',
                    "if (\$img) { \$img.Save('" . str_replace("'", "''", $target)
                        . "', [System.Drawing.Imaging.ImageFormat]::Png) }",
                ]), self::POWERSHELL_TIMEOUT);
            },
            'image/png',
        );
    }

    /**
     * Run something that writes an image to a path, then read the path back.
     *
     * Two of the three backends can only write to a file. The file is removed whatever
     * happens — a clipboard paste should not leave litter in the temp directory on every
     * key press.
     *
     * @param callable(string): ?string $write
     */
    private static function viaTemporaryFile(string $prefix, string $suffix, callable $write, string $mimeType): ?ClipboardImage
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);

        if ($path === false) {
            return null;
        }

        // tempnam() has no suffix argument, and the tools below pick their format from
        // the extension, so the real target is the same name with one added.
        $target = $path . $suffix;

        try {
            if ($write($target) === null || !is_file($target)) {
                return null;
            }

            $bytes = file_get_contents($target);

            return $bytes === false || $bytes === '' ? null : new ClipboardImage($bytes, $mimeType);
        } finally {
            foreach ([$path, $target] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    private static function powerShell(string $script, float $timeout = self::POWERSHELL_TIMEOUT): ?string
    {
        return Process::capture(['powershell.exe', '-NoProfile', '-Command', $script], $timeout);
    }

    /**
     * The best image type in a list of what the clipboard is offering.
     *
     * @param list<string> $types
     */
    private static function preferred(array $types): ?string
    {
        $offered = array_values(array_filter(array_map(static fn (string $type): string => strtolower(
            trim(explode(';', $type)[0]),
        ), $types)));

        foreach (self::IMAGE_TYPES as $wanted) {
            if (in_array($wanted, $offered, true)) {
                return $wanted;
            }
        }

        foreach ($offered as $type) {
            if (str_starts_with($type, 'image/')) {
                return $type;
            }
        }

        return null;
    }

    private static function isWayland(): bool
    {
        return getenv('WAYLAND_DISPLAY') !== false || getenv('XDG_SESSION_TYPE') === 'wayland';
    }

    private static function isWsl(): bool
    {
        return getenv('WSL_DISTRO_NAME') !== false || getenv('WSL_INTEROP') !== false;
    }
}
