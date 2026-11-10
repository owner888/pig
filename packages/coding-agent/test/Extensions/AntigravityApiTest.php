<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Extensions;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\AssistantMessage;
use Pig\Ai\Context;
use Pig\Ai\Model;
use Pig\Ai\Pricing;
use PigAntigravity\AntigravityApi as Antigravity;
use Pig\Ai\Providers\GoogleOptions;
use Pig\Ai\StopReason;
use Pig\Ai\Tool;
use Pig\Ai\UserMessage;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\CannedServer;
use Pig\Ai\Utils\Transcript;

/**
 * What goes out on the wire, which is all this provider is.
 *
 * The Gemini request inside the envelope is `GoogleShared`'s and is asserted where that lives;
 * what is this provider's own is the envelope around it — the runtime model, the enum, the
 * labels, the ids — and the two hosts.
 */
final class AntigravityApiTest extends TestCase
{
    use LoadsAntigravity;

    private CannedServer $server;

    #[\Override]
    protected function setUp(): void
    {
        self::registerAntigravity();
        Loop::reset();
        // Which request is a turn's first is counted per process; every test starts the count over.
        Antigravity::forgetRequestNonces();
        $this->server = new CannedServer();
    }

    #[\Override]
    protected function tearDown(): void
    {
        \Pig\Ai\Extension\ProviderRegistry::forget();
        \PigAntigravity\Routing::forgetTables();
    }

    /** What signing in stores: a token and a Cloud project in one string. */
    private static function key(string $token = 'ya29.a', string $project = 'proj-1'): string
    {
        return (string) json_encode(['token' => $token, 'projectId' => $project]);
    }

    // ---- the envelope ---------------------------------------------------------------------

    public function testTheModelOnTheWireIsTheRuntimeOneAndNotTheOneChosen(): void
    {
        // The whole reason `Antigravity\Routing` exists: `gemini-3.8-flash` at medium is a
        // different upstream model, named by an enum.
        $this->send('gemini-3.8-flash', 'medium');

        $body = $this->server->receivedJson();

        $this->assertSame('gemini-3.8-flash-medium', $body['model']);
        $this->assertSame('MODEL_PLACEHOLDER_M319', $body['request']['labels']['model_enum']);
    }

    public function testTheLevelChangesWhatIsSent(): void
    {
        $this->send('gemini-3.8-flash', 'high');

        $this->assertSame('gemini-3.8-flash-high', $this->server->receivedJson()['model']);
    }

    public function testThinkingOffStillPicksARuntimeModelAndAsksForNoBudget(): void
    {
        $this->send('gemini-3.8-flash', null);

        $body = $this->server->receivedJson();

        $this->assertSame('gemini-3.8-flash-low', $body['model']);
        $this->assertArrayNotHasKey('thinkingConfig', $body['request']['generationConfig'] ?? []);
    }

    public function testTheBudgetComesFromTheRoutingTablesRatherThanTheCaller(): void
    {
        $this->send('gemini-3.8-flash', 'medium');

        $thinking = $this->server->receivedJson()['request']['generationConfig']['thinkingConfig'];

        $this->assertSame(4000, $thinking['thinkingBudget']);
        $this->assertTrue($thinking['includeThoughts']);
    }

    public function testTheTopLevelAsksForAnUncappedBudget(): void
    {
        $this->send('gemini-3.8-flash', 'high');

        $this->assertSame(-1, $this->server->receivedJson()['request']['generationConfig']['thinkingConfig']['thinkingBudget']);
    }

