---
name: android-use
description: Drive a real or emulated Android phone over ADB, in pure PHP. Last resort after the command line — when adb from bash can do it (start an activity, change a setting, push/pull/install), do that; use these screen tools only when the person asks for them or adb cannot do the job. Start a new task with android_ready to get the device channel and the current screen; supports taps, swipes, Chinese text input, launching apps by package and a screenshot when the view needs checking.
---

# Doing a task on an Android phone

The native `android_*` tools drive an Android phone or tablet.

**Try adb first.** Tapping the screen is a last resort. What `adb` can do from `bash` stays off the screen: open an app or a page with `adb shell am start`, type or press keys with `adb shell input`, change settings with `adb shell settings`, install or copy files with `adb install` / `adb push` / `adb pull`, read state with `adb shell dumpsys`. Use `android_*` only when the person asks for it, or when the task needs eyes on the screen (an app's own UI with no adb route).

Settle what the person wants delivered and when it is done. Treat an action that returned normally as done and move on; do not spend a separate call looking at the screen to prove each tap, swipe or keystroke worked. When the next step needs to see the screen, set `observe="screenshot"` on the action itself.

## 1. Ready check

Call this once before anything else:
```json
android_ready()
```
- Returns `ready=true` with the foreground app's package, the viewport size, the `pixel_to_point` ratio and a real screenshot.
- If no device is connected, ask the person to plug in a USB cable and turn on Developer options → USB debugging, or pair over wireless debugging.
- A screen that is off is woken by `android_ready` itself.

## 2. Tapping by coordinates

- Screenshots come from ADB and are scaled down (short side at most 768px), so each one is cheap.
- **Coordinates**: pass the pixel `(px, py)` you see in the screenshot straight to `android_tap(x=px, y=py)`; the tool converts it back to the device's resolution with the returned `pixel_to_point` ratio.
- **Look in the same call**: if a tap changes the page and the next step needs to see it, set `observe="screenshot"` so the tap and the new screenshot come back together.

## 3. The view tree

- **Exact targets in native apps**: when you need the exact text in a native app (Settings, WeChat, shopping apps), a list of search results, or the screenshot is not clear enough, call `android_tree(max_nodes=60)`.
- It returns the useful nodes with `type`, `text`, `desc`, `id` and a converted `rect: [x, y, w, h]`; tap the centre to interact. Games that draw their own UI have no tree; use the screenshot.

## 4. Batches

- **Known sequences**: for a fixed run of steps (tap the search box → type → confirm):
  `android_batch(steps=[{"op": "tap", "args": {"x": 100, "y": 200}}, {"op": "type_text", "args": {"text": "search text"}}], observe="screenshot")`
- Each tap gets ±2px of jitter and 150–350ms random pauses are inserted between steps.

## 5. Swiping and scrolling

- **By direction**: to move through a long list, `android_swipe(direction="up")` (swipes up to show what is below).
- **Exact drags**: to drag a card, icon or slider to a place, give both ends: `android_swipe(from_x=..., from_y=..., to_x=..., to_y=..., duration_ms=500)`.

## 6. Typing text

- Tap the field first, then `android_type_text(text="what to type")`.
- Chinese, English, symbols and multiple lines all work; the text goes through the Android clipboard, so no extra keyboard app is needed.

## 7. Buttons and launching apps

- **Navigation buttons**:
  - Back: `android_press_button(name="back")`
  - Home: `android_press_button(name="home")`
  - Recent apps: `android_press_button(name="app_switch")`
- **Launch an app** by package: `android_launch_app(package="com.tencent.mm")`.

## 8. When to stop and hand over

- **Something in the way**: a coupon, promotion or permission dialog over the screen — find its "X" or "Close/Cancel" and dismiss it, then carry on.
- **Passwords and biometrics**: at a lock-screen password, a WeChat/Alipay payment password, or a face/fingerprint check, stop and tell the person to finish it on the phone themselves; continue once they have.

## 9. Fast decision loop with decision models (System 1 / Jev Fast-Loop)

To eliminate the multi-second round-trip delay of streaming screenshots back to the main LLM for every step, use layered execution:

1. **CLI First**: Whenever `adb shell` (`am`, `pm`, `input`, `settings`) can accomplish the goal, never touch the screen.
2. **Fast Decision Loop**: Inside `codemode`, run a self-contained loop: fetch `android_tree` → pass interactive candidate nodes to a fast decision model (`$models->classify()`) → execute `android_tap`. No intermediate screenshots are sent to the primary model, completing each step in hundreds of milliseconds.
3. **Multi-Provider Failover**: Use `$models->getAvailableOfType('classifier')` to discover configured providers dynamically. If any provider returns 401 or hits quota limits, cascade seamlessly to the next available decision model (e.g. TypeSafe Jev → OpenRouter → Cloudflare).
4. **Vision Fallback**: Only fallback to `android_observe(mode="screenshot")` when the view tree yields no interactive candidates, a custom Canvas surface is present, or automated decisions repeatedly fail.

```php
// Dynamic multi-provider classifier fallback in codemode
function decideNode($models, $context) {
    $classifiers = $models->getAvailableOfType('classifier');
    foreach ($classifiers as $c) {
        $res = $models->classify($c, $context);
        if (($res['stopReason'] ?? '') === 'stop') {
            return $res['answers'];
        }
    }
    return null;
}

$tree = json_decode($tools->android_tree(['max_nodes' => 30]), true);
$criteria = [];
$nodes = [];
foreach ($tree['nodes'] as $i => $n) {
    $label = trim(($n['text'] ?? '') . ' ' . ($n['desc'] ?? '') . ' ' . ($n['id'] ?? ''));
    if ($label !== '' && isset($n['rect'])) {
        $key = 'node_' . $i;
        $criteria[$key] = $label;
        $nodes[$key] = $n;
    }
}

$answers = decideNode($models, [
    'state' => ['instruction' => 'Tap the install button'],
    'questions' => [
        'target' => [
            'type' => 'choice',
            'instructions' => 'Choose the interactive element that best satisfies the instruction',
            'criteria' => $criteria,
        ]
    ]
]);

$choice = $answers['target']['choice'] ?? null;
if ($choice && isset($nodes[$choice])) {
    [$x, $y, $w, $h] = $nodes[$choice]['rect'];
    $tools->android_tap(['x' => $x + $w / 2, 'y' => $y + $h / 2]);
}
```

