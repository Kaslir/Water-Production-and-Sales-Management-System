<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_trips', function (Blueprint $table) {
            $table->string('truck_identifier', 80)->nullable()->change();
            $table->string('driver_name', 120)->nullable()->change();
            $table->string('trip_reference', 30)->nullable()->unique();
            $table->string('status', 20)->nullable()->index();
            $table->unsignedTinyInteger('active_dispatch_key')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('delivery_trips', function (Blueprint $table) {
            $table->dropUnique(['active_dispatch_key']);
            $table->dropIndex(['status']);
            $table->dropUnique(['trip_reference']);
            $table->dropColumn(['trip_reference', 'status', 'active_dispatch_key']);
        });
    }
};
