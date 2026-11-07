<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Services;

use Asignua\FilamentGdprRequests\DataMap\SubjectResolver;
use Asignua\FilamentGdprRequests\Enums\RequestStatus;
use Asignua\FilamentGdprRequests\Enums\StepStatus;
use Asignua\FilamentGdprRequests\Erasure\ErasurePlan;
use Asignua\FilamentGdprRequests\Erasure\Findings;
use Asignua\FilamentGdprRequests\Erasure\Planner;
use Asignua\FilamentGdprRequests\Erasure\Rescan;
use Asignua\FilamentGdprRequests\Exceptions\AlreadyRunning;
use Asignua\FilamentGdprRequests\Exceptions\InvalidTransition;
use Asignua\FilamentGdprRequests\Exceptions\NotPermitted;
use Asignua\FilamentGdprRequests\Exceptions\PlanBlocked;
use Asignua\FilamentGdprRequests\Jobs\ErasureGroupJob;
use Asignua\FilamentGdprRequests\Jobs\FinalizeErasureJob;
use Asignua\FilamentGdprRequests\Models\GdprRequest;
use Asignua\FilamentGdprRequests\Models\GdprRequestStep;
use Asignua\FilamentGdprRequests\Support\Environment;
use Asignua\FilamentGdprRequests\Support\Gates;
use Asignua\FilamentGdprRequests\Support\Pseudonym;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The irreversible path: preview -> confirm -> run, three separate calls, each with its own checks. Nothing here
 * can be reached with one call, and every call re-checks the gate, the identity verification and the plan.
 */
final class ErasureService
{
    public function __construct(
        private readonly Planner $planner,
        private readonly ExportService $exports,
        private readonly Rescan $rescan,
        private readonly SubjectResolver $subjects,
    ) {}

    public function plan(GdprRequest $request): ErasurePlan
    {
        return $this->planner->forRequest($request);
    }

