<?php

/**
 * A stand-in for `pig --mode rpc` that speaks just enough of the protocol for the web shell and
 * `SessionPool` to be tested without a model.
 *
 * The event shapes are `RpcEvents::encode()`'s — a streamed answer is `message_update` lines
 * carrying upstream's `{usage, assistantMessageEvent: {type: "text_delta", delta}}`, not a bare
 * `text_delta` — because the page is written to the real wire and a fixture speaking a simpler
 * one would pass tests the real child fails. A turn ends in `agent_settled`, as a real one does. Remembers what was said, so `get_messages` after
 * a prompt answers with it the way a real child's session would.
 *
 * Ignores `--mode rpc` on its argv; honours `--session <path>` as the file it reports; a
 * `prompt` may carry `holdMs` for how long the turn should appear to take. A message starting
 * `ask:` asks a hook's `confirm` and waits for the answer; one starting `fail:` ends in an error.
 */

declare(strict_types=1);

$sessionFile = null;
foreach ($argv as $i => $arg) {
    if ($arg === '--session' && isset($argv[$i + 1])) {
        $sessionFile = $argv[$i + 1];
    }
}
$sessionFile ??= '/tmp/fake-' . getmypid() . '.jsonl';

$messages = [];
$streaming = false;
$out = static function (array $line): void {
    fwrite(STDOUT, json_encode($line) . "\n");
    fflush(STDOUT);
};

fwrite(STDERR, "fake agent up pid=" . getmypid() . "\n");

