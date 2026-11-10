<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_manufacturing_policies', function (Blueprint $table) {
            $table->string('shelf_life_evidence', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('product_manufacturing_policies', function (Blueprint $table) {
            $table->string('shelf_life_evidence', 500)->change();
        });
    }
};
