<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Exceptions;

use Asignua\FilamentGdprRequests\Erasure\Blocker;

final class PlanBlocked extends GdprException
{
    /**
     * @param list<Blocker> $blockers
     */
    public function __construct(public readonly array $blockers)
    {
        parent::__construct(__('filament-gdpr-requests::filament-gdpr-requests.errors.plan_blocked', ['count' => count($blockers)]));
    }
}
