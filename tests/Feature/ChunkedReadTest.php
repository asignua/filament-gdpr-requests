<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Feature;

use Asignua\FilamentGdprRequests\DataMap\Erase;
use Asignua\FilamentGdprRequests\DataMap\PersonalData;
use Asignua\FilamentGdprRequests\DataMap\Subject;
use Asignua\FilamentGdprRequests\Enums\RequestStatus;
use Asignua\FilamentGdprRequests\Services\ErasureService;
use Asignua\FilamentGdprRequests\Tests\Support\RunsErasures;
use Asignua\FilamentGdprRequests\Tests\Support\Shop;
use Asignua\FilamentGdprRequests\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Framework\Attributes\DataProvider;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Ticket;

/**
 * A selection is read by key in chunks: nothing the closure adds may make it skip rows.
 */
class ChunkedReadTest extends TestCase
{
    use RunsErasures;

    public function test_a_closure_with_its_own_order_still_reaches_every_row(): void
    {
        config(['filament-gdpr-requests.erasure.chunk' => 10]);
        $ada = Shop::customer('Ada', 'ada@customer.example');

        for ($i = 0; $i < 24; $i++) {
            Ticket::query()->create(['customer_email' => 'ada@customer.example', 'subject' => 'More', 'body' => 'x']);
        }

        $this->registry()->forget(Ticket::class);
        PersonalData::for(Ticket::class)->of(Customer::class)->erase(Erase::delete())
            ->matchedUsing(static function (Builder $query, Subject $subject): void {
                $query->where('customer_email', $subject->email)->latest('id');
            });
        $request = $this->confirmed($ada);
        $this->assertSame(25, $request->erasure_plan['counts'][Ticket::class]);

        $this->execute($request);

        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame(RequestStatus::Fulfilled, $request->refresh()->status);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unchunkable(): array
    {
        return ['limit' => ['limit'], 'offset' => ['offset'], 'distinct' => ['distinct'], 'group by' => ['group'], 'union' => ['union']];
    }

    #[DataProvider('unchunkable')]
    public function test_a_closure_that_cannot_be_read_in_chunks_blocks(string $kind): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $this->registry()->forget(Ticket::class);
        PersonalData::for(Ticket::class)->of(Customer::class)->erase(Erase::delete())
            ->matchedUsing(static function (Builder $query, Subject $subject) use ($kind): void {
                $query->where('customer_email', $subject->email);

                match ($kind) {
                    'limit' => $query->limit(5),
                    'offset' => $query->offset(1)->limit(100),
                    'distinct' => $query->distinct(),
                    'group' => $query->groupBy('tickets.id'),
                    'union' => $query->union(Ticket::query()->withoutGlobalScopes()->where('id', 0)),
                };
            });

        $plan = app(ErasureService::class)->plan($this->erasureOf($ada));

        $this->assertContains('selection_unchunkable', $this->codes($plan));
    }
}
