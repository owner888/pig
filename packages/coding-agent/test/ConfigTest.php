<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Test;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Config;

/**
 * Where pig looks for its own things, and for pi's.
 *
 * Small enough to have gone untested, which is how the override for pi's directory came to be a
 * variable name nothing sets: upstream builds it as `${APP_NAME.toUpperCase()}_CODING_AGENT_DIR`
 * rather than writing it out, so reading `getAgentDir()` alone suggests `PI_AGENT_DIR`.
 */
final class ConfigTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $saved = [];

    private const array VARIABLES = ['PIG_HOME', 'PI_CODING_AGENT_DIR', 'PI_AGENT_DIR', 'PI_HOME', 'HOME'];

    #[\Override]
    protected function setUp(): void
    {
        foreach (self::VARIABLES as $name) {
            $this->saved[$name] = getenv($name);
            putenv($name);
        }

        putenv('HOME=/tmp/pig-config-home');
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public function testPigsOwnHomeIsUnderTheHomeDirectory(): void
    {
        $this->assertSame('/tmp/pig-config-home/.pig', Config::home());
    }

    public function testPigHomeOverridesIt(): void
    {
        putenv('PIG_HOME=/somewhere/else/');

        // Trailing slash off, because everything downstream appends `/sessions` and the like.
        $this->assertSame('/somewhere/else', Config::home());
    }

    public function testPisDirectoryIsTheAgentOneAndNotTheConfigOne(): void
    {
        // `~/.pi/agent`, not `~/.pi`: upstream's `getAgentDir()` is `join(homedir(), ".pi",
        // "agent")`, so its sessions are one level deeper than the folder name suggests.
        $this->assertSame('/tmp/pig-config-home/.pi/agent', Config::piHome());
    }

    public function testUpstreamsOwnVariableIsHonoured(): void
    {
        // The name upstream actually uses. pig looked for `PI_AGENT_DIR`, which nothing sets, so
        // somebody who had moved pi's directory the documented way was read from `~/.pi/agent`
        // anyway — and pig's whole promise about pi's files rests on finding them.
        putenv('PI_CODING_AGENT_DIR=/elsewhere/pi-agent');

        $this->assertSame('/elsewhere/pi-agent', Config::piHome());
    }

    public function testPigsOwnPiHomeStillWorksAndComesSecond(): void
    {
        putenv('PI_HOME=/pig/idea/of/pi');

        $this->assertSame('/pig/idea/of/pi', Config::piHome());

        putenv('PI_CODING_AGENT_DIR=/upstreams/idea');

        $this->assertSame('/upstreams/idea', Config::piHome(), 'upstream’s name wins when both are set');
    }

    public function testTheNameNothingSetsIsNotReadAnyMore(): void
    {
        putenv('PI_AGENT_DIR=/a/name/pig/invented');

        $this->assertSame('/tmp/pig-config-home/.pi/agent', Config::piHome());
    }
}