    /**
     * Step 1 of 2 that the actor takes: "I have read the preview and I confirm exactly this".
     *
     * Also the way forward from `partial` when resuming cannot help, because rows turned up or the map was fixed: a fresh
     * preview, a fresh confirmation, and four eyes again. Every group runs again under the new plan, which changes
     * nothing where nothing is left; what the earlier run did stays on the steps and in the log.
     */
    public function confirm(GdprRequest $request, Authenticatable $actor): ErasurePlan
    {
        Gates::authorize(Gates::ERASE, $actor);
        Environment::assertSupported();
        $this->assertEligible($request);

        // A run nobody finishes is `partial` in all but name, and the same way forward has to exist for it.
        if (!in_array($request->status, [RequestStatus::InProgress, RequestStatus::ErasureConfirmed, RequestStatus::Partial], true) && !$request->isStaleRun()) {
            throw InvalidTransition::translated('cannot_confirm');
        }

        $plan = $this->planner->forRequest($request);

        if ($plan->isBlocked()) {
            throw new PlanBlocked($plan->allBlockers());
        }

        $rootChanged = $this->subjects->rootChanged($request);

        DB::transaction(function () use ($request, $actor, $plan, $rootChanged): void {
            $claimed = $this->claimable($request, [RequestStatus::InProgress, RequestStatus::ErasureConfirmed, RequestStatus::Partial])
                ->update([
                    'status' => RequestStatus::ErasureConfirmed->value,
                    'erasure_confirmed_at' => now(),
                    'erasure_confirmed_by' => (string) $actor->getAuthIdentifier(),
                    'erasure_plan' => json_encode(
                        [...$plan->summary(), 'order' => array_map(static fn ($group): string => $group->key(), $plan->groups), 'root_changed' => $rootChanged],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                    ),
                    // The address the rows are matched on outlives the record it came from: the root may be anonymized
                    // before an e-mail group runs, and on a resume it is gone. A request without one takes the record's.
                    ...$this->addressFromRecord($request, $plan),
                ]);

            if ($claimed !== 1) {
                throw InvalidTransition::translated('cannot_confirm');
            }

            $earlier = $request->steps()->where('status', '!=', StepStatus::Pending->value)->get();
            $earlier->each(static fn (GdprRequestStep $step) => $step->supersede());

            RequestLog::record($request, 'erasure_confirmed', $actor, ['counts' => $plan->counts(), 'fingerprint' => $plan->fingerprint()]);

            if ($earlier->isNotEmpty()) {
                RequestLog::record($request, 'erasure_reconfirmed', $actor, ['previous' => $earlier->count()]);
            }
        });

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

        $this->assertCoveredByConfirmation($fresh, $plan);

        // The atomic claim: only one caller changes the status, so two simultaneous "Run" clicks cannot both start.
        $claimed = $this->claimable($fresh, [RequestStatus::ErasureConfirmed, RequestStatus::Partial])
            ->update([
                'status' => RequestStatus::Erasing->value,
                // What the last run found is about the last run.
                'erasure_plan' => json_encode(Arr::except($fresh->erasure_plan, 'findings'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
     * Called by the last job. `fulfilled` is not "the steps finished": the data is looked at again, and the request reads
     * so only if nothing the plan said would go is still there, every group finished under this plan and no archive of
     * the person is left. Anything else is `partial`, with the list on the request. The requester is told after the
     * commit: a mail server has no say in whether data was erased.
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
            $finished = $planned !== [] && $open === [];
            $findings = $finished ? $this->rescan->run($request, $steps) : new Findings;

            // The archives go last: while rows are left the request is not done, and the person may still be owed them.
            $archives = $finished && $findings->isClean() ? $this->exports->removeAllForSubject($request) : 0;

            if (!$finished || !$findings->isClean() || $archives > 0) {
                $request->forceFill([
                    'status' => RequestStatus::Partial,
                    'erasure_plan' => ['findings' => $findings->toArray()] + ($request->erasure_plan ?? []),
                ])->save();

                RequestLog::record($request, 'erasure_partial', null, ['open' => array_values($open), 'archives' => $archives] + $findings->forLog());

                return null;
            }

            // It closes itself: nobody has a reason to come back to it, and until a request is closed its name, address
            // and notes never expire. The status keeps saying how it ended; the plan stays, it holds no person.
            $request->forceFill([
                'status' => RequestStatus::Fulfilled,
                'erasure_finished_at' => now(),
                'closed_at' => now(),
                'subject_model' => null,
                'subject_id' => null,
            ])->save();

            RequestLog::record($request, 'erasure_finished', null, ['groups' => count($planned)]);
            RequestLog::record($request, 'closed', null, ['automatic' => true]);

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
     * The request while it is in one of $statuses, or in a run that has gone quiet: decided by the database, so that two
     * callers cannot both win.
     *
     * @param list<RequestStatus> $statuses
     *
     * @return Builder<GdprRequest>
     */
    private function claimable(GdprRequest $request, array $statuses): Builder
    {
        $staleBefore = now()->subMinutes(GdprRequest::staleRunMinutes());

        return GdprRequest::query()->whereKey($request->getKey())
            ->where(function ($query) use ($statuses, $staleBefore): void {
                $query->whereIn('status', array_map(static fn (RequestStatus $status): string => $status->value, $statuses))
                    ->orWhere(function ($stale) use ($staleBefore): void {
                        $stale->where('status', RequestStatus::Erasing->value)
                            ->where(function ($beat) use ($staleBefore): void {
                                $beat->whereNull('erasure_heartbeat_at')->orWhere('erasure_heartbeat_at', '<', $staleBefore);
                            });
                    });
            });
    }

    /**
     * The confirmation covers the rows it counted. More of them, or a finished group with rows back in it, is data
     * nobody reviewed; fewer in a group still to run means rows that can no longer be reached.
     */
    private function assertCoveredByConfirmation(GdprRequest $request, ErasurePlan $plan): void
    {
        $confirmed = (string) ($request->erasure_plan['fingerprint'] ?? '');
        $steps = $request->steps()->get()->keyBy('group_key');

        foreach ($plan->groups as $group) {
            $planned = (int) ($request->erasure_plan['counts'][$group->key()] ?? 0);
            $done = $steps->get($group->key())?->rowsDone($confirmed) ?? false;
            $vanished = !$done && $group->erases() && $group->matched < $planned && !$plan->sharesRowsWithADeletion($group);

            if ($group->matched > $planned || $vanished || ($done && $group->remaining() > 0)) {
                throw InvalidTransition::translated('plan_changed');
            }
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
