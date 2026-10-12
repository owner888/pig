---
name: computer-use
description: Last resort. Use only when the person asks for computer use, or when bash and the other tools cannot do the job (a native app with no command line, a page that must be clicked through while signed in). Downloading a file, calling an API, reading a web page or editing files is bash (curl) or fetch_web_page, never the desktop or a browser on it. When it is needed, start with computer_ready to get the screen.
---

# Doing a task on the Mac desktop with computer use

The native `computer_*` tools drive the real macOS desktop: browsers, IDEs, chat apps and everyday applications.

**This is a last resort.** Use `computer_*` in two cases only: the person asks for computer use, or `bash` and the other tools cannot do the job (a native app with no command line, a page that must be clicked through while signed in). Anything a command line can do stays off the desktop: download a file with `bash` running `curl -L -o FILE URL`, call an API with `curl`, read a page with `fetch_web_page`. Try the command line first; reach for the screen only when it fails.

Settle what the person wants delivered and when it is done. Treat an action that returned normally as done and move on to the next one; do not spend a separate call looking at the screen after every click or keystroke. When the next step needs to see the screen, set `observe="screenshot"` on the action itself.

## 1. Ready check

Call this once before anything else:
```json
computer_ready()
```
- Returns the main screen's logical size, the frontmost app (Chrome, Slack, Finder…), the `pixel_to_point` ratio and a first real screenshot.
- If the app you need is not in front, bring it forward with `computer_launch_app(target="App Name")`.

## 2. Clicking

- **Retina coordinates**: pass the pixel `(x, y)` you see in the screenshot straight to `computer_click(x=..., y=...)`; the tool converts it to logical points with the returned `pixel_to_point` ratio.
- **Click kinds**:
  - Single: `computer_click(x=..., y=..., button="left")`
  - Double (open a file, select a word): `computer_click(x=..., y=..., click_count=2)`
  - Context menu: `computer_click(x=..., y=..., button="right")`
- **Look in the same call**: if a click opens a dialog or changes the page, add `observe="screenshot"` so the click and the new screen come back together.

## 3. Dragging and scrolling

- **Move a window or select a region**: `computer_drag(from_x=..., from_y=..., to_x=..., to_y=..., duration_ms=400)`
- **Scroll a page**: `computer_scroll(delta_y=15)` (positive scrolls down, negative up).

## 4. Typing text

- Click the field first, then `computer_type_text(text="what to type")`.
- Text goes through the system clipboard, so Chinese, line breaks and symbols arrive intact.

## 5. Keys and shortcuts

- **Single keys**: `computer_press_key(key="enter")`, `computer_press_key(key="tab")`, `computer_press_key(key="escape")`
- **Shortcuts**:
  - Copy: `computer_press_key(key="cmd+c")`
  - Paste: `computer_press_key(key="cmd+v")`
  - Select all: `computer_press_key(key="cmd+a")`
  - Save: `computer_press_key(key="cmd+s")`
  - Switch app: `computer_press_key(key="cmd+tab")`
  - Spotlight: `computer_press_key(key="cmd+space")`

## 6. Batches

For a known sequence (click the search box → type → Enter):
```json
computer_batch(
  steps=[
    {"op": "click", "args": {"x": 240, "y": 180}},
    {"op": "type_text", "args": {"text": "latest earnings report"}},
    {"op": "press_key", "args": {"key": "enter"}}
  ],
  observe="screenshot"
)
```
The tool adds human-like pauses and a pixel of jitter between steps.
