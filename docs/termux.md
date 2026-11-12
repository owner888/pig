# Run pig on Android with Termux

pig runs natively on Android through [Termux](https://termux.dev/), a terminal emulator and Linux environment. Text input, file tools, and shell commands work. pig can copy and paste text with the Android clipboard through Termux:API. With pig's Termux build (below), an image pasted from the clipboard arrives as the path of a saved file, which pig can open.

## Before You Begin

### Recommended: pig's Termux build

[owner888/termux-app](https://github.com/owner888/termux-app) is Termux with what pig needs on a phone:

- **Chinese, Japanese and Korean input that shows while you type.** In official Termux the pinyin, kana or Hangul you are composing does not appear until it is committed, so you type blind; here it is drawn at the cursor, underlined, as in any other app. Turned on by itself when Gboard is the keyboard.
- **Image paste.** An image from the clipboard or from Gboard's clipboard strip is saved to `~/.cache/clipboard/clip_<time>.png` and its path is pasted into the terminal, so pig can read the picture.
- A Material 3 interface, and long presses no longer open the side drawer by accident.

Download the APK from its [Releases](https://github.com/owner888/termux-app/releases) page: `…arm64-v8a.apk` for almost every phone made in the last years, `…universal.apk` if you are not sure.

It is signed like the official GitHub builds of Termux (with the test key those share), so:

- **Termux from GitHub already installed**: install this APK over it. Your files and packages stay.
- **Termux from F-Droid installed**: Android will refuse the APK ("App not installed", "signatures do not match"). [Back up Termux](https://wiki.termux.dev/wiki/Backing_up_Termux), uninstall it and every Termux plugin, then install this one and restore.
- **Plugins** such as Termux:API must come from their GitHub releases, the same source as this build.

Anyone can sign an APK with that shared key, so take this build from that repository only.

### Official Termux

Official Termux from [GitHub or F-Droid](https://github.com/termux/termux-app#installation) works too, without the above. Do not use the deprecated Google Play release.

[Termux:API](https://github.com/termux/termux-api#installation) is optional. Install it only if you want pig to copy or paste Android clipboard text, or if shell commands need Android device APIs.

## Install pig

1. Update Termux packages:

   ```bash
   pkg update && pkg upgrade
   ```

2. Install PHP, Git, curl, and Composer (the installer runs `composer global require`):

   ```bash
   pkg install php git curl composer
   ```

3. Install pig using the installer or Composer:

   Using the official one-line installer:
   ```bash
   curl -fsSL https://pigagent.dev/install.sh | sh
   ```

   Or globally via Composer:
   ```bash
   composer global require pigagent/pig
   ```

4. Verify the installation:

   ```bash
   pig --version
   ```

5. Open the folder you want to work in and start pig:

   ```bash
   cd /path/to/working-folder
   pig
   ```

Continue with the main [Quickstart](/docs/latest/quickstart) to connect a model and run your first task.

## Access Android Shared Storage

Termux cannot access Android shared storage until you grant permission. Run this once:

```bash
termux-setup-storage
```

After approving the prompt, access Android shared storage under `/storage/emulated/0` and in the symlinks Termux creates under `~/storage/`.

Grant this only if pig needs to touch those files. Commands and tools running in Termux use the same storage permissions as the Termux process.

## Use Clipboard Commands

pig uses `termux-clipboard-set` to copy text and `termux-clipboard-get` for its clipboard paste shortcut. Shell commands can also use them directly. Install the Termux:API app and its command-line package:

```bash
pkg install termux-api
```

Verify the integration:

```bash
printf 'pig clipboard test' | termux-clipboard-set
termux-clipboard-get
```

The second command should output `pig clipboard test`.

The Termux clipboard API supports only text. pig's clipboard paste shortcut inserts that text into the editor, but cannot attach clipboard images.

## Add Termux-Specific Instructions

pig detects that it runs in Termux, but cannot infer how you want to interact with Android. Add environment details relevant to your work to `~/.pig/agent/AGENTS.md`:

````markdown
# Termux Environment

- pig runs in Termux on Android.
- Android shared storage is at `/storage/emulated/0`.
- Open URLs with `termux-open-url "https://example.com"`.
- Open files with `termux-open <path>`.
- Do not touch shared storage unless a task asks for it.
````

Run `/reload` after changing this file during an active session.

## Troubleshooting

### Clipboard integration fails

Confirm you installed both parts:

1. The Termux:API Android app from the same source as Termux (GitHub, with pig's Termux build)
2. The `termux-api` command-line package

Run the clipboard verification commands above outside pig. If they fail, fix the Termux:API installation before retrying pig's copy command.

### "App not installed" when installing pig's Termux build

Termux on the phone came from F-Droid (or Google Play), which signs with a different key. Back it up, uninstall it and its plugins, and install again — see [pig's Termux build](#recommended-pig-s-termux-build).

### Chinese input shows nothing until a word is chosen

That is official Termux. pig's Termux build draws the text being composed at the cursor; with a keyboard other than Gboard, turn the composing preview on in Termux's settings.

### Shared storage reports permission denied

Run `termux-setup-storage`, approve the Android prompt, and retry paths under `~/storage/` or `/storage/emulated/0`.

### pig is not found after install

The official installer script (`curl -fsSL https://pigagent.dev/install.sh | sh`) automatically recognizes Termux and appends the bin directory to `~/.bashrc`, automatically sourcing it for the installer session.

If `pig` is not immediately found in your active Termux window, activate the updated PATH by running:

```bash
source ~/.bashrc
```

If you installed manually via `composer global require`, ensure Composer's global bin directory (typically `~/.composer/vendor/bin`) is in your `PATH`:

```bash
echo 'export PATH="$HOME/.composer/vendor/bin:$PATH"' >> ~/.bashrc
source ~/.bashrc
```
