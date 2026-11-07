<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Models;

use Asignua\FilamentGdprRequests\Casts\UnescapedJson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row per run of a maintenance command (retention, prune, remind), dry runs included.
 *
 * @property int $id
 * @property string $command
 * @property bool $dry_run
 * @property array<string, mixed>|null $summary
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 */
class GdprRun extends Model
{
    public $timestamps = false;

    protected $table = 'gdpr_runs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean',
            'summary' => UnescapedJson::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
