<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Services;

use Asignua\FilamentGdprRequests\DataMap\PersonalDataRegistry;
use Asignua\FilamentGdprRequests\Enums\RequestChannel;
use Asignua\FilamentGdprRequests\Enums\RequestStatus;
use Asignua\FilamentGdprRequests\Enums\RequestType;
use Asignua\FilamentGdprRequests\Exceptions\GdprException;
use Asignua\FilamentGdprRequests\Exceptions\InvalidTransition;
use Asignua\FilamentGdprRequests\Models\GdprRequest;
use Asignua\FilamentGdprRequests\Support\Deadline;
use Asignua\FilamentGdprRequests\Support\Environment;
use Asignua\FilamentGdprRequests\Support\Gates;
use Asignua\FilamentGdprRequests\Support\Pseudonym;
use Asignua\FilamentGdprRequests\Support\Sql;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * The lifecycle of a request, except the erasure itself (see ErasureService). Every method checks its own gate.
 */
final class RequestService
{
    public function __construct(private readonly PersonalDataRegistry $registry) {}

    /**
     * Records a new request. $verified is never accepted from outside: a request always starts unverified.
     *
     * @param  array{
     *     type: RequestType, channel?: RequestChannel, name?: string|null, email?: string|null, message?: string|null,
     *     received_at?: CarbonInterface|null, locale?: string|null, assignee_id?: int|string|null,
     *     subject_model?: string|null, subject_id?: int|string|null
     * }  $data
     */
    public function create(array $data): GdprRequest
    {
        Environment::assertSupported();

        $received = $data['received_at'] ?? now();
        $request = new GdprRequest([
            'type' => $data['type'],
            'status' => RequestStatus::New,
            'channel' => $data['channel'] ?? RequestChannel::Email,
            'locale' => $data['locale'] ?? app()->getLocale(),
            'subject_name' => $this->clean($data['name'] ?? null),
            'subject_email' => Pseudonym::normalizeEmail($data['email'] ?? null),
            'message' => $this->clean($data['message'] ?? null),
            'assignee_id' => isset($data['assignee_id']) ? (string) $data['assignee_id'] : null,
            'received_at' => $received,
            'due_at' => Deadline::initial($received),
        ]);

        DB::transaction(function () use ($request, $data): void {
            $request->save();

            if (isset($data['subject_model'], $data['subject_id'])) {
                $this->bindSubject($request, $data['subject_model'], (string) $data['subject_id']);
            } elseif ($request->subject_email !== null && Pseudonym::isConfigured()) {
                $request->forceFill($this->emailPseudonymFields($request->subject_email))->save();
            }

            RequestLog::record($request, 'received', null, ['type' => $request->type->value, 'channel' => $request->channel->value]);
        });

        RequesterMail::send($request, 'received');

        return $request;
    }

    /**
     * Ties the request to a person by an explicit key. Verification is cleared: the identity was checked against
     * the previous link, not this one.
     */
    public function linkSubject(GdprRequest $request, string $model, string|int $id, Authenticatable $actor): GdprRequest
    {
        Gates::authorize(Gates::MANAGE, $actor);
        $this->assertOpen($request);

        if ($request->status === RequestStatus::ErasureConfirmed) {
            throw InvalidTransition::translated('confirmed_locked');
        }

        DB::transaction(function () use ($request, $model, $id, $actor): void {
            $this->bindSubject($request, $model, (string) $id);
            $request->forceFill([
                'identity_verified_at' => null,
                'identity_verified_by' => null,
                'identity_note' => null,
                'erasure_confirmed_at' => null,
                'erasure_confirmed_by' => null,
                'erasure_plan' => null,
                'status' => $request->status === RequestStatus::New ? RequestStatus::New : RequestStatus::InProgress,
            ])->save();
            RequestLog::record($request, 'subject_linked', $actor, ['model' => $model]);
        });

        return $request;
    }

    /**
     * Exact e-mail lookup through the map's declared e-mail column (trim + lowercase, never LIKE).
     */
    public function findSubjectByEmail(string $model, string $email): string|int|null
    {
        $root = $this->registry->rootFor($model);
        $column = $root?->getEmailColumn();
        $email = Pseudonym::normalizeEmail($email);

        if ($root === null || $column === null || $email === null) {
            return null;
        }

        $query = $root->model::query()->withoutGlobalScopes();
        Sql::whereNormalizedEquals($query, $query->getModel()->getTable().'.'.$column, $email);
        $keys = $query->limit(2)->pluck($query->getModel()->getKeyName());

        if ($keys->count() > 1) {
            throw GdprException::translated('email_ambiguous');
        }

        return $keys->first();
    }

    public function verifyIdentity(GdprRequest $request, Authenticatable $actor, string $note): GdprRequest
    {
        Gates::authorize(Gates::MANAGE, $actor);
        $this->assertOpen($request);

        if (trim($note) === '') {
            throw GdprException::translated('note_required');
        }

        if (!$request->hasSubject()) {
            throw InvalidTransition::translated('no_subject');
        }

        $request->forceFill([
            'identity_verified_at' => now(),
            'identity_verified_by' => (string) $actor->getAuthIdentifier(),
            'identity_note' => trim($note),
            'status' => $request->status === RequestStatus::New ? RequestStatus::InProgress : $request->status,
        ])->save();

        RequestLog::record($request, 'identity_verified', $actor);

        return $request;
    }

    public function assign(GdprRequest $request, string|int|null $assignee, Authenticatable $actor): GdprRequest
    {
        Gates::authorize(Gates::MANAGE, $actor);
        $this->assertOpen($request);

        $request->forceFill(['assignee_id' => $assignee === null ? null : (string) $assignee, 'reminded_due_at' => null])->save();
        RequestLog::record($request, 'assigned', $actor, ['assignee' => $assignee === null ? null : (string) $assignee]);

        return $request;
    }

