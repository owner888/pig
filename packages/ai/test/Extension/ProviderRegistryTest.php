<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Extension;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Api;
use Pig\Ai\Context;
use Pig\Ai\Extension\Provider;
use Pig\Ai\Extension\ProviderRegistry;
use Pig\Ai\Extension\StreamApi;
use Pig\Ai\Model;
use Pig\Ai\Models;
use Pig\Ai\Pricing;
use Pig\Ai\ProviderError;
use Pig\Ai\ReasoningEffort;
use Pig\Ai\SimpleStreamOptions;
use Pig\Ai\Stream;
use Pig\Ai\StreamOptions;
use Pig\Ai\TextContent;
use Pig\Ai\UserMessage;
use Pig\Ai\Utils\AssistantMessageEventStream;
use Pig\Ai\DoneEvent;
use Pig\Ai\Providers\AssistantMessageBuilder;
use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\Test\AssertsThrows;

/**
 * A provider an extension brings — upstream's `registerProvider()`.
 *
 * What the registry promises: once `register()` returns, the four core classes that used to know
 * only their own tables answer for it as if it had shipped with pig. Each promise here is pinned
 * from the core's side, through the door the core itself uses.
 */
final class ProviderRegistryTest extends TestCase
{
    use AssertsThrows;

    #[\Override]
    protected function setUp(): void
    {
        Loop::reset();
        ProviderRegistry::forget();
        Models::forgetRegistered();
    }

    #[\Override]
    protected function tearDown(): void
    {
        ProviderRegistry::forget();
        Models::forgetRegistered();
        putenv('ZZP_API_KEY');
    }

    public function testRegisteringPutsTheModelsWhereEveryLookupAlreadyLooks(): void
    {
        $this->assertNull(Models::find('zzp', 'one'));

        ProviderRegistry::register(self::provider());

        $model = Models::find('zzp', 'one');
        $this->assertNotNull($model);
        $this->assertSame(Api::Extension, $model->api);
        $this->assertSame('one', Models::get('one')?->id, 'by bare id too');
        $this->assertContains('zzp', Models::providers());
    }

    public function testUnregisteringTakesEverythingBack(): void
    {
        ProviderRegistry::register(self::provider());
        ProviderRegistry::unregister('zzp');

        $this->assertNull(Models::find('zzp', 'one'));
        $this->assertNull(ProviderRegistry::get('zzp'));
        $this->assertSame([], ProviderRegistry::envKeysFor('zzp'));
        $this->assertFalse(ProviderRegistry::isResold('zzp'));
    }

    public function testRegisteringTheSameIdAgainReplacesRatherThanDoubles(): void
    {
        // `/reload` loads the extensions again; a provider registered twice must not list its
        // models twice.
        ProviderRegistry::register(self::provider());
        ProviderRegistry::register(self::provider());

        $this->assertCount(2, array_filter(Models::all(), static fn (Model $m): bool => $m->provider === 'zzp'), 'two models, once each');
        $this->assertCount(1, ProviderRegistry::all());
    }

    public function testAResoldProviderNeverWinsABareId(): void
    {
        // `Models::RESOLD`'s dynamic half: an extension reselling Anthropic's ids says so, and
        // `--model sonnet` keeps meaning Anthropic's.
        $anthropic = Models::get('claude-sonnet-4-5');
        $this->assertNotNull($anthropic);

        ProviderRegistry::register(new Provider(
            'zzp',
            'Probe',
            [self::model('claude-sonnet-4-5')],
            api: new ProbeApi(),
            resold: true,
        ));

        $this->assertTrue(Models::isResold('zzp'));
        $this->assertSame('anthropic', Models::get('claude-sonnet-4-5')?->provider);
        $this->assertSame('zzp', Models::find('zzp', 'claude-sonnet-4-5')?->provider);
    }

    public function testTheProvidersOwnEnvironmentVariableIsReadForItsKey(): void
    {
        ProviderRegistry::register(self::provider());
        putenv('ZZP_API_KEY=k-from-env');

        $this->assertSame('k-from-env', Stream::envApiKey('zzp'));
    }

