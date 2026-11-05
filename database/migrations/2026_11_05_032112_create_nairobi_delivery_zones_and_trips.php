<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('county', 100);
            $table->decimal('fixed_fee', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('delivery_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_zone_id')->constrained();
            $table->string('county', 100);
            $table->string('name', 100);
            $table->unique(['county', 'name']);
        });
        Schema::create('delivery_zone_fee_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_zone_id')->constrained();
            $table->decimal('previous_fee', 12, 2);
            $table->decimal('new_fee', 12, 2);
            $table->string('reason', 500)->nullable();
            $table->foreignId('changed_by')->constrained('users', 'user_id');
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->string('delivery_county', 100)->nullable();
            $table->foreignId('delivery_area_id')->nullable()->constrained();
        });
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('delivery_county', 100)->nullable();
            $table->foreignId('delivery_area_id')->nullable()->constrained();
            $table->foreignId('delivery_zone_id')->nullable()->constrained();
            $table->decimal('delivery_fee', 12, 2)->default(0);
        });
        Schema::create('delivery_trips', function (Blueprint $table) {
            $table->id();
            $table->string('truck_identifier', 80);
            $table->string('driver_name', 120);
            $table->date('delivery_on');
            $table->decimal('fuel_expense', 14, 2)->default(0);
            $table->decimal('other_expense', 14, 2)->default(0);
            $table->string('expense_reference', 120)->nullable();
            $table->foreignId('created_by')->constrained('users', 'user_id');
            $table->foreignId('expense_recorded_by')->nullable()->constrained('users', 'user_id');
            $table->timestamps();
        });
        Schema::create('delivery_trip_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_id')->unique()->constrained();
            $table->timestamps();
        });
        Schema::create('delivery_fee_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->unique()->constrained();
            $table->decimal('amount', 12, 2);
            $table->string('reason', 500);
            $table->foreignId('approved_by')->constrained('users', 'user_id');
            $table->timestamps();
        });

        $zones = [
            'A' => [800, ['Industrial Area', 'South B', 'South C']],
            'B' => [1000, ['Nairobi CBD', 'Eastleigh', 'Pangani']],
            'C' => [1200, ['Westlands', 'Parklands', 'Ngara']],
            'D' => [1500, ['Kilimani', 'Kileleshwa', 'Lavington']],
            'E' => [2000, ['Karen', 'Runda', 'Gigiri']],
        ];
        foreach ($zones as $code => [$fee, $areas]) {
            $zoneId = DB::table('delivery_zones')->insertGetId(['code' => $code, 'county' => 'Nairobi', 'fixed_fee' => $fee, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($areas as $area) {
                DB::table('delivery_areas')->insert(['delivery_zone_id' => $zoneId, 'county' => 'Nairobi', 'name' => $area]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_fee_credits');
        Schema::dropIfExists('delivery_trip_orders');
        Schema::dropIfExists('delivery_trips');
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_area_id');
            $table->dropConstrainedForeignId('delivery_zone_id');
            $table->dropColumn(['delivery_county', 'delivery_fee']);
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_area_id');
            $table->dropColumn('delivery_county');
        });
        Schema::dropIfExists('delivery_zone_fee_changes');
        Schema::dropIfExists('delivery_areas');
        Schema::dropIfExists('delivery_zones');
    }
};
