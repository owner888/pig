<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test\Session;

use Pig\CodingAgent\Session\SessionStore;

/** The conformance suite against the in-memory store, so it is known to test the interface. */
final class MemorySessionStoreTest extends SessionStoreConformance
{
    private ?MemorySessionStore $store = null;

    #[\Override]
    protected function store(): SessionStore
    {
        return $this->store ??= new MemorySessionStore();
    }
}
