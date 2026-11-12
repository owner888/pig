# Computer Use (pig-computer)

The `pig-computer` extension lets the pig coding agent see and operate the macOS desktop: it takes screenshots, clicks, drags, scrolls, types, presses keys, and opens applications, using only PHP and the tools macOS ships with.

There is no browser service, no Docker container, and no Python or Node.js bridge. Screenshots come from `/usr/sbin/screencapture`, are scaled and compressed with `/usr/bin/sips`, and mouse and keyboard input goes through `/usr/bin/osascript` (JXA with CoreGraphics events, and System Events for keystrokes). The extension works only on macOS; on other systems the tools report that desktop automation is unavailable.

---

## Install

`pig-computer` is a package of its own ([github.com/owner888/pig-computer](https://github.com/owner888/pig-computer), MIT):

```bash
pig install git:github.com/owner888/pig-computer
```

It used to be bundled with pig; `pig update` removes the old bundled copy and prints the line above. Without it installed, pig has no desktop tools at all — useful when it also drives a phone ([Android Use](/docs/latest/android)) and "open Telegram" should mean the phone's.

The terminal (or the process that runs pig) needs two macOS permissions, granted in **System Settings → Privacy & Security**:

- **Screen Recording**, for `screencapture`.
- **Accessibility**, for synthesized mouse and keyboard input.

The extension also ships a `computer-use` skill that tells the model how to use the tools.

---

## Quickstart

Check the environment from a pig session:

```text
/computer status
```

It prints the logical screen size in points, the frontmost application, and the automation engine. Then simply prompt pig in natural language:

> **Open Safari, go to the project's issue tracker, and summarize the newest five issues**

The model typically:

1. calls `computer_ready` to get the screen size, the frontmost app, and a first screenshot,
2. calls `computer_launch_app(target: "Safari")` or opens a URL,
3. clicks with `computer_click(x, y, observe: "screenshot")` to act and see the result in one step,
4. types with `computer_type_text` and confirms with `computer_press_key(key: "enter")`.

---

## Coordinates

Screenshots are scaled down so the long side is at most 1568 pixels and the short side at most 980, then saved as JPEG under `~/.pig/agent/artifacts/` (`desktop-<timestamp>-<hash>.jpg`). Each observation reports `pixel_to_point`, the ratio between screenshot pixels and logical screen points. The model passes coordinates as it sees them in the screenshot; the tools convert them to points using the ratio of the last observation, so clicks land correctly on Retina displays. Clicks add a jitter of up to 2 points.

---

## Tools

| Tool | Parameters | Description |
|---|---|---|
| `computer_doctor` | none | Report the platform, whether automation is available, the screen size, the frontmost app, and whether `screencapture` and `osascript` exist |
| `computer_ready` | `screenshot` (default `true`) | Check the control channel and return the screen size, frontmost app, `pixel_to_point`, and a first screenshot. Call it first in a new task |
| `computer_observe` | `mode`: `screenshot`, `info`, or `both` (default) | Capture the screen and the frontmost app; `info` leaves the image out |
| `computer_click` | `x`, `y`, `button` (`left`, `right`, `middle`), `click_count` (2 for a double click), `observe` | Click at screenshot coordinates |
| `computer_drag` | `from_x`, `from_y`, `to_x`, `to_y`, `duration_ms` (default 300), `observe` | Drag the mouse between two points |
| `computer_scroll` | `delta_y` (positive down), `delta_x` (positive right, default 0), `observe` | Scroll the mouse wheel |
| `computer_type_text` | `text` | Type into the focused field. Plain ASCII is typed as keystrokes; CJK text, quotes, and multi-line text are pasted through the clipboard (`pbcopy` and Command+V) |
| `computer_press_key` | `key` | Press a key or a combination such as `enter`, `tab`, `escape`, `cmd+c`, `cmd+space` |
| `computer_launch_app` | `target` | Launch or focus an application by name (`"Google Chrome"`, `"Finder"`) or open a URL |
| `computer_batch` | `steps`, `observe` (default `screenshot`) | Run up to 15 steps in order, with a random 120–300 ms pause between them |

`observe` on the action tools is `none` (default), `screenshot`, or `both`; with a screenshot, the result carries the new screen next to the action's JSON report.

Each `computer_batch` step is `{ "op": ..., "args": { ... } }`, where `op` is `click`, `drag`, `scroll`, `type_text`, `press_key`, or `wait` (`args.seconds`, 0.1 to 10), and `args` takes the same fields as the matching tool.

```json
{
  "steps": [
    { "op": "click", "args": { "x": 640, "y": 88 } },
    { "op": "type_text", "args": { "text": "pig agent" } },
    { "op": "press_key", "args": { "key": "enter" } },
    { "op": "wait", "args": { "seconds": 1.5 } }
  ]
}
```

---

## `/computer` command

| Command | Description |
|---|---|
| `/computer status` | Screen size, frontmost app, and automation engine (the default without arguments) |
| `/computer screenshot` | Capture the screen and print the image size, the scale ratio, and where the JPEG was saved |
| `/computer app <name>` | Open or focus an application, for example `/computer app Google Chrome` |

---

## Live view in the Web UI

When pig runs as the [Web UI](/docs/latest/web), the extension registers HTTP routes on the web server:

| Route | Response |
|---|---|
| `GET /api/computer/screen` | JSON with the frontmost app, viewport and screen sizes, `pixel_to_point`, and an `image_url` |
| `GET /api/computer/screen.jpg` | A fresh screenshot as JPEG |
| `GET /api/computer/stream` | A page that refreshes the screenshot every second |

These routes capture the real screen; expose the web server only where that is acceptable.
