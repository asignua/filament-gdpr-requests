<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Models;

use Asignua\FilamentGdprRequests\Enums\StepStatus;
use Asignua\FilamentGdprRequests\Erasure\Failures;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $request_id
 * @property string $group_key
 * @property string $label
 * @property string $strategy
 * @property StepStatus $status
 * @property int $matched
 * @property int $changed
 * @property int $trashed
 * @property int $attempts
 * @property string|null $detail
 * @property string|null $fingerprint
 * @property string|null $error
 * @property Carbon|null $finished_at
 */
class GdprRequestStep extends Model
{
    protected $table = 'gdpr_request_steps';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => StepStatus::class, 'finished_at' => 'datetime'];
    }

    public function errorMessage(): ?string
    {
        return Failures::render($this->error);
    }

    /**
     * Did this group's rows change under the given plan? A step of any other plan (another person, other rules)
     * says nothing about this one.
     */
    public function rowsDone(string $fingerprint): bool
    {
        return $this->fingerprint === $fingerprint && ($this->details()['rows_done'] ?? false) === true;
    }

    /**
     * Nothing is left to do for this group under the given plan: rows done and no file still waiting.
     */
    public function isComplete(string $fingerprint): bool
    {
        return $this->status->isSettled() && $this->fingerprint === $fingerprint && $this->pendingFiles() === [];
    }

    /**
     * @return list<array{disk: string, path: string}>
     */
    public function pendingFiles(): array
    {
        return $this->details()['files_pending'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        $decoded = $this->detail === null ? null : json_decode($this->detail, true);

        return is_array($decoded) ? $decoded : [];
    }
}
