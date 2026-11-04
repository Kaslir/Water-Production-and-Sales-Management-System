<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->date('requested_delivery_on')->nullable();
            $table->string('price_override_reason', 500)->nullable();
            $table->string('data_origin', 20)->default('unknown');
        });
        DB::table('sales_orders')->whereIn('created_by', DB::table('users')->where('email', 'like', 'synthetic.%@aquatrack.invalid')->pluck('user_id'))->update(['data_origin' => 'synthetic']);
        Schema::table('production_batches', function (Blueprint $table) {
            $table->string('water_source_reference', 255)->nullable();
            $table->decimal('water_input_litres', 12, 2)->nullable();
            $table->string('treatment_log_reference', 255)->nullable();
            $table->date('quality_tested_on')->nullable();
            $table->string('quality_evidence_reference', 255)->nullable();
            $table->decimal('quality_ph', 5, 2)->nullable();
            $table->decimal('quality_turbidity_ntu', 8, 3)->nullable();
            $table->string('microbiological_result', 20)->nullable();
        });
        Schema::table('dispatches', function (Blueprint $table) {
            $table->string('received_by', 255)->nullable();
            $table->string('receipt_reference', 255)->nullable();
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users', 'user_id');
            $table->string('reversal_reason', 500)->nullable();
        });
        Schema::table('operating_expenses', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users', 'user_id');
            $table->string('reversal_reason', 500)->nullable();
        });
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_batch_id')->constrained();
            $table->unsignedInteger('counted_cases');
            $table->unsignedInteger('system_cases');
            $table->integer('difference_cases');
            $table->string('reason', 500);
            $table->string('status', 20)->default('pending');
            $table->foreignId('counted_by')->constrained('users', 'user_id');
            $table->foreignId('approved_by')->nullable()->constrained('users', 'user_id');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
        Schema::create('customer_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained();
            $table->decimal('amount', 14, 2);
            $table->date('refunded_on');
            $table->string('method', 80);
            $table->string('reference', 120);
            $table->string('reason', 500);
            $table->foreignId('recorded_by')->constrained('users', 'user_id');
            $table->timestamps();
        });
        Schema::create('batch_actual_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_batch_id')->unique()->constrained();
            $table->decimal('actual_treatment', 14, 2);
            $table->decimal('actual_bottles', 14, 2);
            $table->decimal('actual_inbound', 14, 2);
            $table->decimal('actual_caps_labels', 14, 2);
            $table->decimal('actual_labour', 14, 2);
            $table->decimal('actual_electricity', 14, 2);
            $table->decimal('actual_other', 14, 2);
            $table->decimal('actual_total', 14, 2);
            $table->string('evidence_reference', 255);
            $table->foreignId('recorded_by')->constrained('users', 'user_id');
            $table->timestamps();
        });
        Schema::create('daily_order_closures', function (Blueprint $table) {
            $table->id();
            $table->date('order_day')->unique();
            $table->foreignId('recorded_by')->constrained('users', 'user_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_order_closures');
        Schema::dropIfExists('batch_actual_costs');
        Schema::dropIfExists('customer_refunds');
        Schema::dropIfExists('stock_counts');
        Schema::table('operating_expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['reversed_at', 'reversal_reason']);
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['reversed_at', 'reversal_reason']);
        });
        Schema::table('dispatches', function (Blueprint $table) {
            $table->dropColumn(['received_by', 'receipt_reference']);
        });
        Schema::table('production_batches', function (Blueprint $table) {
            $table->dropColumn(['water_source_reference', 'water_input_litres', 'treatment_log_reference', 'quality_tested_on', 'quality_evidence_reference', 'quality_ph', 'quality_turbidity_ntu', 'microbiological_result']);
        });
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['requested_delivery_on', 'price_override_reason', 'data_origin']);
        });
    }
};
