<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Exception;

/**
 * Signals serialization failures.
 *
 * Is thrown when workflow payloads or results cannot be serialized or restored.
 *
 * This file is part of bylexus/durable-task
 *
 * (c) Alexander Schenkel <info@alexi.ch>
 */
class SerializationException extends DurableTaskException {
}
