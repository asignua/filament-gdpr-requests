<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Asignua\FilamentGdprRequests\DataMap\Erase;
use Asignua\FilamentGdprRequests\DataMap\PersonalData;
use Asignua\FilamentGdprRequests\DataMap\Retention;
use Asignua\FilamentGdprRequests\DataMap\Rule;
use Asignua\FilamentGdprRequests\GdprRequests;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Document;
use Workbench\App\Models\Invoice;
use Workbench\App\Models\Order;
use Workbench\App\Models\OrderItem;
use Workbench\App\Models\Ticket;
use Workbench\App\Models\User;

/**
 * The demo shop: its data map, its gates and the one table it deliberately leaves unmapped (and acknowledges).
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::define('gdpr.view', static fn (User $user): bool => in_array($user->role, ['manager', 'dpo'], true));
        Gate::define('gdpr.manage', static fn (User $user): bool => in_array($user->role, ['manager', 'dpo'], true));
        Gate::define('gdpr.export', static fn (User $user): bool => $user->role === 'dpo');
        Gate::define('gdpr.erase', static fn (User $user): bool => $user->role === 'dpo');

        PersonalData::for(Customer::class)
            ->label('Customer')
            ->subject(email: 'email')
            ->export(['id', 'name', 'email', 'phone', 'birthday', 'created_at'])
            ->erase(Erase::anonymize([
                'name' => Rule::fixed('Erased customer'),
                'email' => Rule::uniqueToken('erased-', '@erased.invalid'),
                'phone' => Rule::null(),
                'birthday' => Rule::null(),
            ]));

        PersonalData::for(Order::class)
            ->label('Orders')
            ->of(Customer::class)
            ->matchedBy('customer_id')
            ->export(['id', 'number', 'total', 'shipping_address', 'billing_name', 'created_at'])
            ->erase(Erase::anonymize([
                'shipping_address' => Rule::fixed('Erased'),
                'billing_name' => Rule::fixed('Erased'),
            ]));

        PersonalData::for(OrderItem::class)
            ->label('Order items')
            ->of(Customer::class)
            ->matchedUsing(static function (Builder $query, $subject): void {
                $query->whereIn('order_id', Order::query()->withoutGlobalScopes()->where('customer_id', $subject->keyValue())->select('id'));
            })
            ->export(['id', 'order_id', 'sku', 'qty'])
            ->erase(Erase::delete());

        PersonalData::for(Ticket::class)
            ->label('Support tickets')
            ->of(Customer::class)
            ->matchedByEmail('customer_email')
            ->export(['id', 'subject', 'body', 'created_at'])
            ->erase(Erase::delete());

        PersonalData::for(Document::class)
            ->label('Documents')
            ->of(Customer::class)
            ->matchedBy('customer_id')
            ->export(['id', 'title', 'created_at'])
            ->files(static fn (Document $document): array => [['disk' => $document->disk, 'path' => $document->path]], columns: ['path'])
            ->erase(Erase::delete(files: true));

        PersonalData::for(Invoice::class)
            ->label('Invoices')
            ->of(Customer::class)
            ->matchedBy('customer_id')
            ->export(['id', 'number', 'amount', 'billed_to', 'issued_at'])
            ->erase(Erase::retain('Accounting law: invoices are kept for 10 years', days: 3650, from: 'issued_at'))
            ->retention(Retention::after(days: 3650, from: 'issued_at')->then(Erase::anonymize(['billed_to' => Rule::hash()])));

        GdprRequests::acknowledge('audit_notes', 'Internal notes, no personal data of the customer (demo decision).');
    }
}
