<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_forecast_decisions', function (Blueprint $table) {
            $table->date('planned_for')->nullable()->after('approved_cases');
            $table->unsignedInteger('cases_completed')->default(0)->after('planned_for');
            $table->string('plan_status')->default('planned')->after('cases_completed');
        });
        Schema::table('production_batches', function (Blueprint $table) {
            $table->foreignId('production_forecast_decision_id')->nullable()->after('product_id')
                ->constrained('production_forecast_decisions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('production_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('production_forecast_decision_id');
        });
        Schema::table('production_forecast_decisions', function (Blueprint $table) {
            $table->dropColumn(['planned_for', 'cases_completed', 'plan_status']);
        });
    }
};
