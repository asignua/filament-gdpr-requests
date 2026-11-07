<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Asignua\FilamentGdprRequests\Enums\RequestChannel;
use Asignua\FilamentGdprRequests\Enums\RequestType;
use Asignua\FilamentGdprRequests\GdprRequests;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Document;
use Workbench\App\Models\Invoice;
use Workbench\App\Models\Order;
use Workbench\App\Models\OrderItem;
use Workbench\App\Models\Ticket;
use Workbench\App\Models\User;

/**
 * Everything here is invented: made-up people on reserved example domains.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->create(['name' => 'Marta Officer', 'email' => 'marta@example.test', 'password' => 'password', 'role' => 'dpo', 'is_active' => true]);
        User::query()->create(['name' => 'Oleh Reviewer', 'email' => 'oleh@example.test', 'password' => 'password', 'role' => 'dpo', 'is_active' => true]);
        User::query()->create(['name' => 'Nina Support', 'email' => 'nina@example.test', 'password' => 'password', 'role' => 'manager', 'is_active' => true]);

        $people = [
            ['Ada Example', 'ada@customer.example', '+00 000 0101', '1990-04-12'],
            ['Bruno Sample', 'bruno@customer.example', '+00 000 0102', '1985-11-02'],
            ['Chloe Placeholder', 'chloe@customer.example', null, null],
        ];

        foreach ($people as $n => [$name, $email, $phone, $birthday]) {
            $customer = Customer::query()->create(['name' => $name, 'email' => $email, 'phone' => $phone, 'birthday' => $birthday]);

            foreach ([1, 2] as $k) {
                $order = Order::query()->create([
                    'customer_id' => $customer->id,
                    'number' => sprintf('SO-%d%d', $n + 1, $k),
                    'total' => 1500 * $k,
                    'shipping_address' => sprintf('%d Imaginary Street, Nowhere %d', 10 * ($n + 1) + $k, $n + 1),
                    'billing_name' => $name,
                ]);
                OrderItem::query()->create(['order_id' => $order->id, 'sku' => 'DEMO-'.$k, 'qty' => $k]);
                Invoice::query()->create([
                    'customer_id' => $customer->id,
                    'number' => sprintf('INV-%d%d', $n + 1, $k),
                    'amount' => 1500 * $k,
                    'billed_to' => $name.', '.$order->shipping_address,
                    'issued_at' => now()->subDays(30 * $k),
                ]);
            }

            Ticket::query()->create(['customer_email' => $email, 'subject' => 'Where is my parcel?', 'body' => $name.' asks about a late demo parcel.']);

            $path = 'demo-documents/'.($n + 1).'-scan.txt';
            Storage::disk('local')->put($path, 'Invented scan for '.$name);
            Document::query()->create(['customer_id' => $customer->id, 'title' => 'Signed form', 'disk' => 'local', 'path' => $path]);
        }

        // A soft-deleted order: it must be counted, exported and erased like any other.
        Order::query()->where('number', 'SO-11')->first()?->delete();

        GdprRequests::submit(RequestType::Erasure, 'ada@customer.example', 'Ada Example', 'Please delete everything you hold about me.', RequestChannel::Email);
        GdprRequests::submit(RequestType::Access, 'bruno@customer.example', 'Bruno Sample', 'I would like a copy of my data.', RequestChannel::Web);
    }
}
