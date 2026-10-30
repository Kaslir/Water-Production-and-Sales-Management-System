<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_manufacturing_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained();
            $table->date('effective_from');
            $table->unsignedInteger('shelf_life_days');
            $table->string('shelf_life_evidence', 500);
            $table->decimal('water_treatment_per_case', 12, 2);
            $table->decimal('bottle_per_case', 12, 2);
            $table->decimal('inbound_transport_per_case', 12, 2);
            $table->decimal('cap_label_per_case', 12, 2);
            $table->decimal('labour_per_case', 12, 2);
            $table->decimal('electricity_per_case', 12, 2);
            $table->decimal('other_production_per_case', 12, 2);
            $table->foreignId('approved_by')->constrained('users', 'user_id');
            $table->timestamps();
            $table->unique(['product_id', 'effective_from'], 'product_policy_effective_unique');
        });

        Schema::table('production_batches', function (Blueprint $table) {
            $table->foreignId('manufacturing_policy_id')->nullable()->constrained('product_manufacturing_policies');
        });
    }

    public function down(): void
    {
        Schema::table('production_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manufacturing_policy_id');
        });

        Schema::dropIfExists('product_manufacturing_policies');
    }
};
