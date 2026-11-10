<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('production_forecast_decisions', 'forecast_predicted_cases')) {
            Schema::table('production_forecast_decisions', function (Blueprint $table) {
                $table->decimal('forecast_predicted_cases', 14, 2)->nullable();
                $table->decimal('forecast_mae_cases', 14, 2)->nullable();
                $table->decimal('forecast_bias_cases', 14, 2)->nullable();
                $table->json('forecast_evaluation')->nullable();
            });
        }

        DB::table('production_forecast_decisions')->orderBy('id')->chunkById(200, function ($decisions): void {
            foreach ($decisions as $decision) {
                $forecast = DB::table('product_forecasts')->where('id', $decision->product_forecast_id)->first();
                if ($forecast) {
                    DB::table('production_forecast_decisions')->where('id', $decision->id)->update([
                        'forecast_predicted_cases' => $forecast->predicted_cases,
                        'forecast_mae_cases' => $forecast->mae_cases,
                        'forecast_bias_cases' => $forecast->bias_cases,
                        'forecast_evaluation' => $forecast->evaluation,
                    ]);
                }
            }
        });

        Schema::table('product_forecasts', function (Blueprint $table) {
            $table->index(['product_id', 'origin_date', 'horizon_days', 'method'], 'product_forecast_lookup');
            $table->dropUnique('product_forecast_identity');
        });
    }

    public function down(): void
    {
        // Historic forecast runs can share an origin; recreating the old unique key would destroy history.
        Schema::table('production_forecast_decisions', function (Blueprint $table) {
            $table->dropColumn(['forecast_predicted_cases', 'forecast_mae_cases', 'forecast_bias_cases', 'forecast_evaluation']);
        });
    }
};
