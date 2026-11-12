# Android Use (pig-android-use)

The `pig-android-use` package lets the pig coding agent operate an Android phone or emulator over ADB: it reads the screen's view tree, takes screenshots, taps, swipes, types (Chinese included), presses buttons and launches apps. It can also work on a **background screen** of its own on the phone, which the person does not see, so the phone stays theirs while pig works.

It is PHP plus the `adb` binary, and a small helper of its own that runs on the phone (Java, no libraries). No Appium, no Python, no app to install on the phone.

---

## Install

```bash
pig install git:github.com/owner888/pig-android-use
```

You also need:

- `adb` (Android platform-tools) on the machine pig runs on — on `PATH`, in the Android SDK's usual places, or named by `ADB_PATH`.
- A phone with **USB debugging** on, or **wireless debugging** paired, then `/android connect <ip:port>`.
- **Android 12 or newer** for the view tree and the background screen.

Android use was bundled with pig until it became this package. `pig update` removes the old bundled copy and prints the install line above; `disabledExtensions: ["pig-android-use"]` still reaches the package.

The package ships the `android-use` skill, which tells the model how to use the tools, and the `messaging`, `info-research` and `content-transcribe` skills.

---

## Quickstart

```text
/android status
```

It lists the connected devices, the screen size and the foreground app. Then ask pig in plain language:

> **In the background screen, open Settings, go into Display, and tell me what options it has**

The model typically:

1. calls `android_ready` to wake the phone and get a first look,
2. opens the app with `android_launch_app(package: "com.android.settings", screen: "background")`,
3. reads the screen with `android_tree(screen: "background")` and finds "Display" in it,
4. taps it with `android_tap(x, y, screen: "background")` and reads the tree again.

**adb comes first.** What a shell command can do — open a page with `am start`, change a setting with `settings put`, install with `adb install`, read state with `dumpsys` — the skill has the model do from `bash`, without touching the screen. The screen tools are for an app's own UI.

---

## Tools

| Tool | Parameters | Description |
|---|---|---|
| `android_doctor` | `serial` | adb, ffmpeg, devices, screen size, foreground app, screen state |
| `android_ready` | `serial`, `screenshot` (default `true`) | Check for a device, wake the screen, return the foreground app, the viewport and a screenshot. Call it first |
| `android_observe` | `mode` (`screenshot`, `info`, `both`), `screen`, `serial` | A screenshot and/or the foreground app |
| `android_tree` | `max_nodes` (default 60), `screen`, `serial` | The view tree as compact nodes (`type`, `text`, `desc`, `id`, `rect`) and whether it is enough to work from |
| `android_tap` | `x`, `y`, `observe`, `screen`, `serial` | Tap at screenshot coordinates, with ±2 px jitter |
| `android_swipe` | `direction` or `from_x`/`from_y`/`to_x`/`to_y`, `duration_ms`, `observe`, `screen`, `serial` | Swipe by direction, or drag between two points |
| `android_type_text` | `text`, `screen`, `serial` | Type into the focused field — Chinese, symbols, several lines (through the Android clipboard, no keyboard app needed) |
| `android_press_button` | `name`, `screen`, `serial` | `back`, `home`, `app_switch`, `power`, `volumeup`, `volumedown`, `enter` |
| `android_launch_app` | `package`, `screen`, `serial` | Launch an app by package name |
| `android_batch` | `steps`, `observe` (default `screenshot`), `screen`, `serial` | Up to 15 steps in order, 150–350 ms apart |
| `android_connect` | `address` | Connect to a device over Wi-Fi |

`screen` is `main` (default) or `background`. `observe` on the action tools is `none` (default), `screenshot` or `both`: the result carries the new screen next to the action's report, so there is no separate call just to look.

---

## The view tree

`android_tree` is the fast way to see a screen: about **0.4 s** a read, against 2.5 s for `uiautomator dump` and more for a screenshot the model has to look at. The helper on the phone reads it through UiAutomation, connected for each read and disconnected after — so `uiautomator dump` still works for anything else — and without suppressing the accessibility services the person has on. The first read in a while takes about a second more, while the helper starts.

The result says whether the tree is enough to work from:

| `tree_quality` | Meaning |
|---|---|
| `adequate` | Enough named elements, controls labelled, no large area without text |
| `partial` | Part of the screen is missing from the tree |
| `unusable` | Next to nothing in it — an app that draws its own UI (a game, a canvas, a map) |
| `unchecked` | It could not be checked against the screen (no OCR on this machine) |

