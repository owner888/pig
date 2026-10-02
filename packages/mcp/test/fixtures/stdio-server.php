<?php

declare(strict_types=1);

/**
 * The smallest MCP server that can be spoken to over stdio, for `StdioTransportTest`.
 *
 * One JSON-RPC message per line in, one per line out. `initialize`, `ping`, `tools/list` with
 * one tool, `tools/call` echoing its arguments. A few behaviours are switched on by argv so a
 * test can see the transport cope with them:
 *
 *   --chatty      write a line to stderr at startup and on every request
 *   --slow-start  sleep 300ms before reading anything
 *   --crlf        end lines with \r\n
 *   --exit-on     exit (code 3) when the method named next arrives, mid-conversation
 *   --garbage     write a line that is not JSON before the first reply
 *   --ignore-stdin-close  keep running after stdin closes (so SIGTERM has to do it)
 *   --log-on-call send a `notifications/message` before answering each `tools/call`
 *   --resources   offer resources: two pages of `resources/list`, one template, readable URIs,
 *                 and an MCP App resource that listings leave out
 */

$options = array_slice($argv, 1);
$flag = static fn (string $name): bool => in_array($name, $options, true);
$exitOn = null;

foreach ($options as $at => $option) {
    if ($option === '--exit-on') {
        $exitOn = $options[$at + 1] ?? null;
    }
}

$eol = $flag('--crlf') ? "\r\n" : "\n";

if ($flag('--chatty')) {
    fwrite(STDERR, "fixture server starting\n");
}

if ($flag('--slow-start')) {
    usleep(300_000);
}

if ($flag('--garbage')) {
    fwrite(STDOUT, "this is not json\n");
}

$reply = static function (array $message) use ($eol): void {
    fwrite(STDOUT, json_encode($message) . $eol);
    fflush(STDOUT);
};

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);

    if ($line === '') {
        continue;
    }

    $message = json_decode($line, true);

    if (!is_array($message)) {
        continue;
    }

    $method = $message['method'] ?? '';

    if ($flag('--chatty')) {
        fwrite(STDERR, "got {$method}\n");
    }

    if ($exitOn !== null && $method === $exitOn) {
        exit(3);
    }

    if (!array_key_exists('id', $message)) {
        continue;   // a notification
    }

    $id = $message['id'];
    $params = $message['params'] ?? [];

    if ($method === 'tools/call' && $flag('--log-on-call')) {
        $reply(['jsonrpc' => '2.0', 'method' => 'notifications/message', 'params' => ['level' => 'warning', 'logger' => 'echo', 'data' => "called with\n" . json_encode($params['arguments'] ?? [])]]);
    }

    $result = match ($method) {
        'initialize' => [
            'protocolVersion' => '2025-11-25',
            'capabilities' => ['tools' => new stdClass(), ...($flag('--resources') ? ['resources' => new stdClass()] : [])],
            'serverInfo' => ['name' => 'fixture', 'version' => '1.0'],
        ],
        'ping' => new stdClass(),
        'tools/list' => ['tools' => [['name' => 'echo', 'inputSchema' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string']]]]]],
        'tools/call' => ['content' => [['type' => 'text', 'text' => 'echo: ' . ($params['arguments']['text'] ?? '')]]],
        'resources/list' => !$flag('--resources') ? null : (($params['cursor'] ?? null) === 'page2'
            ? ['resources' => [
                ['uri' => 'file:///notes.txt', 'name' => 'notes', 'mimeType' => 'text/plain', 'size' => 5, 'icons' => [['src' => 'x']]],
                ['uri' => 'ui://app/widget', 'name' => 'widget', 'mimeType' => 'text/html;profile=mcp-app'],
            ]]
            : ['resources' => [['uri' => 'file:///readme.md', 'name' => 'readme', 'mimeType' => 'text/markdown', '_meta' => ['x' => 1]]], 'nextCursor' => 'page2']),
        'resources/templates/list' => !$flag('--resources') ? null : ['resourceTemplates' => [['uriTemplate' => 'file:///{path}', 'name' => 'any file']]],
        'resources/read' => !$flag('--resources') ? null : match ($params['uri'] ?? '') {
            'file:///readme.md' => ['contents' => [['uri' => 'file:///readme.md', 'mimeType' => 'text/markdown', 'text' => '# Hello']]],
            'file:///notes.txt' => ['contents' => [['uri' => 'file:///notes.txt', 'mimeType' => 'text/plain', 'text' => 'notes']]],
            'file:///dir' => ['contents' => [
                ['uri' => 'file:///dir/a', 'mimeType' => 'text/plain', 'text' => 'A'],
                ['uri' => 'file:///dir/b.png', 'mimeType' => 'image/png', 'blob' => base64_encode("\x89PNG")],
            ]],
            'file:///empty' => ['contents' => []],
            default => null,
        },
        default => null,
    };

    if ($result === null) {
        $reply(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => "Method not found: {$method}"]]);
        continue;
    }

    $reply(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
}

if ($flag('--ignore-stdin-close')) {
    // Pretend to be a server that does not notice stdin going away.
    sleep(30);
}
