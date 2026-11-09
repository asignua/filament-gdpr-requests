<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Feature;

use Asignua\FilamentGdprRequests\DataMap\Erase;
use Asignua\FilamentGdprRequests\DataMap\PersonalData;
use Asignua\FilamentGdprRequests\DataMap\Subject;
use Asignua\FilamentGdprRequests\Enums\RequestStatus;
use Asignua\FilamentGdprRequests\Enums\RequestType;
use Asignua\FilamentGdprRequests\Services\ErasureService;
use Asignua\FilamentGdprRequests\Services\ExportService;
use Asignua\FilamentGdprRequests\Tests\Support\RunsErasures;
use Asignua\FilamentGdprRequests\Tests\Support\Shop;
use Asignua\FilamentGdprRequests\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Order;
use Workbench\App\Models\OrderItem;
use ZipArchive;

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

    /**
     * A queue worker that has not touched Order yet boots it inside the closure, and booting registers the soft-delete
     * scope again. The closure must not see it all the same.
     */
    #[RunInSeparateProcess]
    public function test_a_model_first_booted_inside_the_closure_keeps_no_scope_in_an_export(): void
    {
        $ada = $this->adaWithATrashedOrder();
        $dpo = $this->user('dpo', 'Exporter');
        $request = Shop::request($ada, RequestType::Access, $dpo);
        Model::clearBootedModels(); // what a fresh worker starts with

        $export = app(ExportService::class)->request($request, $dpo)->refresh();

        $zip = new ZipArchive;
        $path = tempnam(sys_get_temp_dir(), 'gdpr');
        file_put_contents($path, Storage::disk('local')->get($export->path));
        $zip->open($path);
        $items = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_contains((string) $zip->getNameIndex($i), 'orderitem')) {
                $items = json_decode((string) $zip->getFromIndex($i), true);
            }
        }

        $zip->close();
        unlink($path);

        $this->assertIsArray($items);
        $this->assertCount(2, $items, 'The item of the soft-deleted order is exported too.');
    }

    #[RunInSeparateProcess]
    public function test_a_model_first_booted_inside_the_closure_keeps_no_scope_in_a_preview(): void
    {
        $ada = $this->adaWithATrashedOrder();
        $request = $this->erasureOf($ada);
        Model::clearBootedModels();

        $plan = app(ErasureService::class)->plan($request);

        $this->assertSame(2, $plan->group(OrderItem::class)?->matched);
        $this->assertSame(1, Order::query()->where('customer_id', $ada->id)->count(), 'The scope is back after the preview.');
    }

    private function adaWithATrashedOrder(): Customer
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        Order::query()->where('customer_id', $ada->id)->orderBy('id')->first()->delete();
        // Order is not in the map here, so nothing but the closure ever makes an Order.
        $this->registry()->forget(Order::class);
        $this->registry()->forget(OrderItem::class);
        PersonalData::for(OrderItem::class)->of(Customer::class)->export(['id', 'order_id', 'sku'])->erase(Erase::delete())
            ->matchedUsing(static function (Builder $query, Subject $subject): void {
                $query->whereIn('order_id', Order::query()->where('customer_id', $subject->keyValue())->select('id'));
            });

        return $ada;
    }
}
