<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Feature;

use Asignua\FilamentGdprRequests\DataMap\Erase;
use Asignua\FilamentGdprRequests\DataMap\PersonalData;
use Asignua\FilamentGdprRequests\DataMap\Subject;
use Asignua\FilamentGdprRequests\Enums\RequestStatus;
use Asignua\FilamentGdprRequests\Tests\Support\RunsErasures;
use Asignua\FilamentGdprRequests\Tests\Support\Shop;
use Asignua\FilamentGdprRequests\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Ticket;

/**
 * A closure that reads a table runs before any other group that changes it, its own table included.
 */
class ClosureOrderTest extends TestCase
{
    use RunsErasures;

    public function test_a_closure_reading_its_own_table_runs_before_a_deletion_there(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        Ticket::query()->update(['subject' => 'Thread of Ada']);
        Ticket::query()->create(['customer_email' => 'someone@else.example', 'subject' => 'Thread of Ada', 'body' => 'reply 1']);
        Ticket::query()->create(['customer_email' => 'someone@else.example', 'subject' => 'Thread of Ada', 'body' => 'reply 2']);
        PersonalData::for(Ticket::class, 'thread')->of(Customer::class)->erase(Erase::delete())
            ->matchedUsing(static function (Builder $query, Subject $subject): void {
                $query->whereIn('subject', Ticket::query()->withoutGlobalScopes()->where('customer_email', $subject->email)->select('subject'));
            });
        $request = $this->confirmed($ada);

        $order = $request->erasure_plan['order'];
        $this->assertLessThan(array_search(Ticket::class, $order, true), array_search('thread', $order, true));

        $this->execute($request);

        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame(RequestStatus::Fulfilled, $request->refresh()->status);
    }
}
