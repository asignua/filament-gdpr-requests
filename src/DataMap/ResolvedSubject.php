<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\DataMap;

use Asignua\FilamentGdprRequests\Erasure\Blocker;

/**
 * The person a request is about, as the linked record says, and what stops the work on that person.
 */
final readonly class ResolvedSubject
{
    /**
     * @param list<Blocker> $blockers
     */
    public function __construct(public Subject $subject, public array $blockers = []) {}

    public function hasBlocker(string $code): bool
    {
        foreach ($this->blockers as $blocker) {
            if ($blocker->code === $code) {
                return true;
            }
        }

        return false;
    }
}
