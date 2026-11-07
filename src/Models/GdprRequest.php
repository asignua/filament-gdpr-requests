<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Models;

use Asignua\FilamentGdprRequests\Casts\UnescapedJson;
use Asignua\FilamentGdprRequests\Enums\RequestChannel;
use Asignua\FilamentGdprRequests\Enums\RequestStatus;
use Asignua\FilamentGdprRequests\Enums\RequestType;
use Asignua\FilamentGdprRequests\Support\Deadline;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ulid
 * @property RequestType $type
 * @property RequestStatus $status
 * @property RequestChannel $channel
 * @property string|null $locale
 * @property string|null $subject_model
 * @property string|null $subject_id
 * @property string|null $subject_name
 * @property string|null $subject_email
 * @property string|null $pseudonym
 * @property string|null $email_pseudonym
 * @property int|null $pseudonym_key_version
 * @property string|null $message
 * @property string|null $notes
 * @property string|null $assignee_id
 * @property Carbon $received_at
 * @property Carbon $due_at
 * @property Carbon|null $extended_at
 * @property string|null $extension_reason
 * @property Carbon|null $identity_verified_at
 * @property string|null $identity_verified_by
 * @property string|null $identity_note
 * @property Carbon|null $erasure_confirmed_at
 * @property string|null $erasure_confirmed_by
 * @property array<string, mixed>|null $erasure_plan
 * @property Carbon|null $erasure_started_at
 * @property string|null $erasure_started_by
 * @property Carbon|null $erasure_heartbeat_at
 * @property Carbon|null $erasure_finished_at
 * @property Carbon|null $refused_at
 * @property string|null $refusal_reason
 * @property Carbon|null $closed_at
 * @property Carbon|null $details_purged_at
 * @property Carbon|null $reminded_due_at
 * @property Carbon|null $reminded_overdue_at
 */
class GdprRequest extends Model
{
    use HasUlids;

    protected $table = 'gdpr_requests';

    // Internal writes only; nothing here is filled from user input directly.
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => RequestType::class,
            'status' => RequestStatus::class,
            'channel' => RequestChannel::class,
            'received_at' => 'datetime',
            'due_at' => 'datetime',
            'extended_at' => 'datetime',
            'identity_verified_at' => 'datetime',
            'erasure_confirmed_at' => 'datetime',
            'erasure_plan' => UnescapedJson::class,
            'erasure_started_at' => 'datetime',
            'erasure_heartbeat_at' => 'datetime',
            'erasure_finished_at' => 'datetime',
            'refused_at' => 'datetime',
            'closed_at' => 'datetime',
            'details_purged_at' => 'datetime',
            'reminded_due_at' => 'datetime',
            'reminded_overdue_at' => 'datetime',
        ];
    }

    /**
     * The public key is the ULID column; the integer id stays internal.
     */
    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * @return HasMany<GdprRequestEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(GdprRequestEvent::class, 'request_id')->orderBy('id');
    }

    /**
     * @return HasMany<GdprRequestStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(GdprRequestStep::class, 'request_id')->orderBy('id');
    }

    /**
     * @return HasMany<GdprRequestExport, $this>
     */
    public function exports(): HasMany
    {
        return $this->hasMany(GdprRequestExport::class, 'request_id')->orderByDesc('id');
    }

    /**
     * A short human reference for e-mails and typed confirmations: the tail of the ULID.
     */
    public function reference(): string
    {
        return strtoupper(substr($this->ulid, -8));
    }

    public function isOverdue(): bool
    {
        return $this->status->isOpen() && $this->effectiveDueAt()->isPast();
    }

    public function effectiveDueAt(): Carbon
    {
        return $this->due_at;
    }

    public function canExtend(): bool
    {
        return $this->status->isOpen() && $this->extended_at === null && Deadline::canExtend($this);
    }

    public function isVerified(): bool
    {
        return $this->identity_verified_at !== null;
    }

    public function hasSubject(): bool
    {
        return $this->subject_model !== null && $this->subject_id !== null;
    }
}
