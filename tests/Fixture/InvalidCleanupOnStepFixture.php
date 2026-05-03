<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Tests\Fixture;

use ByLexus\DurableTask\Attribute\CleanupAfter;

#[CleanupAfter(new \DateInterval('PT0S'), new \DateInterval('P1D'))]
final class InvalidCleanupOnStepFixture
{
}
