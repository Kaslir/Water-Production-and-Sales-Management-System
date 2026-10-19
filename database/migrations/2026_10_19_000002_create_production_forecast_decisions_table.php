<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_forecast_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_forecast_id')->constrained();
            $table->unsignedInteger('recommended_cases');
            $table->unsignedInteger('approved_cases');
            $table->text('reason')->nullable();
            $table->foreignId('decided_by')->constrained('users', 'user_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_forecast_decisions');
    }
};
