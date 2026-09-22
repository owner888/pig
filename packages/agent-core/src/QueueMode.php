<?php

declare(strict_types=1);

namespace Pig\Agent;

/**
 * How a queue of waiting messages is handed to the loop.
 *
 * `OneAtATime` gives the agent a chance to answer each before seeing the next, which is
 * what someone typing three separate thoughts usually means. `All` delivers them together.
 */
enum QueueMode: string
{
    case All = 'all';
    case OneAtATime = 'one-at-a-time';
}
