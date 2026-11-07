<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Unit;

use Asignua\FilamentGdprRequests\Support\Deadline;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class DeadlineTest extends TestCase
{
    public function test_a_month_from_the_31st_ends_on_the_last_day_of_a_short_month(): void
    {
        $this->assertSame('2026-02-28 23:59:59', Deadline::initial(Carbon::parse('2026-01-31 10:15'))->format('Y-m-d H:i:s'));
        $this->assertSame('2028-02-29', Deadline::initial(Carbon::parse('2028-01-31'))->toDateString());
        $this->assertSame('2026-04-30', Deadline::initial(Carbon::parse('2026-03-31'))->toDateString());
    }

    public function test_ordinary_and_year_end_months(): void
    {
        $this->assertSame('2026-06-15', Deadline::initial(Carbon::parse('2026-05-15'))->toDateString());
        $this->assertSame('2027-01-31', Deadline::initial(Carbon::parse('2026-12-31'))->toDateString());
        $this->assertSame('2027-01-01', Deadline::initial(Carbon::parse('2026-12-01'))->toDateString());
    }

    public function test_the_deadline_is_the_end_of_the_day_not_its_start(): void
    {
        $due = Deadline::initial(Carbon::parse('2026-05-15 00:00:01'));

        $this->assertSame('23:59:59', $due->format('H:i:s'));
    }

    public function test_an_extension_counts_from_the_day_of_receipt(): void
    {
        $received = Carbon::parse('2026-01-31');

        $this->assertSame('2026-03-31', Deadline::extended($received, 1)->toDateString());
        $this->assertSame('2026-04-30', Deadline::extended($received, 2)->toDateString());
    }
}
