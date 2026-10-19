<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('name');
            $table->unsignedInteger('volume_ml');
            $table->unsignedInteger('bottles_per_case');
            $table->decimal('default_price_per_case', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('contact_person')->nullable();
        });

        Schema::create('production_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained();
            $table->string('batch_code')->unique();
            $table->date('produced_on');
            $table->date('expires_on');
            $table->unsignedInteger('cases_produced');
            $table->unsignedInteger('cases_on_hand');
            $table->unsignedInteger('quality_rejected_cases')->default(0);
            $table->unsignedInteger('cases_reserved')->default(0);
            $table->string('status')->default('quality_hold');
            $table->decimal('water_treatment_cost', 12, 2)->default(0);
            $table->decimal('bottle_cost', 12, 2)->default(0);
            $table->decimal('cap_label_cost', 12, 2)->default(0);
            $table->decimal('labour_cost', 12, 2)->default(0);
            $table->decimal('electricity_cost', 12, 2)->default(0);
            $table->decimal('other_production_cost', 12, 2)->default(0);
            $table->decimal('total_cost', 14, 2);
            $table->decimal('cost_per_case', 14, 4);
            $table->text('quality_notes')->nullable();
            $table->foreignId('produced_by')->constrained('users', 'user_id');
            $table->foreignId('released_by')->nullable()->constrained('users', 'user_id');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'status', 'expires_on']);
        });

        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->foreignId('customer_id')->constrained('customers', 'customer_id');
            $table->date('ordered_on');
            $table->string('status')->default('draft');
            $table->string('delivery_address');
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('delivery_cost', 12, 2)->default(0);
            $table->decimal('total_amount', 14, 2);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users', 'user_id');
            $table->timestamps();
        });

        Schema::create('sales_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->unsignedInteger('cases_ordered');
            $table->decimal('price_per_case', 12, 2);
            $table->decimal('line_total', 14, 2);
            $table->timestamps();
            $table->unique(['sales_order_id', 'product_id']);
        });

        Schema::create('batch_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('production_batch_id')->constrained();
            $table->unsignedInteger('cases_reserved');
            $table->timestamps();
            $table->unique(['sales_order_item_id', 'production_batch_id'], 'reservation_item_batch_unique');
        });

        Schema::create('dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->unique()->constrained();
            $table->date('dispatched_on');
            $table->date('delivered_on')->nullable();
            $table->string('status')->default('dispatched');
            $table->string('delivery_reference')->nullable();
            $table->foreignId('dispatched_by')->constrained('users', 'user_id');
            $table->timestamps();
        });

        Schema::create('dispatch_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispatch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_item_id')->constrained();
            $table->foreignId('production_batch_id')->constrained();
            $table->unsignedInteger('cases');
            $table->decimal('cost_per_case', 14, 4);
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->unique()->constrained();
            $table->string('invoice_number')->unique();
            $table->date('issued_on');
            $table->decimal('amount', 14, 2);
            $table->string('status')->default('unpaid');
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained();
            $table->decimal('amount', 14, 2);
            $table->date('paid_on');
            $table->string('method');
            $table->string('reference')->nullable();
            $table->foreignId('recorded_by')->constrained('users', 'user_id');
            $table->timestamps();
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_batch_id')->constrained();
            $table->integer('signed_cases');
            $table->string('reason');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('performed_by')->constrained('users', 'user_id');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('batch_disposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_batch_id')->constrained();
            $table->unsignedInteger('cases');
            $table->string('reason');
            $table->date('disposed_on');
            $table->foreignId('recorded_by')->constrained('users', 'user_id');
            $table->timestamps();
        });

        Schema::create('operating_expenses', function (Blueprint $table) {
            $table->id();
            $table->date('expense_date');
            $table->string('category');
            $table->decimal('amount', 14, 2);
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->constrained('users', 'user_id');
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->string('event');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('password_reset_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users', 'user_id')->cascadeOnDelete();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('product_forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained();
            $table->date('origin_date');
            $table->unsignedSmallInteger('horizon_days');
            $table->string('method');
            $table->decimal('predicted_cases', 14, 2);
            $table->decimal('mae_cases', 14, 2)->nullable();
            $table->decimal('bias_cases', 14, 2)->nullable();
            $table->json('evaluation')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'origin_date', 'horizon_days', 'method'], 'product_forecast_identity');
        });

        DB::table('products')->insert([
            ['sku' => 'WATER-500ML', 'name' => '500 mL bottled water', 'volume_ml' => 500, 'bottles_per_case' => 24, 'default_price_per_case' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['sku' => 'WATER-1L', 'name' => '1 L bottled water', 'volume_ml' => 1000, 'bottles_per_case' => 12, 'default_price_per_case' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['sku' => 'WATER-5L', 'name' => '5 L bottled water', 'volume_ml' => 5000, 'bottles_per_case' => 4, 'default_price_per_case' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['sku' => 'WATER-20L', 'name' => '20 L bottled water', 'volume_ml' => 20000, 'bottles_per_case' => 1, 'default_price_per_case' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        foreach (['product_forecasts', 'password_reset_codes', 'audit_logs', 'operating_expenses', 'batch_disposals', 'stock_movements', 'payments', 'invoices', 'dispatch_lines', 'dispatches', 'batch_reservations', 'sales_order_items', 'sales_orders', 'production_batches', 'products'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['email', 'address', 'contact_person']);
        });
    }
};
