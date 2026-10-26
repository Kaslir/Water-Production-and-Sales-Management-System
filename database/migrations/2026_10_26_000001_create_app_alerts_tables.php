<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users', 'user_id')->cascadeOnDelete();
            $table->string('alert_key', 160);
            $table->string('category', 40)->index();
            $table->string('severity', 20);
            $table->string('title', 160);
            $table->text('message');
            $table->string('target_type', 30);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'alert_key']);
            $table->index(['user_id', 'resolved_at', 'read_at']);
        });

        Schema::create('app_alert_settings', function (Blueprint $table) {
            $table->id();
            $table->timestamp('activated_at');
            $table->date('last_scan_on')->nullable();
        });

        DB::table('app_alert_settings')->insert(['id' => 1, 'activated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('app_alert_settings');
        Schema::dropIfExists('app_alerts');
    }
};
