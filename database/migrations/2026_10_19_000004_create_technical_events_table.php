<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technical_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 120);
            $table->string('source', 255)->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_events');
    }
};
