<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('activated_at')->nullable();
        });

        Schema::create('employee_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users', 'user_id')->restrictOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('status', 20)->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_invitations');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('activated_at'));
    }
};
