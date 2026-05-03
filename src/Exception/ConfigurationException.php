<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Exception;

/**
 * Signals configuration errors.
 *
 * Is thrown when task, step, or framework configuration is invalid or incomplete.
 *
 * This file is part of bylexus/durable-task
 *
 * (c) Alexander Schenkel <info@alexi.ch>
 */
class ConfigurationException extends DurableTaskException
{
}
