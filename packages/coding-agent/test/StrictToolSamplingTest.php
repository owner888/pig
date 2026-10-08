<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use Pig\Agent\AgentTool;
use Pig\Agent\ToolArguments;
use Pig\Ai\Api;
use Pig\Ai\Context;
use Pig\Ai\Model;
use Pig\Ai\Providers\Google;
use Pig\Ai\Providers\GoogleOptions;
use Pig\Ai\ToolCall;
use Pig\Ai\UserMessage;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Tools\BashTool;
use Pig\CodingAgent\Tools\EditTool;
use Pig\CodingAgent\Tools\FindTool;
use Pig\CodingAgent\Tools\GrepTool;
use Pig\CodingAgent\Tools\LsTool;
use Pig\CodingAgent\Tools\ReadTool;
use Pig\CodingAgent\Tools\WriteTool;
use Pig\Test\CannedServer;

/**
 * The built-in tools' strict sampling, end to end: what they declare, what a provider that has
 * strict mode is sent, and what a strict-sampled call — nulls for everything left out — does.
 */
final class StrictToolSamplingTest extends ToolTestCase
{
    /**
     * Upstream's coding-agent tools: `constrainedSampling: { type: "json_schema", strict: "prefer" }`
     * on bash, edit, read and write, and on nothing else.
     */
    public function testTheToolsUpstreamMarksAskForStrictSamplingAndNoOthers(): void
    {
        foreach ($this->tools() as $tool) {
            $definition = $tool->definition();
            $expected = in_array($definition->name, ['bash', 'edit', 'read', 'write'], true)
                ? ['type' => 'json_schema', 'strict' => 'prefer']
                : null;

            $this->assertSame($expected, $definition->constrainedSampling, $definition->name);
        }
    }

    /**
     * The Gemini 3 `VALIDATED` mode the Google provider gained was unreachable from pig itself: no
     * built-in tool asked for strict sampling, so a coding session never sent it. Now the built-in
     * set does, and the strict tools go in their strict form while the others go as written.
     */
    public function testGeminiThreeIsSentTheBuiltInToolsStrictAndInValidatedMode(): void
    {
        Loop::reset();
        $server = new CannedServer();
        $body = 'data: ' . json_encode(['candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']]]) . "\n\n";
        $url = $server->start([
            "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nTransfer-Encoding: chunked\r\n\r\n",
            sprintf("%x\r\n%s\r\n", strlen($body), $body),
            "0\r\n\r\n",
        ]);

        $model = new Model('gemini-3-pro-preview', 'Gemini 3 Pro', Api::GoogleGenerativeAi, 'google', rtrim($url, '/'), 1_000_000, 64_000);
        $context = new Context(
            [new UserMessage('hi')],
            tools: array_map(static fn (AgentTool $tool) => $tool->definition(), $this->tools()),
        );

        Async::run(static function () use ($model, $context): void {
            $stream = (new Google())->stream($model, $context, new GoogleOptions(apiKey: 'test-key'));

            foreach ($stream as $ignored) {
                // Drain it; what is under test is what went out.
            }

            $stream->result()->await();
        });

        $sent = $server->receivedJson();
        $declared = [];

        foreach ($sent['tools'][0]['functionDeclarations'] as $declaration) {
            $declared[$declaration['name']] = $declaration['parametersJsonSchema'];
        }

        $this->assertSame(['functionCallingConfig' => ['mode' => 'VALIDATED']], $sent['toolConfig']);

        // read: every property required, the optional ones widened to take null.
        $this->assertSame(['path', 'offset', 'limit'], $declared['read']['required']);
        $this->assertSame(['type' => 'null'], $declared['read']['properties']['limit']['anyOf'][1]);
        $this->assertFalse($declared['read']['additionalProperties']);
        $this->assertSame(['command', 'timeout'], $declared['bash']['required']);

        // grep does not ask, so it goes as written.
        $this->assertSame(['pattern'], $declared['grep']['required']);
    }

    /**
     * What a strict-sampled call looks like: every optional parameter present, as null. It goes
     * through `ToolArguments` — the validation the agent loop runs — and then executes exactly as
     * the call that left them out does. pig refused it with "offset: must be number".
     */
    public function testAStrictCallWithNullsForItsOptionalParametersRunsLikeOneThatLeftThemOut(): void
    {
        $this->file('notes.txt', "one\ntwo\nthree");
        $read = new ReadTool($this->cwd);

        $arguments = ToolArguments::validate(
            $read->definition(),
            new ToolCall('c1', 'read', ['path' => 'notes.txt', 'offset' => null, 'limit' => null]),
        );

        $this->assertSame(['path' => 'notes.txt'], $arguments);
        $this->assertSame(
            $this->textOf($this->execute($read, ['path' => 'notes.txt'])),
            $this->textOf($this->execute($read, $arguments)),
        );

        $bash = new BashTool($this->cwd);
        $arguments = ToolArguments::validate($bash->definition(), new ToolCall('c2', 'bash', ['command' => 'echo hi', 'timeout' => null]));

        Loop::reset();
        $result = Async::run(fn () => $this->execute($bash, $arguments));

        $this->assertSame("hi\n", $this->textOf($result));
    }

    /**
     * Upstream's built-in tools are TypeBox schemas, so `validateToolArguments()` runs `Value.Convert`
     * over their arguments; a plain JSON schema is not converted. Every built-in here says so
     * (`Tool::$typeBox`), and `ToolArguments` then converts the way TypeBox does: a null for the
     * required `path` is the string "null", where pig made it "", and an empty string for a number
     * is 0, where pig refused it.
     */
    public function testEveryBuiltInToolIsConvertedTheWayUpstreamsTypeBoxSchemasAre(): void
    {
        foreach ($this->tools() as $tool) {
            $this->assertTrue($tool->definition()->typeBox, $tool->definition()->name);
        }

        $read = new ReadTool($this->cwd);

        $this->assertSame(
            ['path' => 'null', 'offset' => 0],
            ToolArguments::validate($read->definition(), new ToolCall('c1', 'read', ['path' => null, 'offset' => ''])),
        );
    }

    /** @return list<AgentTool> */
    private function tools(): array
    {
        return [
            new ReadTool($this->cwd),
            new BashTool($this->cwd),
            new EditTool($this->cwd),
            new WriteTool($this->cwd),
            new GrepTool($this->cwd),
            new FindTool($this->cwd),
            new LsTool($this->cwd),
        ];
    }
}
