<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Support;

use Asignua\FilamentGdprRequests\Enums\RequestType;
use Asignua\FilamentGdprRequests\GdprRequests;
use Asignua\FilamentGdprRequests\Models\GdprRequest;
use Asignua\FilamentGdprRequests\Services\RequestService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Storage;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Document;
use Workbench\App\Models\Invoice;
use Workbench\App\Models\Order;
use Workbench\App\Models\OrderItem;
use Workbench\App\Models\Ticket;

/**
 * Invented shop data for the tests: two customers whose data must never mix.
 */
final class Shop
{
    public static function customer(string $name, string $email): Customer
    {
        $customer = Customer::query()->create(['name' => $name, 'email' => $email, 'phone' => '+00 000 '.random_int(1000, 9999), 'birthday' => '1990-01-01']);

        foreach ([1, 2] as $k) {
            $order = Order::query()->create([
                'customer_id' => $customer->id,
                'number' => $name.'-'.$k,
                'total' => 100 * $k,
                'shipping_address' => $name.' street '.$k,
                'billing_name' => $name,
            ]);
            OrderItem::query()->create(['order_id' => $order->id, 'sku' => 'SKU-'.$k, 'qty' => $k]);
            Invoice::query()->create(['customer_id' => $customer->id, 'number' => 'INV-'.$name.'-'.$k, 'amount' => 100 * $k, 'billed_to' => $name, 'issued_at' => now()->subDays(10)]);
        }

        Ticket::query()->create(['customer_email' => $email, 'subject' => 'Hello', 'body' => $name.' wrote this']);
        $path = 'docs/'.$name.'.txt';
        Storage::disk('local')->put($path, 'secret of '.$name);
        Document::query()->create(['customer_id' => $customer->id, 'title' => 'Form', 'disk' => 'local', 'path' => $path]);

        return $customer;
    }

    public static function request(Customer $customer, RequestType $type, Authenticatable $actor, bool $verify = true): GdprRequest
    {
        $request = GdprRequests::submit($type, $customer->email, $customer->name, 'please');
        $service = app(RequestService::class);
        $service->linkSubject($request, Customer::class, $customer->id, $actor);

        if ($verify) {
            $service->verifyIdentity($request, $actor, 'Replied from the registered address.');
        }

        return $request->refresh();
    }
}