When it is not clearly enough, pig reads the text on the screen and returns the lines the tree lacks as `ocr_lines`, each with its `rect`. An `unusable` tree also comes with the screenshot: a game's icons have no text to read, and the model takes their positions from the picture. The OCR is Apple's Vision framework through `osascript` — Chinese and English, nothing to install, **macOS only**. On Linux and Termux, a partial or unusable tree comes with the screenshot instead.

---

## The background screen

`screen: "background"` works on a virtual display pig makes on the phone. It has the phone's own size and density, the person never sees it, and an app opened there keeps running while they use the phone. Nothing appears on the main screen.

How it works: pig pushes its helper (`helper/pig-android-helper.dex`) to `/data/local/tmp`, starts it with `app_process` as the shell user, and reaches it through `adb forward` with a token only pig knows. The helper makes the display, holds its picture, takes its screenshots and reads its tree; taps, keys and launches go to it with adb (`input -d`, `am start --display`). The helper exits after 15 minutes without a request, and the display and the app on it close with it.

Things to know:

- Pass `screen: "background"` to every call for that work; a call without it goes to the main screen.
- `home` has no launcher there; open another app with `android_launch_app` instead.
- An app runs in one place: if the person opens it themselves, it moves to them.
- It works while the phone is locked: pig wakes it before each action if it is off and keeps it from sleeping while it works. Some systems put it in the main screen's display group (Android 17 on Xiaomi does); there the phone is woken to its lock screen — not unlocked — for as long as pig works, and goes dark on its own timer after.

| Command | Description |
|---|---|
| `/android background` | Whether there is a background screen, and its display id |
| `/android background off` | Close it and the app on it |

---

## Coordinates

Screenshots are scaled so the short side is at most 768 px and the long side at most 1568, and saved as JPEG in the extension's artifacts directory. Tree rects are in the same coordinates, and `viewport` gives their size. Each screen keeps its own `pixel_to_point` ratio, set by every screenshot and every tree read of it, so a target read from the tree is tapped where it is whether or not a screenshot came in between.

---

## App knowledge

`skills/android-use/apps/` holds notes on 24 apps — WeChat, Xiaohongshu, Douyin, Bilibili, BOSS Zhipin, Feishu, Telegram, WhatsApp, Gmail, the game Pokémon TCG Pocket and more: their screens, where features live, and what is easy to get wrong. The skill has the model read the matching `APP.md` before it works in an app, and slow down in those marked `pace: careful`. The notes are in Chinese; all but the Pokémon TCG Pocket one come from [Mika](https://github.com/wangzan101/Mika) (Apache-2.0).

### Notes the model keeps

The model keeps its own notes on each app it works in, `~/.pig/agent/android-use/notes/<package>.md`: it reads them before working in the app, and after each run adds or corrects what it did and then saw — dated, with the app's version, positions in words rather than pixels, and the path that worked for a task. Where they disagree with `APP.md`, the notes win, since they were seen on this phone. So the second time it opens a free pack it knows the way. A lost game or a failed task gets a lesson — what decided it and what to try next — which stays "to try" until it has helped twice; choices such as a deck against an opponent go by the win/loss tally in the notes. The notes are outside the package; `pig update` leaves them alone, and you can read or correct them.

---

## `/android` command

| Command | Description |
|---|---|
| `/android status` | Devices, screen size and foreground app (the default) |
| `/android connect <ip:port>` | Connect over Wi-Fi |
| `/android disconnect [ip:port]` | Disconnect |
| `/android screenshot` | Take a screenshot and print where it was saved |
| `/android background [off]` | See above |

---

## Live view in the Web UI

With the [Web UI](/docs/latest/web), `GET /api/android/stream` is a page showing the phone's main screen, refreshed every 0.8 s; `/api/android/screen.jpg` is one screenshot.

---

## Configuration

| Variable | Meaning |
|---|---|
| `ADB_PATH` | The `adb` binary |
| `FFMPEG_PATH` | The `ffmpeg` binary. With it, main-screen screenshots come from the phone's hardware H.264 encoder (about 0.7 s instead of 2.3 s on a 2K screen) |

The package is Apache-2.0 and lives at [github.com/owner888/pig-android-use](https://github.com/owner888/pig-android-use). The helper's source is in `helper/src`; after changing it, rebuild the .dex with `ANDROID_HOME=~/Library/Android/sdk helper/build.sh`.
