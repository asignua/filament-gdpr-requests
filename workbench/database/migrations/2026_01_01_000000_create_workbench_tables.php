<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff users plus an invented shop: customers, orders (soft deletes), items, tickets (found by e-mail only),
 * documents (files), invoices (kept by law) and an audit table that the data map does not know on purpose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('manager');
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->date('birthday')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers');
            $table->string('number')->unique();
            $table->unsignedInteger('total');
            $table->string('shipping_address')->nullable();
            $table->string('billing_name')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders');
            $table->string('sku');
            $table->unsignedInteger('qty');
        });

        Schema::create('tickets', function (Blueprint $table): void {
            $table->id();
            $table->string('customer_email');
            $table->string('subject');
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers');
            $table->string('title');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers');
            $table->string('number')->unique();
            $table->unsignedInteger('amount');
            $table->string('billed_to');
            $table->dateTime('issued_at');
        });

        Schema::create('audit_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers');
            $table->string('note');
        });
    }
};
