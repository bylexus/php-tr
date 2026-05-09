<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Tests\Fixture;

use ByLexus\DurableTask\Attribute\MaxRuntime;
use ByLexus\DurableTask\Attribute\Retries;
use ByLexus\DurableTask\Attribute\RetryMode as RetryModeAttribute;
use ByLexus\DurableTask\Enum\RetryMode;

#[RetryModeAttribute(RetryMode::SKIP)]
#[Retries(1, new \DateInterval('PT15M'))]
#[MaxRuntime(new \DateInterval('PT30M'))]
final class OverrideStepFixture
{
}
