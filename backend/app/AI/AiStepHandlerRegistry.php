<?php

namespace App\AI;

use Closure;
use InvalidArgumentException;

/**
 * Explicit application-local handler registration. Nothing is auto-executed
 * from job payloads, database fields, config class names or user input.
 * T014 may register reviewed provider/content handlers during app startup.
 */
final class AiStepHandlerRegistry
{
    /** @var array<string, Closure> */
    private array $handlers = [];

    public function register(string $jobType, Closure $handler): void
    {
        if (! preg_match('/^[A-Za-z0-9_.:\-]{1,80}$/D', $jobType)) {
            throw new InvalidArgumentException('Invalid AI job handler type.');
        }
        if (isset($this->handlers[$jobType])) {
            throw new InvalidArgumentException('Duplicate AI job handler registration.');
        }
        if (count($this->handlers) >= 20) {
            throw new InvalidArgumentException('Too many AI job handlers.');
        }
        $this->handlers[$jobType] = $handler;
    }

    /** @return array<string, Closure> */
    public function handlers(): array
    {
        return $this->handlers;
    }

    public function hasHandlers(): bool
    {
        return $this->handlers !== [];
    }
}
