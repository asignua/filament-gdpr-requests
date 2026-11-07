<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Support;

use Asignua\FilamentGdprRequests\Enums\RequestStatus;
use Asignua\FilamentGdprRequests\Enums\RequestType;
use Asignua\FilamentGdprRequests\Erasure\Blocker;
use Asignua\FilamentGdprRequests\Erasure\ErasurePlan;
use Asignua\FilamentGdprRequests\Erasure\Runner;
use Asignua\FilamentGdprRequests\Models\GdprRequest;
use Asignua\FilamentGdprRequests\Services\ErasureService;
use Workbench\App\Models\Customer;

trait RunsErasures
{
    private function erasureOf(Customer $customer): GdprRequest
    {
        return Shop::request($customer, RequestType::Erasure, $this->user('dpo', 'Confirmer'));
    }

    private function confirmed(Customer $customer): GdprRequest
    {
        $request = $this->erasureOf($customer);
        app(ErasureService::class)->confirm($request, $this->user('dpo', 'Confirmer'));

        return $request->refresh();
    }

    private function execute(GdprRequest $request): void
    {
        app(ErasureService::class)->start($request->refresh(), $this->user('dpo', 'Runner'));
    }

    /**
     * What the job chain does before its last link, so that a test can change the data in between.
     */
    private function runGroups(GdprRequest $request): void
    {
        $request->forceFill(['status' => RequestStatus::Erasing, 'erasure_heartbeat_at' => now()])->save();

        foreach ($request->erasure_plan['order'] as $group) {
            app(Runner::class)->runGroup($request, (string) $group);
        }
    }

    private function finalize(GdprRequest $request): GdprRequest
    {
        app(ErasureService::class)->finalize($request->getKey());

        return $request->refresh();
    }

    /**
     * @return list<string>
     */
    private function codes(ErasurePlan $plan): array
    {
        return array_map(static fn (Blocker $blocker): string => $blocker->code, $plan->allBlockers());
    }

    /**
     * The context of the last line of the log with this event.
     *
     * @return array<string, mixed>
     */
    private function context(GdprRequest $request, string $event): array
    {
        return $request->events()->where('event', $event)->get()->last()->context ?? [];
    }
}
