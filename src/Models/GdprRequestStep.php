<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Models;

use Asignua\FilamentGdprRequests\Enums\StepStatus;
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
}