    /**
     * Art. 12(3): once, with a reason, by at most `extension_max_months` further months from the day of receipt.
     */
    public function extend(GdprRequest $request, int $extraMonths, string $reason, Authenticatable $actor): GdprRequest
    {
        Gates::authorize(Gates::MANAGE, $actor);
        $this->assertOpen($request);

        $max = Deadline::maxExtraMonths();

        if (trim($reason) === '') {
            throw GdprException::translated('reason_required');
        }

        if ($extraMonths < 1 || $extraMonths > $max) {
            throw GdprException::translated('extension_range', ['max' => $max]);
        }

        if ($request->extended_at !== null || !$request->due_at->isFuture()) {
            throw InvalidTransition::translated('cannot_extend');
        }

        $request->forceFill([
            'due_at' => Deadline::extended($request->received_at, $extraMonths),
            'extended_at' => now(),
            'extension_reason' => trim($reason),
            'reminded_due_at' => null,
        ])->save();

        RequestLog::record($request, 'extended', $actor, ['months' => $extraMonths, 'due' => $request->due_at->toDateString()]);
        RequesterMail::send($request, 'extended');

        return $request;
    }

    public function addNote(GdprRequest $request, string $note, Authenticatable $actor): GdprRequest
    {
        Gates::authorize(Gates::MANAGE, $actor);

        $note = trim($note);

        if ($note === '') {
            throw GdprException::translated('note_required');
        }

        $stamp = now()->format('Y-m-d H:i').' #'.$actor->getAuthIdentifier();
        $request->forceFill(['notes' => trim(($request->notes ?? '')."\n".$stamp.': '.$note)])->save();
        RequestLog::record($request, 'note_added', $actor);

        return $request;
    }

    /**
     * Marks a request of a non-erasure type done (the erasure type is completed only by the erasure itself).
     */
    public function complete(GdprRequest $request, Authenticatable $actor): GdprRequest
    {
        Gates::authorize(Gates::MANAGE, $actor);

        if ($request->type->allowsErasure() || $request->status !== RequestStatus::InProgress) {
            throw InvalidTransition::translated('cannot_complete');
        }

        $request->forceFill(['status' => RequestStatus::Fulfilled])->save();
        RequestLog::record($request, 'completed', $actor);
        RequesterMail::send($request, 'completed');

        return $request;
    }

    public function refuse(GdprRequest $request, string $reason, Authenticatable $actor): GdprRequest
    {
        Gates::authorize(Gates::MANAGE, $actor);

        if (!in_array($request->status, [RequestStatus::New, RequestStatus::InProgress, RequestStatus::ErasureConfirmed], true)) {
            throw InvalidTransition::translated('cannot_refuse');
        }

        if (trim($reason) === '') {
            throw GdprException::translated('reason_required');
        }

        $request->forceFill([
            'status' => RequestStatus::Refused,
            'refused_at' => now(),
            'refusal_reason' => trim($reason),
            'erasure_confirmed_at' => null,
            'erasure_confirmed_by' => null,
            'erasure_plan' => null,
        ])->save();

        RequestLog::record($request, 'refused', $actor);
        RequesterMail::send($request, 'refused');

        return $request;
    }

    /**
     * Closes the request: the link to the subject is cut (only the pseudonym stays), the export archives are
     * removed. The name, address and notes stay until `request_retention_days` have passed (see `gdpr:prune`).
     */
    public function close(GdprRequest $request, Authenticatable $actor): GdprRequest
    {
        Gates::authorize(Gates::MANAGE, $actor);

        if (!in_array($request->status, [RequestStatus::New, RequestStatus::InProgress, RequestStatus::Fulfilled, RequestStatus::Refused], true)) {
            throw InvalidTransition::translated('cannot_close');
        }

        DB::transaction(function () use ($request, $actor): void {
            $request->forceFill([
                'status' => RequestStatus::Closed,
                'closed_at' => now(),
                'subject_model' => null,
                'subject_id' => null,
                'erasure_plan' => null,
            ])->save();

            RequestLog::record($request, 'closed', $actor);
        });

        app(ExportService::class)->removeAllFor($request);

        return $request;
    }

    private function bindSubject(GdprRequest $request, string $model, string $id): void
    {
        $root = $this->registry->rootFor($model);

        if ($root === null) {
            throw GdprException::translated('subject_not_mapped');
        }

        $exists = $root->model::query()->withoutGlobalScopes()->whereKey($id)->exists();

        if (!$exists) {
            throw GdprException::translated('subject_not_found');
        }

        $version = Pseudonym::currentVersion();

        $request->forceFill([
            'subject_model' => $model,
            'subject_id' => $id,
            'pseudonym' => Pseudonym::forSubject($model, $id),
            'pseudonym_key_version' => $version,
        ] + ($request->subject_email === null ? [] : $this->emailPseudonymFields($request->subject_email)))->save();
    }

    /**
     * @return array{email_pseudonym: string, pseudonym_key_version: int}
     */
    private function emailPseudonymFields(string $email): array
    {
        return ['email_pseudonym' => Pseudonym::forEmail($email), 'pseudonym_key_version' => Pseudonym::currentVersion()];
    }

    private function assertOpen(GdprRequest $request): void
    {
        if (!$request->status->isOpen()) {
            throw InvalidTransition::translated('request_closed');
        }

        if ($request->status === RequestStatus::Erasing) {
            throw InvalidTransition::translated('erasure_running');
        }
    }

    private function clean(?string $text): ?string
    {
        $text = $text === null ? null : trim($text);

        return $text === '' ? null : $text;
    }
}
