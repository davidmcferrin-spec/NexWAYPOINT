<?php

declare(strict_types=1);

namespace NexWaypont\Core;

/**
 * Thrown by interface implementations that exist for contract/shape reasons
 * (e.g. future mail sources) but are not yet built. This is intentionally
 * loud and explicit -- it must never be mistaken for a working code path.
 * Do not catch-and-ignore this exception; catch it, log it, and surface it.
 */
final class NotImplementedException extends \RuntimeException
{
    public function __construct(string $feature, string $plannedPhase = 'a future phase')
    {
        parent::__construct(
            "{$feature} is not implemented yet (planned for {$plannedPhase}). " .
            'See README.md roadmap section before wiring this into production config.'
        );
    }
}
