<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained();
            $table->foreignId('dispatch_line_id')->constrained();
            $table->unsignedInteger('cases');
            $table->decimal('credit_amount', 14, 2);
            $table->date('returned_on');
            $table->string('disposition')->default('destroyed_on_receipt');
            $table->text('reason');
            $table->foreignId('recorded_by')->constrained('users', 'user_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_returns');
    }
};
