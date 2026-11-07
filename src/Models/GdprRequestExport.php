<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ulid
 * @property int $request_id
 * @property string $status
 * @property string|null $disk
 * @property string|null $path
 * @property int $size
 * @property string|null $created_by
 * @property int $downloads
 * @property string|null $error
 * @property Carbon|null $expires_at
 * @property Carbon|null $removed_at
 */
class GdprRequestExport extends Model
{
    use HasUlids;

    public const string PENDING = 'pending';

    public const string READY = 'ready';

    public const string FAILED = 'failed';

    public const string REMOVED = 'removed';

    protected $table = 'gdpr_request_exports';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'removed_at' => 'datetime'];
    }

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
     * @return BelongsTo<GdprRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(GdprRequest::class, 'request_id');
    }

    /**
     * The owning request; a dangling export cannot exist (cascade FK), so absence is an error, not a case.
     */
    public function owner(): GdprRequest
    {
        return $this->request ?? throw (new ModelNotFoundException)->setModel(GdprRequest::class, [$this->request_id]);
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::READY
            && $this->removed_at === null
            && $this->path !== null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
