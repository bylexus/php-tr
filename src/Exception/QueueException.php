<?php

declare(strict_types=1);

namespace ByLexus\DurableTask\Exception;

/**
 * Signals queue operation failures.
 *
 * Is thrown when the PostgreSQL-backed queue cannot persist or retrieve workflow records.
 *
 * This file is part of bylexus/durable-task
 *
 * (c) Alexander Schenkel <info@alexi.ch>
 */
class QueueException extends DurableTaskException {
}
