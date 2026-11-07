<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Models;

use Asignua\FilamentGdprRequests\Casts\UnescapedJson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $request_id
 * @property string $event
 * @property string|null $actor_id
 * @property array<string, mixed>|null $context
 * @property Carbon $created_at
 */
class GdprRequestEvent extends Model
{
    public const null UPDATED_AT = null;

    protected $table = 'gdpr_request_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['context' => UnescapedJson::class, 'created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<GdprRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(GdprRequest::class, 'request_id');
    }

    public function label(): string
    {
        $key = 'filament-gdpr-requests::filament-gdpr-requests.event.'.$this->event;
        $text = __($key);

        return $text === $key ? $this->event : $text;
    }
}
