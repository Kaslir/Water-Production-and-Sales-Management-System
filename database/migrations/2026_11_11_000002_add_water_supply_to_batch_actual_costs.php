<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batch_actual_costs', function (Blueprint $table) {
            $table->decimal('actual_water_supply', 14, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('batch_actual_costs', function (Blueprint $table) {
            $table->dropColumn('actual_water_supply');
        });
    }
};