    public function testAModelOfAProviderNobodyRegisteredIsRefusedByName(): void
    {
        // A model saying `Api::Extension` with no provider behind it was in a session file when
        // the extension was installed and is not any more. "No API key" would send somebody to
        // the wrong file.
        $problem = $this->assertThrows(
            ProviderError::class,
            static fn (): mixed => Stream::start(self::model('one'), new Context([new UserMessage('hi')]), new StreamOptions(apiKey: 'k')),
        );

        $this->assertStringContainsString('zzp/one', $problem->getMessage());
        $this->assertStringContainsString('extension', $problem->getMessage());
    }

    public function testStreamHandsTheRequestToTheExtensionsProtocolWithTheKey(): void
    {
        $api = new ProbeApi();
        ProviderRegistry::register(self::provider($api));
        putenv('ZZP_API_KEY=k-from-env');

        $answer = Async::run(static function (): string {
            $stream = Stream::simple(self::model('one'), new Context([new UserMessage('hi')]), new SimpleStreamOptions(reasoning: ReasoningEffort::High));
            $text = '';

            foreach ($stream as $event) {
                if ($event instanceof DoneEvent) {
                    $text = $event->message->content[0]->text;
                }
            }

            return $text;
        });

        $this->assertSame('probe answered', $answer);
        // `translate()` was asked and handed the key the environment named; the extension's own
        // dialect reached its `stream()`.
        $this->assertSame('k-from-env', $api->translatedWith?->apiKey);
        $this->assertSame('high', $api->translatedWith?->level);
        $this->assertSame('high', $api->streamedWith?->level);
    }

    public function testAProviderWhoseModelsNameAnotherProviderIsRefused(): void
    {
        $this->assertThrows(
            \InvalidArgumentException::class,
            static fn (): Provider => new Provider('zzp', 'Probe', [new Model('x', 'X', Api::Extension, 'other', 'https://probe.invalid', 1, 1)]),
            "says provider 'other'",
        );
    }

    // ---- helpers ------------------------------------------------------------------------------

    private static function provider(?ProbeApi $api = null): Provider
    {
        return new Provider('zzp', 'Probe', [self::model('one'), self::model('two')], api: $api ?? new ProbeApi(), envKeys: ['ZZP_API_KEY']);
    }

    private static function model(string $id): Model
    {
        return new Model($id, ucfirst($id), Api::Extension, 'zzp', 'https://probe.invalid', 100_000, 1_000, true, ['text'], new Pricing());
    }
}

/** The extension's own dialect: a level, as a string. */
final readonly class ProbeOptions extends StreamOptions
{
    public function __construct(?string $apiKey, public ?string $level)
    {
        parent::__construct(apiKey: $apiKey);
    }
}

/** A protocol that answers at once, recording what it was handed. */
final class ProbeApi implements StreamApi
{
    public ?ProbeOptions $translatedWith = null;

    public ?ProbeOptions $streamedWith = null;

    #[\Override]
    public function translate(Model $model, ?SimpleStreamOptions $options, string $apiKey): StreamOptions
    {
        return $this->translatedWith = new ProbeOptions($apiKey, $options?->reasoning?->value);
    }

    #[\Override]
    public function stream(Model $model, \Pig\Ai\TranscriptContext $context, StreamOptions $options): AssistantMessageEventStream
    {
        $this->streamedWith = $options instanceof ProbeOptions ? $options : new ProbeOptions($options->apiKey, null);
        $stream = new AssistantMessageEventStream();
        $builder = new AssistantMessageBuilder($model);

        Async::spawn(static function () use ($stream, $builder): void {
            $index = $builder->startText(0);
            $builder->append($index, 'text', 'probe answered');
            $message = $builder->snapshot();
            $stream->push(new DoneEvent($message->stopReason, $message));
            $stream->end();
        });

        return $stream;
    }
}