while (($raw = fgets(STDIN)) !== false) {
    $cmd = json_decode(trim($raw), true);
    if (!is_array($cmd)) {
        continue;
    }
    $id = $cmd['id'] ?? null;
    $type = $cmd['type'] ?? '';
    $respond = static fn (array $data = []) => $out(array_filter([
        'type' => 'response', 'command' => $type, 'id' => $id, 'success' => true, 'data' => $data ?: null,
    ], static fn ($v) => $v !== null));

    if ($type === 'get_state') {
        $respond([
            'sessionFile' => $sessionFile,
            'cwd' => getcwd(),
            'sessionName' => null,
            'opening' => '',
            'model' => ['id' => 'fake-1', 'name' => 'Fake', 'provider' => 'fake', 'contextWindow' => 100000, 'reasoning' => false],
            'thinkingLevel' => 'off',
            'isStreaming' => $streaming,
            'contextTokens' => 0,
            'pendingMessageCount' => 0,
            'pid' => getmypid(),
        ]);
    } elseif ($type === 'get_session_stats') {
        $respond(['tokens' => ['input' => 10, 'output' => 20, 'cacheRead' => 0, 'cacheWrite' => 0, 'total' => 30], 'cost' => 0.001]);
    } elseif ($type === 'get_messages') {
        $respond(['messages' => $messages]);
    } elseif ($type === 'prompt') {
        $text = (string) ($cmd['message'] ?? '');
        $messages[] = ['role' => 'user', 'content' => [['type' => 'text', 'text' => $text]], 'timestamp' => (int) (microtime(true) * 1000)];
        $respond(['disposition' => 'started']);
        $streaming = true;
        $out(['type' => 'agent_start']);
        $out(['type' => 'turn_start']);
        $reply = 'echo: ' . $text;
        $partial = ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => $reply]], 'stopReason' => 'stop'];
        // `ask:` parks the turn on a hook's question until it is answered, reading and answering
        // other commands meanwhile, as a real child's loop does; `fail:` ends the turn in an error.
        if (str_starts_with($text, 'ask:')) {
            $out(['type' => 'extension_ui_request', 'id' => 'q1', 'method' => 'confirm', 'title' => 'Allow?', 'message' => $text]);
            while (($inner = fgets(STDIN)) !== false) {
                $c = json_decode(trim($inner), true);
                if (!is_array($c)) {
                    continue;
                }
                if (($c['type'] ?? '') === 'extension_ui_response' && ($c['id'] ?? null) === 'q1') {
                    $reply = 'answered: ' . json_encode($c['confirmed'] ?? null);
                    // Long enough for a test to look at the server between the answer and the end.
                    $cmd['holdMs'] = 400;
                    break;
                }
                $out(array_filter([
                    'type' => 'response', 'command' => $c['type'] ?? '', 'id' => $c['id'] ?? null, 'success' => true,
                    'data' => ($c['type'] ?? '') === 'get_state' ? ['sessionFile' => $sessionFile, 'cwd' => getcwd(), 'isStreaming' => true] : null,
                ], static fn ($v) => $v !== null));
            }
        }
        // `show:` puts what an extension's `$ctx->ui` leaves on screen: a widget set twice, a status
        // set and another cleared, a title — so a page that binds later can be checked for the latest.
        if (str_starts_with($text, 'show:')) {
            foreach ([
                ['method' => 'setWidget', 'widgetKey' => 'w', 'widgetLines' => ['first']],
                ['method' => 'setWidget', 'widgetKey' => 'w', 'widgetLines' => ['latest'], 'widgetPlacement' => 'belowEditor'],
                ['method' => 'setStatus', 'statusKey' => 'kept', 'statusText' => 'on'],
                ['method' => 'setStatus', 'statusKey' => 'gone', 'statusText' => 'soon'],
                ['method' => 'setStatus', 'statusKey' => 'gone'],
                ['method' => 'setTitle', 'title' => 'Shown'],
                ['method' => 'notify', 'message' => 'said once', 'notifyType' => 'info'],
            ] as $i => $request) {
                $out(['type' => 'extension_ui_request', 'id' => "t{$i}", ...$request]);
            }
        }
        $out(['type' => 'message_start', 'message' => ['role' => 'assistant', 'content' => []]]);
        $out(['type' => 'message_update', 'usage' => ['input' => 0, 'output' => 0], 'assistantMessageEvent' => ['type' => 'text_delta', 'contentIndex' => 0, 'delta' => $reply]]);
        // A turn takes a moment, so a test can observe `running`.
        usleep((int) (($cmd['holdMs'] ?? 50) * 1000));
        $out(['type' => 'message_end', 'message' => $partial]);
        $messages[] = $partial + ['timestamp' => (int) (microtime(true) * 1000)];
        $out(['type' => 'turn_end']);
        $streaming = false;
        $out(array_filter(['type' => 'agent_end', 'messages' => [], 'willRetry' => false, 'error' => str_starts_with($text, 'fail:') ? 'boom' : null], static fn ($v) => $v !== null));
        $out(['type' => 'agent_settled', 'aborted' => false]);
    } elseif ($type === 'continue') {
        // What the conversation on disk ends on decides the answer, as `AgentSession::continueRun()`
        // decides it: a file holding `NOTHING` has nothing to carry on and is refused, one holding
        // `FINISHED` had already answered, anything else is carried on with one more turn.
        $saved = is_file($sessionFile) ? (string) file_get_contents($sessionFile) : '';
        if (str_contains($saved, 'NOTHING')) {
            $out(['type' => 'response', 'command' => $type, 'id' => $id, 'success' => false,
                'error' => 'There is nothing in this session to carry on from.']);
        } elseif (str_contains($saved, 'FINISHED')) {
            $respond(['outcome' => 'finished']);
        } else {
            $respond(['outcome' => 'continued']);
            $streaming = true;
            $out(['type' => 'agent_start']);
            $out(['type' => 'turn_start']);
            $partial = ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'carried on']], 'stopReason' => 'stop'];
            $out(['type' => 'message_start', 'message' => ['role' => 'assistant', 'content' => []]]);
            usleep(50_000);
            $out(['type' => 'message_end', 'message' => $partial]);
            $messages[] = $partial + ['timestamp' => (int) (microtime(true) * 1000)];
            $out(['type' => 'turn_end']);
            $streaming = false;
            $out(['type' => 'agent_end', 'messages' => [], 'willRetry' => false]);
            $out(['type' => 'agent_settled', 'aborted' => false]);
        }
    } elseif ($type === 'die') {
        exit(3);
    } else {
        $respond();
    }
}
