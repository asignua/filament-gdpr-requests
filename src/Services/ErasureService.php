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
use Asignua\FilamentGdprRequests\Support\Pseudonym;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The irreversible path: preview -> confirm -> run, three separate calls, each with its own checks. Nothing here
 * can be reached with one call, and every call re-checks the gate, the identity verification and the plan.
 */
final class ErasureService
{
    public function __construct(private readonly Planner $planner, private readonly ExportService $exports) {}

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
                // The address the rows are matched on outlives the record it came from: the root may be anonymized
                // before an e-mail group runs, and on a resume it is gone. A request without one takes the record's.
                ...$this->addressFromRecord($request, $plan),
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
        $resuming = $fresh->status === RequestStatus::Partial || $fresh->isStaleRun();

        if ($fresh->status === RequestStatus::Erasing && !$resuming) {
            throw AlreadyRunning::translated('already_running');
        }
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
        $staleBefore = now()->subMinutes(GdprRequest::staleRunMinutes());
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

        $confirmed = (string) ($fresh->erasure_plan['fingerprint'] ?? '');
        $settled = $fresh->steps()->get()->filter(static fn ($step): bool => $step->isComplete($confirmed))->pluck('group_key')->all();

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
     * Called by the last job: fulfilled only if every planned group is finished under this plan and no archive of the
     * person is left. The requester is told after the commit: a mail server has no say in whether data was erased.
     */
    public function finalize(int $requestId): void
    {
        $fulfilled = DB::transaction(function () use ($requestId): ?GdprRequest {
            /** @var GdprRequest $request */
            $request = GdprRequest::query()->whereKey($requestId)->lockForUpdate()->firstOrFail();

            if ($request->status !== RequestStatus::Erasing) {
                return null;
            }

            $planned = $request->erasure_plan['order'] ?? [];
            $confirmed = (string) ($request->erasure_plan['fingerprint'] ?? '');
            $steps = $request->steps()->get()->keyBy('group_key');

            // Finished means finished under THIS plan, files included; a step of another person never counts.
            $open = array_filter($planned, static fn (string $key): bool => !($steps->get($key)?->isComplete($confirmed) ?? false));
            $archives = $planned !== [] && $open === [] ? $this->exports->removeAllForSubject($request) : 0;

            if ($planned === [] || $open !== [] || $archives > 0) {
                $request->forceFill(['status' => RequestStatus::Partial])->save();
                RequestLog::record($request, 'erasure_partial', null, ['open' => array_values($open), 'archives' => $archives]);

                return null;
            }

            $request->forceFill(['status' => RequestStatus::Fulfilled, 'erasure_finished_at' => now()])->save();
            RequestLog::record($request, 'erasure_finished', null, ['groups' => count($planned)]);

            return $request;
        });

        if ($fulfilled !== null) {
            try {
                RequesterMail::send($fulfilled, 'completed');
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * The last job died (killed worker, timeout): a run that nobody finishes must not read `erasing` for ever.
     */
    public function interrupted(int $requestId, ?Throwable $e): void
    {
        $moved = GdprRequest::query()->whereKey($requestId)->where('status', RequestStatus::Erasing->value)
            ->update(['status' => RequestStatus::Partial->value]);

        if ($moved === 1) {
            RequestLog::record(GdprRequest::query()->findOrFail($requestId), 'erasure_interrupted', null, ['reason' => $e === null ? null : class_basename($e)]);
        }
    }

    /**
     * @return array<string, string>
     */
    private function addressFromRecord(GdprRequest $request, ErasurePlan $plan): array
    {
        if ($request->subject_email !== null || $plan->subjectEmail === null) {
            return [];
        }

        return [
            'subject_email' => $plan->subjectEmail,
            'email_pseudonym' => Pseudonym::forEmail($plan->subjectEmail),
        ];
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
}