    public function testThinkingTurnedOffBeatsALevelThatWasLeftSet(): void
    {
        // The two arriving contradictory is not hypothetical: `Stream` sets both, and every other
        // provider here reads `thinkingEnabled: false` as off whatever the level says. Reading
        // only the level would have this turn think at `high`.
        $url = $this->serve([['response' => ['candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']]]]]);

        Async::run(function () use ($url): void {
            $stream = (new Antigravity())->stream(
                $this->model($url),
                Transcript::normalizeContext(new Context([new UserMessage('hello')])),
                new GoogleOptions(apiKey: self::key(), thinkingEnabled: false, thinkingLevel: 'high'),
            );

            foreach ($stream as $ignored) {
                // Drain it.
            }

            $stream->result()->await();
        });

        $body = $this->server->receivedJson();

        $this->assertSame('gemini-3-flash', $body['model']);
        $this->assertArrayNotHasKey('thinkingConfig', $body['request']['generationConfig'] ?? []);
    }

    public function testTheEnvelopeSaysItIsAnAgentAndCallsItselfAntigravity(): void
    {
        // Both are the reference implementation's, and the `userAgent` field in particular is
        // upstream's own fix for 429s — pig used to send `pig` here.
        $this->send('gemini-3-flash', 'low');

        $body = $this->server->receivedJson();

        $this->assertSame('agent', $body['requestType']);
        $this->assertSame('antigravity', $body['userAgent']);
        $this->assertSame('proj-1', $body['project']);
    }

    public function testTheUserAgentHeaderIsTheClientsOwn(): void
    {
        // This deployment answers 403 to the wrong one, so it is load bearing rather than
        // decorative.
        $this->send('gemini-3-flash', 'low');

        $this->assertStringContainsString('antigravity/cli/', $this->server->receivedHead());
        $this->assertStringContainsString('aidev_client', $this->server->receivedHead());
    }

    public function testAClaudeModelIsLabelledAsOneAndAGeminiIsNot(): void
    {
        $this->send('claude-sonnet-5-5', 'medium');
        $labels = $this->server->receivedJson()['request']['labels'];

        $this->assertSame('true', $labels['used_claude']);
        $this->assertSame('true', $labels['used_claude_conservative']);
        $this->assertSame('true', $labels['used_non_gemini_model']);

        $this->server = new CannedServer();
        $this->send('gemini-3-flash', 'medium');
        $labels = $this->server->receivedJson()['request']['labels'];

        $this->assertSame('false', $labels['used_claude']);
        $this->assertSame('false', $labels['used_non_gemini_model']);
    }

    public function testGptOssIsNotClaudeButIsNotGeminiEither(): void
    {
        $this->send('gpt-oss-120b', 'medium');
        $labels = $this->server->receivedJson()['request']['labels'];

        $this->assertSame('false', $labels['used_claude']);
        $this->assertSame('true', $labels['used_non_gemini_model']);
    }

    public function testTheSameConversationKeepsTheSameTrajectoryId(): void
    {
        // What the deployment caches against, so it has to be derived from the conversation and
        // not from the clock. Two turns of one conversation, two requests, one trajectory.
        $opening = new UserMessage('the opening line');

        $this->send('gemini-3-flash', 'low', new Context([$opening]));
        $first = $this->server->receivedJson()['request'];

        $this->server = new CannedServer();
        $this->send('gemini-3-flash', 'low', new Context([$opening, self::answered('ok'), new UserMessage('and then?')]));
        $second = $this->server->receivedJson()['request'];

        $this->assertSame($first['labels']['trajectory_id'], $second['labels']['trajectory_id']);
        $this->assertSame($first['sessionId'], $second['sessionId']);
    }

    public function testATurnSentAgainGoesOutAsADifferentSessionAndTrajectory(): void
    {
        // pi-antigravity 0.12: the deployment queues a session's requests behind one another, and
        // a stream that died may still be generating there — so the same turn asked for again
        // would wait behind it. Every request after a turn's first has an identity of its own.
        $context = new Context([new UserMessage('the opening line'), self::answered('ok'), new UserMessage('and then?')]);
        $identity = function (?string $sessionId) use ($context): array {
            $this->server = new CannedServer();
            $url = $this->serve([['response' => ['candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']]]]]);
            $this->drain(new Antigravity(), $this->model($url), 'gemini-3-flash', 'low', $context, sessionId: $sessionId);
            $body = $this->server->receivedJson();

            return [$body['request']['sessionId'], $body['request']['labels']['trajectory_id'], explode('/', $body['requestId'])[1]];
        };

        // With a session id: the id itself first, then the id with the count on it.
        $first = $identity('sess-1');
        $again = $identity('sess-1');
        $third = $identity('sess-1');
        $this->assertSame(Antigravity::toInt64SessionId('sess-1'), $first[0]);
        $this->assertSame(Antigravity::toInt64SessionId('sess-1:r1'), $again[0]);
        $this->assertSame(Antigravity::toInt64SessionId('sess-1:r2'), $third[0]);
        $this->assertSame(md5('antigravity:traj:sess-1:r1'), $again[1]);
        $this->assertNotSame($first[1], $again[1]);
        $this->assertNotSame($first[2], $again[2], 'the agent id in the request id is the retry\'s too');

        // Another session at the same point of the same conversation is not a retry of this one.
        $this->assertSame(Antigravity::toInt64SessionId('sess-2'), $identity('sess-2')[0]);

        // Without one: counted by the conversation, and identified by something random.
        $bare = $identity(null);
        $bareAgain = $identity(null);
        $this->assertSame($first[1], $bare[1], 'the trajectory is the conversation\'s either way');
        $this->assertNotSame($bare[0], $bareAgain[0]);
        $this->assertNotSame($bare[1], $bareAgain[1]);
        $this->assertNotSame($bareAgain, $identity(null));

        // A turn that failed is not a turn answered: the request after it is still the same turn's.
        Antigravity::forgetRequestNonces();
        $failed = new AssistantMessage([], Api::Extension, 'antigravity', 'gemini-3-flash', new \Pig\Ai\Usage(), StopReason::Error, 'boom');
        $this->server = new CannedServer();
        $this->send('gemini-3-flash', 'low', new Context([new UserMessage('q')]));
        $firstTry = $this->server->receivedJson()['request']['labels']['trajectory_id'];
        $this->server = new CannedServer();
        $this->send('gemini-3-flash', 'low', new Context([new UserMessage('q'), $failed, new UserMessage('q, again')]));
        $this->assertNotSame($firstTry, $this->server->receivedJson()['request']['labels']['trajectory_id']);
        $this->assertSame(md5('antigravity:traj:q'), $firstTry);
    }

    public function testADifferentConversationGetsADifferentTrajectoryId(): void
    {
        $this->send('gemini-3-flash', 'low', new Context([new UserMessage('one thing')]));
        $first = $this->server->receivedJson()['request']['labels']['trajectory_id'];

        $this->server = new CannedServer();
        $this->send('gemini-3-flash', 'low', new Context([new UserMessage('a different thing')]));

        $this->assertNotSame($first, $this->server->receivedJson()['request']['labels']['trajectory_id']);
    }

    public function testTheRequestIdCarriesTheTrajectoryAndTheStepCount(): void
    {
        $this->send('gemini-3-flash', 'low', new Context([new UserMessage('hello')]));

        $body = $this->server->receivedJson();
        $trajectory = $body['request']['labels']['trajectory_id'];

        $this->assertMatchesRegularExpression("~^agent/[0-9a-f]{16}/\d+/{$trajectory}/\d+$~", $body['requestId']);
        $this->assertSame('0', $body['request']['labels']['last_step_index']);
    }

    public function testAnOpeningTurnWithNoTextStillGetsAStableSeed(): void
    {
        // A first message that is only a picture has nothing to key on. It must not fall back to
        // something random, or every turn of that conversation is a different trajectory.
        $picture = new UserMessage([]);

        $this->send('gemini-3-flash', 'low', new Context([$picture]));
        $first = $this->server->receivedJson()['request']['labels']['trajectory_id'];

        $this->server = new CannedServer();
        $this->send('gemini-3-flash', 'low', new Context([$picture, self::answered('a cat'), new UserMessage('what colour?')]));

        $this->assertSame($first, $this->server->receivedJson()['request']['labels']['trajectory_id']);
    }

    public function testTheGeminiRequestIsStillInsideTheEnvelope(): void
    {
        $this->send('gemini-3-flash', 'low', new Context(
            [new UserMessage('hello')],
            'be brief',
            [new Tool('read', 'Read a file', ['properties' => ['path' => ['type' => 'string']]])],
        ));

        $inner = $this->server->receivedJson()['request'];

        $this->assertSame('hello', $inner['contents'][0]['parts'][0]['text']);
        $this->assertSame('be brief', $inner['systemInstruction']['parts'][0]['text']);
        $this->assertSame('read', $inner['tools'][0]['functionDeclarations'][0]['name']);
    }

    // ---- the two hosts --------------------------------------------------------------------

    public function testA403OnTheFirstHostIsTriedAgainOnTheSecond(): void
    {
        $refusing = new CannedServer();
        $refused = $refusing->start([self::refusal(403, 'not for you')]);

        $answering = $this->serve([['response' => ['candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']]]]]);

        $this->drain(new Antigravity(fallback: $answering), $this->model($refused), 'gemini-3-flash', 'low', new Context([new UserMessage('hi')]));

        // The second host is the one that saw the request through.
        $this->assertStringContainsString('gemini-3-flash', $this->server->receivedJson()['model']);
    }

    public function testAQuotaWallIsNotTriedOnTheOtherHostAndIsSaidInPiAntigravitysWords(): void
    {
        // pi-antigravity's request loop stops at a 429 that is a quota wall — every endpoint would
        // answer it the same way — and words it so that it matches none of the session's retryable
        // patterns: the turn ends at once, saying how long to wait. (pig used to name it "out of
        // quota" here and have the session read the wait out of it and rewrite it.)
        $quota = new CannedServer();
        $url = $quota->start([self::refusal(429, 'Individual quota reached. Resets in 2h3m.')]);

        $second = new CannedServer();
        $secondUrl = $second->start([self::refusal(200, 'unused')]);

        $message = $this->drain(new Antigravity(fallback: $secondUrl), $this->model($url), 'gemini-3-flash', 'low', new Context([new UserMessage('hi')]));

        $this->assertSame('Quota reached. Please wait 2h3m. Next: switch models or try again after reset.', $message);
        $this->assertSame('', $second->receivedHead(), 'the second host was never asked');
    }

    public function testATransient429IsTriedOnTheOtherHostAndThenReadsAsThrottling(): void
    {
        // `[403, 404, 429, 500, 502, 503, 504]` move on to the next endpoint; a 429 that says it
        // is rate limiting is throttling, worded so that the session's backoff engages.
        $first = new CannedServer();
        $url = $first->start([self::refusal(429, 'Rate limit exceeded: slow down')]);

        $second = new CannedServer();
        $secondUrl = $second->start([self::refusal(429, 'Too many requests')]);

        $message = $this->drain(new Antigravity(fallback: $secondUrl), $this->model($url), 'gemini-3-flash', 'low', new Context([new UserMessage('hi')]));

        // pi-antigravity's own shape for every refusal that is not a quota wall:
        // `Antigravity API error (<status>, <diagnostics>): <friendly>`.
        $this->assertStringStartsWith('Antigravity API error (429, endpoint=' . $secondUrl, (string) $message);
        $this->assertStringEndsWith('): Rate limited by Antigravity (429 ResourceExhausted). Next: retrying automatically; if it persists, switch models.', (string) $message);
        $this->assertTrue(\Pig\Ai\Utils\Retry::isRetryableAssistantError(new \Pig\Ai\AssistantMessage([], \Pig\Ai\Api::Extension, 'antigravity', 'm', new \Pig\Ai\Usage(), \Pig\Ai\StopReason::Error, (string) $message)), 'still throttling to the session');
        $this->assertNotSame('', $second->receivedHead(), 'the second host was asked');
    }

    public function testA429ThatNamesNoLimitIsAModelNotBeingServedAndIsNeitherWalkedNorRetried(): void
    {
        // pi-antigravity 0.11: "A generic RESOURCE_EXHAUSTED body, with no reset hint, is how
        // unavailable models fail." Every endpoint would say the same, the session's backoff would
        // spend itself on it, and it is not this account's quota either.
        $first = new CannedServer();
        $url = $first->start([self::refusal(429, 'Resource has been exhausted (e.g. check quota).')]);

        $second = new CannedServer();
        $secondUrl = $second->start([self::refusal(200, 'unused')]);
        $asked = 0;
        $failover = static function () use (&$asked): ?string {
            $asked++;

            return null;
        };

        $message = (string) $this->drain(new Antigravity(fallback: $secondUrl, failover: $failover), $this->model($url), 'gemini-3-flash', 'low', new Context([new UserMessage('hi')]));

        // No status in it: the digits alone are what the session's retry pattern reads.
        $this->assertSame(
            'Antigravity API error (request-failed, endpoint=' . rtrim($url, '/') . ', project=proj-1, runtimeModel=gemini-3-flash, matched=static:gemini-3-flash, available=' . implode(',', \PigAntigravity\Routing::models()) . '): '
            . 'This model is unavailable right now (Resource has been exhausted (e.g. check quota).). Next: switch to gemini-3.8-flash or another Flash model. This is not necessarily an account quota wall.',
            $message,
        );
        $this->assertFalse(\Pig\Ai\Utils\Retry::isRetryableAssistantError(new AssistantMessage([], Api::Extension, 'antigravity', 'm', new \Pig\Ai\Usage(), StopReason::Error, $message)));
        $this->assertSame('', $second->receivedHead(), 'the second host was never asked');
        $this->assertSame(0, $asked, 'and no other account either: it is not a quota wall');

        // The statuses the session does retry keep their number — where pig is narrower than upstream.
        $busy = new CannedServer();
        $busyUrl = $busy->start([self::refusal(503, 'The service is currently unavailable.')]);
        $busyMessage = (string) $this->drain(new Antigravity(fallback: rtrim($busyUrl, '/')), $this->model($busyUrl), 'gemini-3-flash', 'low', new Context([new UserMessage('busy')]));
        $this->assertStringStartsWith('Antigravity API error (503, endpoint=', $busyMessage);
        $this->assertTrue(\Pig\Ai\Utils\Retry::isRetryableAssistantError(new AssistantMessage([], Api::Extension, 'antigravity', 'm', new \Pig\Ai\Usage(), StopReason::Error, $busyMessage)));
    }

    public function testTheModelThatActuallyAnsweredIsOnTheMessage(): void
    {
        // pi-antigravity 0.12: the gateway may serve another model than the runtime one asked for,
        // and says which in `modelVersion`; the session file should show it.
        $url = $this->serve([
            ['response' => ['modelVersion' => 'gemini-3-flash-001', 'responseId' => 'resp-1', 'candidates' => [['content' => ['parts' => [['text' => 'o']]]]]]],
            ['response' => ['modelVersion' => 'gemini-3-flash-002', 'candidates' => [['content' => ['parts' => [['text' => 'k']]], 'finishReason' => 'STOP']]]],
        ]);

        $message = Async::run(function () use ($url): AssistantMessage {
            $stream = (new Antigravity())->stream($this->model($url), Transcript::normalizeContext(new Context([new UserMessage('hi')])), new GoogleOptions(apiKey: self::key()));

            foreach ($stream as $ignored) {
                // Drained for its result.
            }

            return $stream->result()->await();
        });

        $this->assertSame('gemini-3-flash', $message->model, 'the model that was asked for');
        $this->assertSame('gemini-3-flash-002', $message->responseModel);
        $this->assertSame('resp-1', $message->responseId);
    }

    public function testAnEndpointThatCannotBeReachedIsPassedOverForTheNextOne(): void
    {
        // pi-antigravity 0.12: a transport failure used to end the turn without the other
        // endpoint ever being asked. Port 1 refuses the connection.
        $answering = $this->serve([['response' => ['candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']]]]]);

        $this->assertNull($this->drain(new Antigravity(fallback: $answering), $this->model('http://127.0.0.1:1'), 'gemini-3-flash', 'low', new Context([new UserMessage('hi')])));
        $this->assertSame('gemini-3-flash', $this->server->receivedJson()['model']);

        // When none can be: the transport's own words, which are what the session retries on —
        // not "No endpoint to try".
        $message = (string) $this->drain(new Antigravity(fallback: 'http://127.0.0.1:1'), $this->model('http://127.0.0.1:1'), 'gemini-3-flash', 'low', new Context([new UserMessage('again')]));
        $this->assertStringContainsStringIgnoringCase('connect', $message);
        $this->assertTrue(\Pig\Ai\Utils\Retry::isRetryableAssistantError(new AssistantMessage([], Api::Extension, 'antigravity', 'm', new \Pig\Ai\Usage(), StopReason::Error, $message)), $message);
    }

    public function testAQuotaWallFailsOverToTheNextAccountAndSendsAgain(): void
    {
        // pi-antigravity's `failoverToNextAccount()`: the next account not yet tried, inside the
        // same request. pig used to do this from the session's `before_retry` hook.
        $tried = [];
        $failover = static function (array $tokens) use (&$tried): ?string {
            $tried[] = $tokens;

            return count($tried) === 1 ? (string) json_encode(['token' => 'second-token', 'projectId' => 'second-project']) : null;
        };

        $replies = [
            [self::refusal(429, 'Individual quota reached. Resets in 5m.')],
            $this->okPieces([['response' => ['candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']]]]]),
        ];
        $url = $this->server->startSequence($replies);

        $message = $this->drain(new Antigravity(fallback: $url, failover: $failover), $this->model($url), 'gemini-3-flash', 'low', new Context([new UserMessage('hi')]));

        $this->assertNull($message);
        $this->assertCount(1, $tried);
        $this->assertSame(2, $this->server->connections());
        $this->assertStringContainsString('authorization: Bearer second-token', $this->server->received());
        $this->assertStringContainsString('"project":"second-project"', $this->server->received());
    }

    public function testAnEmptyResponseIsAskedForAgainBeforeTheTurnFails(): void
    {
        // pi-antigravity's `emptyAttempt` loop: a 200 with no text, thinking or call in it is asked
        // for again (after 500 ms, then 1 s) — the stream says nothing until something arrives.
        $empty = $this->okPieces([['response' => ['candidates' => [['content' => ['parts' => []], 'finishReason' => 'STOP']]]]]);
        $url = $this->server->startSequence([
            $empty,
            $this->okPieces([['response' => ['candidates' => [['content' => ['parts' => [['text' => 'there it is']]], 'finishReason' => 'STOP']]]]]),
        ]);

        $events = [];
        $message = Async::run(function () use ($url, &$events): AssistantMessage {
            $stream = (new Antigravity(fallback: $url))->stream($this->model($url), Transcript::normalizeContext(new Context([new UserMessage('hi')])), new GoogleOptions(apiKey: self::key()));

            foreach ($stream as $event) {
                $events[] = (new \ReflectionClass($event))->getShortName();
            }

            return $stream->result()->await();
        });

        $this->assertSame(2, $this->server->connections());
        $this->assertSame('there it is', $message->content[0]->text ?? null);
        $this->assertSame('StartEvent', $events[0], 'one start, for the attempt that said something');
        $this->assertSame(1, count(array_keys($events, 'StartEvent', true)));

        // Three empty answers in a row is the turn failing with pi-antigravity's sentence.
        $this->server = new CannedServer();
        $url = $this->server->startSequence([$empty, $empty, $empty]);
        $failure = $this->drain(new Antigravity(fallback: $url), $this->model($url), 'gemini-3-flash', 'low', new Context([new UserMessage('hi')]));

        $this->assertSame('Antigravity API returned an empty response', $failure);
        $this->assertSame(3, $this->server->connections());
    }

    public function testARuntimeModelThatIsNotThereFallsBackToTheOlderOne(): void
    {
        // pi-antigravity's `getFallbackRuntimeModel()`: a 404 on Gemini 3.8 Flash's runtime model
        // is tried again on 3.7's, under its own enum. Today's catalogue serves neither 3.7 nor
        // 3.6, so a catalogue that still names them is installed for the chain to have somewhere
        // to go — which is also how it would come back if the deployment named them again.
        $enums = ['gemini-3.7-flash-medium' => 'MODEL_TEST_37_MEDIUM', 'gemini-3.7-flash-low' => 'MODEL_TEST_37_LOW', 'gemini-3.6-flash-low' => 'MODEL_TEST_36_LOW'];
        $newest = ['gemini-3.8-flash' => ['default' => 'gemini-3.8-flash-low', 'off' => 'gemini-3.8-flash-low', 'levels' => ['medium' => 'gemini-3.8-flash-medium']]];

        // An enum is not enough: with a catalogue installed, the older runtime has to be one that
        // catalogue still routes to ("never downgrade into a retired Flash generation").
        \PigAntigravity\Routing::useTables($newest, $enums);
        $this->assertNull(\PigAntigravity\Routing::fallback('gemini-3.8-flash-medium', 'medium'));

        \PigAntigravity\Routing::useTables([
            ...$newest,
            'gemini-3.7-flash' => ['default' => 'gemini-3.7-flash-low', 'off' => 'gemini-3.7-flash-low', 'levels' => ['medium' => 'gemini-3.7-flash-medium']],
            'gemini-3.6-flash' => ['default' => 'gemini-3.6-flash-low', 'levels' => []],
        ], $enums);

        $url = $this->server->startSequence([
            [self::refusal(404, 'Requested entity was not found.')],
            $this->okPieces([['response' => ['candidates' => [['content' => ['parts' => [['text' => 'from 3.7']]], 'finishReason' => 'STOP']]]]]),
        ]);

        // One host, so the second answer is the fallback model's and not the other endpoint's.
        $failure = $this->drain(new Antigravity(fallback: rtrim($url, '/')), $this->model($url), 'gemini-3.8-flash', 'medium', new Context([new UserMessage('hi')]));

        $this->assertNull($failure);
        $this->assertSame(2, $this->server->connections());
        $this->assertStringContainsString('"model":"gemini-3.7-flash-medium"', $this->server->received());
        $this->assertStringContainsString('"model_enum":"MODEL_TEST_37_MEDIUM"', $this->server->received());
        $this->assertSame('gemini-3.7-flash-low', \PigAntigravity\Routing::fallback('gemini-3.8-flash', null));
        $this->assertSame('gemini-3.6-flash-low', \PigAntigravity\Routing::fallback('gemini-3.7-flash', null));
        $this->assertNull(\PigAntigravity\Routing::fallback('gemini-3.6-flash-low', null));
    }

    public function testWithNoOlderModelInTheCatalogueA404IsReportedRatherThanRetried(): void
    {
        // The shipped tables: 3.7 and 3.6 are gone from the deployment, so they have no enum and
        // the chain has nowhere to send the request. One attempt, and the 404 is the answer.
        $this->assertNull(\PigAntigravity\Routing::fallback('gemini-3.8-flash-medium', 'medium'));

        $url = $this->server->startSequence([
            [self::refusal(404, 'Requested entity was not found.')],
        ]);

        $failure = $this->drain(new Antigravity(fallback: rtrim($url, '/')), $this->model($url), 'gemini-3.8-flash', 'medium', new Context([new UserMessage('hi')]));

        $this->assertNotNull($failure);
        $this->assertSame(1, $this->server->connections());
        $this->assertStringContainsString('"model":"gemini-3.8-flash-medium"', $this->server->received());
    }

    public function testEveryStatusIsWordedAsPiAntigravityWordsIt(): void
    {
        $friendly = \PigAntigravity\AntigravityApi::friendlyAntigravityError(...);

        $this->assertSame('Antigravity authentication failed. Next: run /login antigravity, then retry.', $friendly(401, '{}'));
        $this->assertSame('This model has no capacity right now. Next: retry later or switch to another model.', $friendly(503, '{"error":{"message":"No capacity available for model"}}'));
        $this->assertSame('Antigravity is temporarily unavailable. Next: retry in a moment or switch models.', $friendly(503, 'down'));
        $this->assertSame('Antigravity rejected an invalid function-call message boundary. Next: update the extension or start a new session, then retry; re-login is not required.', $friendly(400, '{"error":{"message":"Please ensure that function call turn comes immediately after a user turn or after a function response turn."}}'));
        $this->assertSame('Antigravity denied this request. Next: re-login or try another model. Backend said: nope', $friendly(403, 'nope'));
        $this->assertSame('Antigravity access was denied for this account or project. Next: try another model, re-login, or use an account with access.', $friendly(403, 'PERMISSION_DENIED'));
        $validationJson = (string) json_encode([
            'error' => [
                'code' => 403,
                'message' => 'Verify your account to continue.',
                'details' => [
                    ['metadata' => ['validation_url' => 'https://accounts.google.com/signin/continue?plt=session-secret']],
                ],
            ],
        ]);
        $this->assertSame(
            'Antigravity denied this request. Next: re-login or try another model. Backend said: Verify your account to continue. Open this link in a browser signed in to the same Google account to verify: https://accounts.google.com/signin/continue?plt=session-secret',
            $friendly(403, $validationJson),
        );
        $this->assertSame('Antigravity timed out upstream. Next: retry in a moment.', $friendly(504, ''));
        $this->assertSame('teapot [redacted-access-token]', $friendly(418, 'teapot ya29.abcdef'));
    }

    public function testToInt64SessionIdIsDeterministicForNonEmptySeedsAndRandomOtherwise(): void
    {
        $id1 = Antigravity::toInt64SessionId('test-seed-1');
        $id2 = Antigravity::toInt64SessionId('test-seed-1');
        $id3 = Antigravity::toInt64SessionId('test-seed-2');

        $this->assertSame($id1, $id2);
        $this->assertNotSame($id1, $id3);
        $this->assertMatchesRegularExpression('/^-?\d+$/', $id1);
        $this->assertMatchesRegularExpression('/^-?\d+$/', $id3);

        $rnd1 = Antigravity::toInt64SessionId(null);
        $rnd2 = Antigravity::toInt64SessionId('');
        $this->assertMatchesRegularExpression('/^-?\d+$/', $rnd1);
        $this->assertMatchesRegularExpression('/^-?\d+$/', $rnd2);
        $this->assertNotSame($rnd1, $rnd2);
    }

    public function testAStreamEndingWithoutAFinishReasonIsAnError(): void
    {
        $url = $this->serve([['response' => ['candidates' => [['content' => ['parts' => [['text' => 'cut short']]]]]]]]);
        $message = $this->drain(new Antigravity(), $this->model($url), 'gemini-3-flash', 'low', new Context([new UserMessage('hi')]));

        $this->assertSame('Antigravity stream ended without a finish reason; the response was terminated before completion.', $message);
    }

    public function testAnOrdinaryFailureIsNotCalledAQuotaOne(): void
    {
        $server = new CannedServer();
        $url = $server->start([self::refusal(400, 'contents is empty')]);

        $message = $this->drain(new Antigravity(), $this->model($url), 'gemini-3-flash', 'low', new Context([new UserMessage('hi')]));

        // pi-antigravity's `friendlyAntigravityError()` for a 400, in its error envelope. This test
        // asserted pig's own `antigravity returned 400: …`, which pi-antigravity never says.
        $this->assertStringStartsWith('Antigravity API error (400, endpoint=', (string) $message);
        $this->assertStringContainsString('Bad request from Antigravity. Next: retry once, then run /login antigravity if it keeps failing. Backend said: contents is empty', (string) $message);
        $this->assertStringNotContainsString('quota', (string) $message);
    }

    public function testAnErrorFinishReasonIsReadToTheEndOfTheStreamBeforeTheTurnFails(): void
    {
        // As the direct Gemini path does (`google-generative-ai.ts`): the reason is recorded, the
        // rest of the body is read, and only then does the turn fail on it. This provider used to
        // throw on the chunk that carried the reason, so the usage after it was never counted.
        $url = $this->serve([
            ['response' => ['candidates' => [['content' => ['parts' => [['text' => 'part']]], 'finishReason' => 'SAFETY']]]],
            ['response' => ['usageMetadata' => ['promptTokenCount' => 40, 'candidatesTokenCount' => 3, 'totalTokenCount' => 43]]],
        ]);

        $message = Async::run(function () use ($url): AssistantMessage {
            $stream = (new Antigravity())->stream($this->model($url), Transcript::normalizeContext(new Context([new UserMessage('hi')])), new GoogleOptions(apiKey: self::key(), thinkingEnabled: false));

            foreach ($stream as $ignored) {
            }

            return $stream->result()->await();
        });

        $this->assertSame(StopReason::Error, $message->stopReason);
        $this->assertSame('Provider stopped with: SAFETY', $message->errorMessage);
        $this->assertSame(40, $message->usage->input);
        $this->assertSame('part', $message->content[0]->text);
    }

    public function testABlockedPromptIsStillNamedHere(): void
    {
        // pig's own rule, now in `GoogleShared::onChunk()` for every Gemini path; this provider
        // unwraps `response` first and hands the same chunk over.
        $url = $this->serve([['response' => ['promptFeedback' => ['blockReason' => 'SAFETY']]]]);

        $this->assertSame('Gemini refused the prompt: SAFETY', $this->drain(new Antigravity(), $this->model($url), 'gemini-3-flash', null, new Context([new UserMessage('hi')])));
    }

    public function testAKeyThatIsNotATokenAndAProjectIsNamedRatherThanSent(): void
    {
        $message = $this->drain(
            new Antigravity(),
            $this->model('http://127.0.0.1:1'),
            'gemini-3-flash',
            'low',
            new Context([new UserMessage('hi')]),
            // Short on purpose: long enough to read as "somebody pasted a Gemini key", too
            // short for a secret scanner to mistake for one.
            'AIza-fake',
        );

        $this->assertStringContainsString('token and a Cloud project', (string) $message);
    }

    // ---- what the registry offers ----------------------------------------------------------

    public function testTheRegistrysAntigravityModelsAreAllOnThisProvider(): void
    {
        $listed = array_values(array_filter(
            \Pig\Ai\Models::all(),
            static fn (Model $m): bool => $m->provider === 'antigravity',
        ));

        $this->assertCount(11, $listed);

        foreach ($listed as $model) {
            $this->assertSame(Api::Extension, $model->api, $model->id);
            $this->assertSame(Antigravity::ENDPOINT, $model->baseUrl, $model->id);
            // A subscription, so there is no per-token price to report.
            $this->assertSame(0.0, $model->pricing->input, $model->id);
            // Every one of them always thinks, which is the deployment's answer and not an
            // oversight — see the table's docblock.
            $this->assertFalse($model->hasThinkingLevel('off'), $model->id);
        }
    }

    public function testAntigravityDoesNotStealAnthropicsOwnId(): void
    {
        // `claude-sonnet-5-5` is Anthropic's id and Antigravity resells it. A bare one has to
        // keep meaning Anthropic's, or somebody with an Antigravity sign-in would find their
        // `--model sonnet` quietly going through Google. This test moved here with the provider;
        // the rule is `Models::RESOLD`'s and did not change.
        $this->assertNotSame('antigravity', \Pig\Ai\Models::get('claude-sonnet-5-5')?->provider);
        $this->assertSame('antigravity', \Pig\Ai\Models::find('antigravity', 'claude-sonnet-5-5')?->provider);
    }

    // ---- helpers ---------------------------------------------------------------------------

    private function send(string $model, ?string $level, ?Context $context = null): void
    {
        $url = $this->serve([['response' => ['candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']]]]]);

        $this->drain(new Antigravity(), $this->model($url), $model, $level, $context ?? new Context([new UserMessage('hello')]));
    }

    /** @return string|null the error the stream ended with, when it ended with one */
    private function drain(
        Antigravity $provider,
        Model $model,
        string $id,
        ?string $level,
        Context $context,
        ?string $key = null,
        ?string $sessionId = null,
    ): ?string {
        return Async::run(function () use ($provider, $model, $id, $level, $context, $key, $sessionId): ?string {
            $stream = $provider->stream(
                new Model(
                    $id,
                    $id,
                    Api::Extension,
                    'antigravity',
                    $model->baseUrl,
                    $model->contextWindow,
                    $model->maxTokens,
                    true,
                    ['text', 'image'],
                    new Pricing(),
                ),
                Transcript::normalizeContext($context),
                // Both, the way `Stream` sets them: a level and the flag that says it counts.
                new GoogleOptions(
                    apiKey: $key ?? self::key(),
                    thinkingEnabled: $level !== null,
                    thinkingLevel: $level,
                    sessionId: $sessionId,
                ),
            );

            foreach ($stream as $ignored) {
                // Drain it; what is under test is what went out.
            }

            return $stream->result()->await()->errorMessage;
        });
    }

    /** A turn the model answered, for a context that is past its first turn. */
    private static function answered(string $text): AssistantMessage
    {
        return new AssistantMessage([new \Pig\Ai\TextContent($text)], Api::Extension, 'antigravity', 'gemini-3-flash', new \Pig\Ai\Usage(), StopReason::Stop);
    }

    private function model(string $baseUrl): Model
    {
        return new Model(
            'gemini-3-flash',
            'Gemini 3 Flash',
            Api::Extension,
            'antigravity',
            rtrim($baseUrl, '/'),
            1_048_576,
            65_536,
            true,
            ['text', 'image'],
            new Pricing(),
        );
    }

    /**
     * One canned refusal, with a `Content-Length` that matches the body.
     *
     * Counted rather than written: a hand-typed length that is short cuts the JSON off, and the
     * test then fails against half an error message for reasons that have nothing to do with the
     * code under test.
     */
    private static function refusal(int $status, string $message): string
    {
        $body = (string) json_encode(['error' => ['message' => $message]]);

        return "HTTP/1.1 {$status} Refused\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;
    }

    /**
     * @param list<array<string, mixed>> $chunks
     * @return list<string>
     */
    private function okPieces(array $chunks): array
    {
        $pieces = ["HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nTransfer-Encoding: chunked\r\n\r\n"];

        foreach ($chunks as $chunk) {
            $body = 'data: ' . json_encode($chunk) . "\n\n";
            $pieces[] = sprintf("%x\r\n%s\r\n", strlen($body), $body);
        }

        $pieces[] = "0\r\n\r\n";

        return $pieces;
    }

    /** @param list<array<string, mixed>> $chunks */
    private function serve(array $chunks): string
    {
        return $this->server->start($this->okPieces($chunks));
    }
}
