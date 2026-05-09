<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Tests\Fixture;

use ByLexus\DurableTask\Attribute\CleanupAfter;
use ByLexus\DurableTask\Attribute\MaxRuntime;
use ByLexus\DurableTask\Attribute\Retries;
use ByLexus\DurableTask\Attribute\RetryMode as RetryModeAttribute;
use ByLexus\DurableTask\Enum\RetryMode;

#[CleanupAfter(new \DateInterval('PT30M'), new \DateInterval('P2D'))]
#[RetryModeAttribute(RetryMode::RESTART)]
#[Retries(5, new \DateInterval('PT2M'))]
#[MaxRuntime(new \DateInterval('PT2H'))]
final class ConfiguredTaskFixture
{
}
