# RPC Extension UI

Extensions can request user interaction through `$ctx->ui`. In RPC mode, supported calls become a request/response subprotocol alongside normal [RPC commands](/docs/latest/rpc-commands) and [session events](/docs/latest/json).

There are two categories of extension UI methods:

- **Dialog methods** (`select`, `confirm`, `input`, `editor`): emit an `extension_ui_request` on stdout and block until the client sends back an `extension_ui_response` on stdin with the matching `id`.
- **Fire-and-forget methods** (`notify`, `setStatus`, `setWidget`, `setTitle`, `set_editor_text`): emit an `extension_ui_request` on stdout but do not expect a response. The client can display the information or ignore it.

Every request carries a fresh UUID as its `id`. If a dialog method includes a `timeout` field, the agent-side will auto-resolve with a default value when the timeout expires. The client does not need to track timeouts. pig sends no cancel request when it withdraws a dialog (for example after a timeout); a late response is ignored.

## Limitations

Some `HookUi` methods (`Pig\CodingAgent\Rpc\RpcUi` in RPC mode) are not supported or degraded because they require direct terminal UI access:

- `custom()` returns `null`.
- `onTerminalInput()` returns a no-op unsubscribe closure.
- `setWorkingMessage()`, `setWorkingVisible()`, `setWorkingIndicator()`, `setHiddenThinkingLabel()`, `setFooter()`, `setHeader()`, `addAutocompleteProvider()`, `setEditorComponent()`, and `setToolsExpanded()` are no-ops.
- `getEditorText()` returns `''` and `getEditorComponent()` returns `null`.
- `getToolsExpanded()` returns `false`.
- `pasteToEditor()` delegates to `setEditorText()` without terminal paste handling.
- `getAllThemes()` returns `[]` and `getTheme()` returns `null` when the host has not declared a theme. Once the host declares its theme via `set_theme` (such as Web UI), available theme files are returned.
- `setTheme()` returns `['success' => false, 'error' => 'Theme switching not supported in RPC mode']` by default. When the host has declared its theme, switching by name is supported and emits a `setTheme` request to the host.

Note: `$ctx->mode()` is `'rpc'` and `$ctx->hasUi` is `true` in RPC mode because the dialog and fire-and-forget methods are functional via the extension UI sub-protocol. Check `$ctx->mode()` before using TUI-specific features like `custom()` that require a real terminal.

## Requests from pig

All requests have `type: "extension_ui_request"`, a unique `id`, and a `method` field.

### select

Prompt the user to choose from a list. Dialog methods with a `timeout` field include the timeout in milliseconds; the agent auto-resolves with `null` if the client doesn't respond in time.

```json
{
  "type": "extension_ui_request",
  "id": "uuid-1",
  "method": "select",
  "title": "Allow dangerous command?",
  "options": ["Allow", "Block"],
  "timeout": 10000
}
```

Expected response: `extension_ui_response` with `value` (the selected option string) or `cancelled: true`.

### confirm

Prompt the user for yes/no confirmation.

```json
{
  "type": "extension_ui_request",
  "id": "uuid-2",
  "method": "confirm",
  "title": "Clear session?",
  "message": "All messages will be lost.",
  "timeout": 5000
}
```

Expected response: `extension_ui_response` with `confirmed: true/false` or `cancelled: true`.

### input

Prompt the user for free-form text.

```json
{
  "type": "extension_ui_request",
  "id": "uuid-3",
  "method": "input",
  "title": "Enter a value",
  "placeholder": "type something..."
}
```

Expected response: `extension_ui_response` with `value` (the entered text) or `cancelled: true`.

### editor

Open a multi-line text editor with optional prefilled content.

```json
{
  "type": "extension_ui_request",
  "id": "uuid-4",
  "method": "editor",
  "title": "Edit some text",
  "prefill": "Line 1\nLine 2\nLine 3"
}
```

Expected response: `extension_ui_response` with `value` (the edited text) or `cancelled: true`.

### notify

Display a notification. Fire-and-forget, no response expected.

```json
{
  "type": "extension_ui_request",
  "id": "uuid-5",
  "method": "notify",
  "message": "Command blocked by user",
  "notifyType": "warning"
}
```

The `notifyType` field is `"info"`, `"warning"`, or `"error"`. Defaults to `"info"` if omitted.

### setStatus

Set or clear a status entry in the footer/status bar. Fire-and-forget.

```json
{
  "type": "extension_ui_request",
  "id": "uuid-6",
  "method": "setStatus",
  "statusKey": "my-ext",
  "statusText": "Turn 3 running..."
}
```

A request without `statusText` clears the status entry for that key.

### setWidget

Set or clear a widget (block of text lines) displayed above or below the editor. Fire-and-forget.

```json
{
  "type": "extension_ui_request",
  "id": "uuid-7",
  "method": "setWidget",
  "widgetKey": "my-ext",
  "widgetLines": ["--- My Widget ---", "Line 1", "Line 2"],
  "widgetPlacement": "aboveEditor"
}
```

A request without `widgetLines` clears the widget. The `widgetPlacement` field is `"aboveEditor"` (default) or `"belowEditor"`; an extension's `['placement' => 'above']` or `'below'` maps to these. Only string arrays are supported in RPC mode; component factories are ignored.

### setTitle

Set the terminal window/tab title. Fire-and-forget.

```json
{
  "type": "extension_ui_request",
  "id": "uuid-8",
  "method": "setTitle",
  "title": "pig - my project"
}
```

### set_editor_text

Set the text in the input editor. Fire-and-forget. `setEditorText()` and `pasteToEditor()` both send it.

```json
{
  "type": "extension_ui_request",
  "id": "uuid-9",
  "method": "set_editor_text",
  "text": "prefilled text for the user"
}
```

### setTheme

Notify the host to switch its rendering theme (sent when an extension calls `$ctx->ui->setTheme()`). Fire-and-forget.

```json
{
  "type": "extension_ui_request",
  "id": "uuid-10",
  "method": "setTheme",
  "theme": "light"
}
```

## Responses to pig

Responses are sent for dialog methods only (`select`, `confirm`, `input`, `editor`). The `id` must match the request.

### Value response (select, input, editor)

```json
{"type": "extension_ui_response", "id": "uuid-1", "value": "Allow"}
```

### Confirmation response (confirm)

```json
{"type": "extension_ui_response", "id": "uuid-2", "confirmed": true}
```

### Cancellation response (any dialog)

Dismiss any dialog method. The extension receives `null` (for select/input/editor) or `false` (for confirm).

```json
{"type": "extension_ui_response", "id": "uuid-3", "cancelled": true}
```

## Example

An extension that asks before a destructive command works the same in the terminal and over RPC:

```php
$pig->on('tool_call', function ($event, $ctx) {
    if ($event->toolName === 'bash' && str_contains($event->input['command'] ?? '', 'rm -rf')
        && !$ctx->ui->confirm('Allow?', $event->input['command'], timeout: 30000)) {
        return new ToolCallEventResult(block: true, reason: 'Blocked by user');
    }

    return null;
});
```

Over RPC, the `confirm()` call becomes a `confirm` request; the client answers with `confirmed`, and a timeout or `cancelled: true` blocks the command.

The requests are built in [`RpcUi.php`](https://github.com/owner888/pig/blob/main/packages/coding-agent/src/Rpc/RpcUi.php) and the responses dispatched in [`RpcMode.php`](https://github.com/owner888/pig/blob/main/packages/coding-agent/src/Rpc/RpcMode.php). See [Extensions](/docs/latest/extensions) for mode-independent extension guidance.
