<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('failed_delivery_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispatch_line_id')->unique()->constrained();
            $table->unsignedInteger('cases');
            $table->string('status'); // with_truck, quality_hold, released, disposed
            $table->text('failure_reason');
            $table->string('warehouse_reference')->nullable();
            $table->text('quality_notes')->nullable();
            $table->foreignId('recorded_by')->constrained('users', 'user_id');
            $table->foreignId('inspected_by')->nullable()->constrained('users', 'user_id');
            $table->date('returned_on')->nullable();
            $table->date('inspected_on')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_delivery_lines');
    }
};
