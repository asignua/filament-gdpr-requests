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
use Workbench\App\Models\Order;
use Workbench\App\Models\OrderItem;

/**
 * No global scope hides a row, not even one inside a closure's own subquery.
 */
class ClosureScopesTest extends TestCase
{
    use RunsErasures;

    public function test_a_soft_delete_scope_inside_a_closure_does_not_hide_rows(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        Order::query()->where('customer_id', $ada->id)->orderBy('id')->first()->delete();
        $this->registry()->forget(OrderItem::class);
        PersonalData::for(OrderItem::class)->of(Customer::class)->erase(Erase::delete())
            ->matchedUsing(static function (Builder $query, Subject $subject): void {
                $query->whereIn('order_id', Order::query()->where('customer_id', $subject->keyValue())->select('id'));
            });
        $request = $this->confirmed($ada);

        $this->assertSame(2, $request->erasure_plan['counts'][OrderItem::class]);
        $this->assertSame(1, Order::query()->where('customer_id', $ada->id)->count(), 'The scope is back after the preview.');

        $this->execute($request);

        $this->assertSame(0, OrderItem::query()->whereIn('order_id', Order::withTrashed()->where('customer_id', $ada->id)->pluck('id'))->count());
        $this->assertSame(RequestStatus::Fulfilled, $request->refresh()->status);
    }
}
