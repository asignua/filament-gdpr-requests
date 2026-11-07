<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Services;

use Asignua\FilamentGdprRequests\Enums\RequestStatus;
use Asignua\FilamentGdprRequests\Erasure\ErasurePlan;
use Asignua\FilamentGdprRequests\Erasure\Planner;
use Asignua\FilamentGdprRequests\Exceptions\AlreadyRunning;
use Asignua\FilamentGdprRequests\Exceptions\InvalidTransition;
use Asignua\FilamentGdprRequests\Exceptions\NotPermitted;
use Asignua\FilamentGdprRequests\Exceptions\PlanBlocked;
use Asignua\FilamentGdprRequests\Jobs\ErasureGroupJob;
use Asignua\FilamentGdprRequests\Jobs\FinalizeErasureJob;
use Asignua\FilamentGdprRequests\Models\GdprRequest;
use Asignua\FilamentGdprRequests\Support\Environment;
use Asignua\FilamentGdprRequests\Support\Gates;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * The irreversible path: preview -> confirm -> run, three separate calls, each with its own checks. Nothing here
 * can be reached with one call, and every call re-checks the gate, the identity verification and the plan.
 */
final class ErasureService
{
    public function __construct(private readonly Planner $planner) {}

    public function plan(GdprRequest $request): ErasurePlan
    {
        return $this->planner->forRequest($request);
    }

    /**
     * Step 1 of 2 that the actor takes: "I have read the preview and I confirm exactly this".
     */
    public function confirm(GdprRequest $request, Authenticatable $actor): ErasurePlan
    {
        Gates::authorize(Gates::ERASE, $actor);
        Environment::assertSupported();
        $this->assertEligible($request);

        if (!in_array($request->status, [RequestStatus::InProgress, RequestStatus::ErasureConfirmed], true)) {
            throw InvalidTransition::translated('cannot_confirm');
        }

        $plan = $this->planner->forRequest($request);

        if ($plan->isBlocked()) {
            throw new PlanBlocked($plan->allBlockers());
        }

        $claimed = GdprRequest::query()->whereKey($request->getKey())
            ->whereIn('status', [RequestStatus::InProgress->value, RequestStatus::ErasureConfirmed->value])
            ->update([
                'status' => RequestStatus::ErasureConfirmed->value,
                'erasure_confirmed_at' => now(),
                'erasure_confirmed_by' => (string) $actor->getAuthIdentifier(),
                'erasure_plan' => json_encode(
                    [...$plan->summary(), 'order' => array_map(static fn ($group): string => $group->key(), $plan->groups)],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ),
            ]);

        if ($claimed !== 1) {
            throw InvalidTransition::translated('cannot_confirm');
        }

        RequestLog::record($request, 'erasure_confirmed', $actor, ['counts' => $plan->counts(), 'fingerprint' => $plan->fingerprint()]);

        $request->refresh();

        return $plan;
    }

