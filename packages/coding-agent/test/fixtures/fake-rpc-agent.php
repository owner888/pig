<?php

/**
 * A stand-in for `pig --mode rpc` that speaks just enough of the protocol for the web shell and
 * `SessionPool` to be tested without a model.
 *
 * The event shapes are `RpcEvents::encode()`'s — a streamed answer is `message_update` lines
 * carrying `{delta: {type: "text_delta", delta}}` plus the whole message so far, not a bare
 * `text_delta` — because the page is written to the real wire and a fixture speaking a simpler
 * one would pass tests the real child fails. Remembers what was said, so `get_messages` after
 * a prompt answers with it the way a real child's session would.
 *
 * Ignores `--mode rpc` on its argv; honours `--session <path>` as the file it reports; a
 * `prompt` may carry `holdMs` for how long the turn should appear to take.
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
            'pid' => getmypid(),
        ]);
    } elseif ($type === 'get_session_stats') {
        $respond(['input' => 10, 'output' => 20, 'cost' => 0.001, 'totalTokens' => 30]);
    } elseif ($type === 'get_messages') {
        $respond(['messages' => $messages]);
    } elseif ($type === 'prompt') {
        $text = (string) ($cmd['message'] ?? '');
        $messages[] = ['role' => 'user', 'content' => [['type' => 'text', 'text' => $text]], 'timestamp' => (int) (microtime(true) * 1000)];
        $respond();
        $streaming = true;
        $out(['type' => 'agent_start']);
        $out(['type' => 'turn_start']);
        $reply = 'echo: ' . $text;
        $partial = ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => $reply]], 'stopReason' => 'stop'];
        $out(['type' => 'message_start', 'message' => ['role' => 'assistant', 'content' => []]]);
        $out(['type' => 'message_update', 'message' => $partial, 'delta' => ['type' => 'text_delta', 'contentIndex' => 0, 'delta' => $reply]]);
        // A turn takes a moment, so a test can observe `running`.
        usleep((int) (($cmd['holdMs'] ?? 50) * 1000));
        $out(['type' => 'message_end', 'message' => $partial]);
        $messages[] = $partial + ['timestamp' => (int) (microtime(true) * 1000)];
        $out(['type' => 'turn_end']);
        $streaming = false;
        $out(['type' => 'agent_end']);
    } elseif ($type === 'die') {
        exit(3);
    } else {
        $respond();
    }
}
