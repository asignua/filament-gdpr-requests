<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Support;

use Asignua\FilamentGdprRequests\Models\GdprRequest;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Art. 12(3) deadlines. Calendar months, and a month that is too short ends on its last day, so
 * 31 January + one month is the last day of February (never 2 or 3 March). The deadline is the END of that day.
 */
final class Deadline
{
    public static function initial(CarbonInterface $received): Carbon
    {
        return Carbon::instance($received)->addMonthsNoOverflow(1)->endOfDay();
    }

    /**
     * The extended deadline: one month plus $extraMonths, counted from the day of receipt (not from the old deadline).
     */
    public static function extended(CarbonInterface $received, int $extraMonths): Carbon
    {
        return Carbon::instance($received)->addMonthsNoOverflow(1 + $extraMonths)->endOfDay();
    }

    public static function maxExtraMonths(): int
    {
        return max(0, (int) config('filament-gdpr-requests.extension_max_months', 2));
    }

    public static function canExtend(GdprRequest $request): bool
    {
        return self::maxExtraMonths() > 0 && $request->extended_at === null && $request->due_at->isFuture();
    }
}
