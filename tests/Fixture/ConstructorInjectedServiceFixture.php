<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Tests\Fixture;

final class ConstructorInjectedServiceFixture
{
    public function __construct(private string $name) {
    }

    public function getName(): string {
        return $this->name;
    }
}
