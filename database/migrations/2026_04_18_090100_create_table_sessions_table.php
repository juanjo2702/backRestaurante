<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('table_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mesa_id')->constrained('mesas')->cascadeOnDelete();
            $table->string('session_token_hash')->unique();
            $table->enum('status', ['active', 'closed', 'expired'])->default('active');
            $table->dateTime('started_at');
            $table->dateTime('expires_at');
            $table->dateTime('last_seen_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->string('client_fingerprint')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['mesa_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_sessions');
    }
};
