<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('water_supply_rates', function (Blueprint $table) {
            $table->id();
            $table->date('effective_from')->unique();
            $table->decimal('price_per_litre', 12, 4);
            $table->string('bill_or_tariff_reference', 255);
            $table->foreignId('approved_by')->constrained('users', 'user_id');
            $table->timestamps();
        });

        Schema::table('production_batches', function (Blueprint $table) {
            $table->foreignId('water_supply_rate_id')->nullable()->constrained('water_supply_rates');
            $table->decimal('water_supply_cost', 12, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('production_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('water_supply_rate_id');
            $table->dropColumn('water_supply_cost');
        });

        Schema::dropIfExists('water_supply_rates');
    }
};
