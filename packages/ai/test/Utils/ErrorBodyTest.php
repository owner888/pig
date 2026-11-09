<?php

declare(strict_types=1);

namespace Pig\Ai\Test\Utils;

use PHPUnit\Framework\TestCase;
use Pig\Ai\Providers\SdkRequest;
use Pig\Ai\Utils\ErrorBody;
use Pig\Ai\Utils\ProviderHttpError;
use RuntimeException;

/**
 * Upstream's `withCauseDetail()` (#11783): a transport failure's generic message keeps the reason
 * its cause carries.
 */
final class ErrorBodyTest extends TestCase
{
    public function testAConnectionErrorKeepsTheDeepestCauseAndItsCode(): void
    {
        $socket = new RuntimeException('connect ECONNREFUSED 127.0.0.1:8080', 111);
        $error = new ProviderHttpError(SdkRequest::CONNECTION_ERROR, previous: new RuntimeException('fetch failed', 0, $socket));

        $this->assertSame('Connection error.: connect ECONNREFUSED 127.0.0.1:8080 (111)', SdkRequest::errorMessage($error));
        $this->assertSame('Connection error.: connect ECONNREFUSED 127.0.0.1:8080 (111)', ErrorBody::normalizeProviderError($error)['message']);
    }

    public function testTheMessageIsUnchangedWithoutACauseOrWhenItAlreadySaysIt(): void
    {
        $this->assertSame('Connection error.', ErrorBody::withCauseDetail(new ProviderHttpError(SdkRequest::CONNECTION_ERROR)));
        $this->assertSame(
            'refused: ECONNREFUSED',
            ErrorBody::withCauseDetail(new RuntimeException('refused: ECONNREFUSED', 0, new RuntimeException('ECONNREFUSED'))),
        );
    }

    public function testARefusedRequestKeepsItsStatusMessage(): void
    {
        $error = new ProviderHttpError('400 bad', status: 400, body: 'bad', previous: new RuntimeException('inner'));

        $this->assertSame('400 bad', ErrorBody::normalizeProviderError($error)['message']);
    }
}
