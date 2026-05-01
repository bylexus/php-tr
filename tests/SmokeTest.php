<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Tests;

use ByLexus\DurableTask\Enum\RunnerMode;
use ByLexus\DurableTask\Exception\DurableTaskException;
use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase {
    public function testAutoloadBootstrapsProjectSkeleton(): void {
        self::assertTrue(enum_exists(RunnerMode::class));
        self::assertTrue(is_subclass_of(DurableTaskException::class, \RuntimeException::class));
    }
}
