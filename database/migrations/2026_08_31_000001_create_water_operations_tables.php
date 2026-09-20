<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('customers', function (Blueprint $table) { $table->id('customer_id'); $table->string('name'); $table->string('phone_number')->unique(); $table->timestamps(); });
        Schema::create('inventories', function (Blueprint $table) { $table->id('inventory_id'); $table->decimal('quantity_available', 14, 2)->default(0); $table->timestamp('last_updated')->nullable(); $table->timestamps(); });
        Schema::create('productions', function (Blueprint $table) { $table->id('production_id'); $table->date('production_date')->index(); $table->decimal('quantity_produced', 14, 2); $table->text('notes')->nullable(); $table->foreignId('user_id')->constrained('users', 'user_id'); $table->timestamps(); });
        Schema::create('distributions', function (Blueprint $table) { $table->id('distribution_id'); $table->date('distribution_date')->index(); $table->decimal('quantity_distributed', 14, 2); $table->string('destination'); $table->text('notes')->nullable(); $table->foreignId('user_id')->constrained('users', 'user_id'); $table->timestamps(); });
        Schema::create('sales', function (Blueprint $table) { $table->id('sale_id'); $table->date('sale_date')->index(); $table->decimal('quantity_sold', 14, 2); $table->decimal('unit_price', 14, 2); $table->decimal('total_amount', 14, 2); $table->boolean('consumes_inventory')->default(true); $table->foreignId('customer_id')->constrained('customers', 'customer_id'); $table->foreignId('user_id')->constrained('users', 'user_id'); $table->timestamps(); });
        Schema::create('inventory_transactions', function (Blueprint $table) { $table->id('transaction_id'); $table->enum('transaction_type', ['IN','OUT','ADJUSTMENT']); $table->decimal('quantity', 14, 2); $table->string('reference_type')->nullable(); $table->unsignedBigInteger('reference_id')->nullable(); $table->date('transaction_date')->index(); $table->foreignId('user_id')->constrained('users', 'user_id'); $table->text('notes')->nullable(); $table->timestamps(); $table->index(['reference_type','reference_id']); });
        Schema::create('forecasts', function (Blueprint $table) { $table->id('forecast_id'); $table->date('forecast_date'); $table->string('forecast_period'); $table->decimal('forecast_quantity', 14, 2); $table->string('method'); $table->decimal('mae', 14, 4)->nullable(); $table->decimal('rmse', 14, 4)->nullable(); $table->decimal('mape', 14, 4)->nullable(); $table->timestamps(); });
        Schema::create('reports', function (Blueprint $table) { $table->id('report_id'); $table->string('report_type'); $table->timestamp('generated_date'); $table->foreignId('generated_by')->constrained('users', 'user_id'); $table->timestamps(); });
        Schema::create('security_codes', function (Blueprint $table) { $table->id(); $table->foreignId('user_id')->constrained('users', 'user_id')->cascadeOnDelete(); $table->string('code_hash'); $table->timestamp('expires_at'); $table->timestamp('used_at')->nullable(); $table->timestamps(); });
        Schema::create('settings', function (Blueprint $table) { $table->id(); $table->string('key')->unique(); $table->string('value'); $table->timestamps(); });
    }
    public function down(): void { Schema::dropIfExists('settings'); Schema::dropIfExists('security_codes'); Schema::dropIfExists('reports'); Schema::dropIfExists('forecasts'); Schema::dropIfExists('inventory_transactions'); Schema::dropIfExists('sales'); Schema::dropIfExists('distributions'); Schema::dropIfExists('productions'); Schema::dropIfExists('inventories'); Schema::dropIfExists('customers'); }
};
