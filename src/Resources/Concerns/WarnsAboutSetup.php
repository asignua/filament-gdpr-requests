<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Resources\Concerns;

use Asignua\FilamentGdprRequests\Support\Pseudonym;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * A missing pseudonym key stops the plugin only when somebody links a record. Say so where the work starts instead.
 */
trait WarnsAboutSetup
{
    public function getSubheading(): string|Htmlable|null
    {
        $problem = Pseudonym::setupProblem();

        return $problem === null ? parent::getSubheading() : new HtmlString('<span style="color:var(--danger-600);font-weight:500">'.e($problem).'</span>');
    }
}