    /**
     * Step 2: start (or resume) the run. With four eyes on, the person who confirmed cannot start it.
     */
    public function start(GdprRequest $request, Authenticatable $actor): void
    {
        Gates::authorize(Gates::ERASE, $actor);
        Environment::assertSupported();
        $this->assertEligible($request);

        $fresh = GdprRequest::query()->whereKey($request->getKey())->firstOrFail();
        $resuming = $fresh->status === RequestStatus::Partial || $this->isStale($fresh);
        $actorId = (string) $actor->getAuthIdentifier();
        $fourEyes = (bool) config('filament-gdpr-requests.four_eyes', true);

        if (!$resuming && $fresh->status !== RequestStatus::ErasureConfirmed) {
            throw InvalidTransition::translated('not_confirmed');
        }

        if ($fresh->erasure_confirmed_at === null || $fresh->erasure_plan === null) {
            throw InvalidTransition::translated('not_confirmed');
        }

        if ($fourEyes && $fresh->erasure_confirmed_by === $actorId) {
            throw NotPermitted::translated('four_eyes_same_person');
        }

        if (!$resuming && $fresh->erasure_confirmed_at->addHours((int) config('filament-gdpr-requests.erasure.confirmation_valid_hours', 72))->isPast()) {
            throw InvalidTransition::translated('confirmation_expired');
        }

        $plan = $this->planner->forRequest($fresh);

        if ($plan->isBlocked()) {
            throw new PlanBlocked($plan->allBlockers());
        }

        if (($fresh->erasure_plan['fingerprint'] ?? null) !== $plan->fingerprint()) {
            throw InvalidTransition::translated('plan_changed');
        }

        foreach ($plan->counts() as $key => $matched) {
            if ($matched > (int) ($fresh->erasure_plan['counts'][$key] ?? 0)) {
                throw InvalidTransition::translated('plan_changed');
            }
        }

        // The atomic claim: only one caller changes the status, so two simultaneous "Run" clicks cannot both start.
        $staleBefore = now()->subMinutes((int) config('filament-gdpr-requests.erasure.stale_run_minutes', 30));
        $claimed = GdprRequest::query()->whereKey($fresh->getKey())
            ->where(function ($query) use ($staleBefore): void {
                $query->whereIn('status', [RequestStatus::ErasureConfirmed->value, RequestStatus::Partial->value])
                    ->orWhere(function ($stale) use ($staleBefore): void {
                        $stale->where('status', RequestStatus::Erasing->value)
                            ->where(function ($beat) use ($staleBefore): void {
                                $beat->whereNull('erasure_heartbeat_at')->orWhere('erasure_heartbeat_at', '<', $staleBefore);
                            });
                    });
            })
            ->update([
                'status' => RequestStatus::Erasing->value,
                'erasure_started_at' => now(),
                'erasure_started_by' => $actorId,
                'erasure_heartbeat_at' => now(),
            ]);

        if ($claimed !== 1) {
            throw AlreadyRunning::translated('already_running');
        }

        RequestLog::record($fresh, 'erasure_started', $actor, ['resumed' => $resuming, 'four_eyes' => $fourEyes]);

        if (!$fourEyes) {
            RequestLog::record($fresh, 'four_eyes_disabled', $actor, ['group_count' => count($plan->groups)]);
        }

        $settled = $fresh->steps()->get()->filter(
            static fn ($step): bool => $step->status->isSettled() && !str_contains((string) $step->detail, '"files_pending":[{'),
        )->pluck('group_key')->all();

        $jobs = [];

        foreach ($fresh->erasure_plan['order'] ?? [] as $groupKey) {
            if (!in_array($groupKey, $settled, true)) {
                $jobs[] = new ErasureGroupJob($fresh->getKey(), (string) $groupKey);
            }
        }

        $jobs[] = new FinalizeErasureJob($fresh->getKey());

        $connection = config('filament-gdpr-requests.queue.connection');
        $queue = config('filament-gdpr-requests.queue.name');

        Bus::chain($jobs)
            ->onConnection(is_string($connection) && $connection !== '' ? $connection : null)
            ->onQueue(is_string($queue) && $queue !== '' ? $queue : null)
            ->dispatch();
    }

    /**
     * Called by the last job: fulfilled only if every planned group is settled.
     */
    public function finalize(int $requestId): void
    {
        DB::transaction(function () use ($requestId): void {
            /** @var GdprRequest $request */
            $request = GdprRequest::query()->whereKey($requestId)->lockForUpdate()->firstOrFail();

            if ($request->status !== RequestStatus::Erasing) {
                return;
            }

            $planned = $request->erasure_plan['order'] ?? [];
            $steps = $request->steps()->get()->keyBy('group_key');

            $open = array_filter($planned, static function (string $key) use ($steps): bool {
                $step = $steps->get($key);

                if ($step === null || !$step->status->isSettled()) {
                    return true;
                }

                // Settled rows but files still waiting to be removed is not finished.
                return str_contains((string) $step->detail, '"files_pending":[{');
            });

            if ($open !== []) {
                $request->forceFill(['status' => RequestStatus::Partial])->save();
                RequestLog::record($request, 'erasure_partial', null, ['open' => array_values($open)]);

                return;
            }

            $request->forceFill(['status' => RequestStatus::Fulfilled, 'erasure_finished_at' => now()])->save();
            RequestLog::record($request, 'erasure_finished', null, ['groups' => count($planned)]);
            RequesterMail::send($request, 'completed');
        });
    }

    private function assertEligible(GdprRequest $request): void
    {
        if (!$request->type->allowsErasure()) {
            throw InvalidTransition::translated('wrong_type');
        }

        if (!$request->hasSubject()) {
            throw InvalidTransition::translated('no_subject');
        }

        if (!$request->isVerified()) {
            throw InvalidTransition::translated('not_verified');
        }
    }

    private function isStale(GdprRequest $request): bool
    {
        return $request->status === RequestStatus::Erasing
            && ($request->erasure_heartbeat_at === null
                || $request->erasure_heartbeat_at->lt(now()->subMinutes((int) config('filament-gdpr-requests.erasure.stale_run_minutes', 30))));
    }
}
