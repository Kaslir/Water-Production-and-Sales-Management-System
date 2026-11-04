<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_batches', function (Blueprint $table) {
            $table->string('unplanned_reason', 500)->nullable();
            $table->foreignId('unplanned_authorized_by')->nullable()->constrained('users', 'user_id');
        });
    }

    public function down(): void
    {
        Schema::table('production_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unplanned_authorized_by');
            $table->dropColumn('unplanned_reason');
        });
    }
};
