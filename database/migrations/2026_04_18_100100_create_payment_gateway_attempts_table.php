<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateway_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_transaction_id')->constrained('payment_transactions')->cascadeOnDelete();
            $table->string('provider')->default('mock_gateway');
            $table->enum('stage', ['authorization', 'capture']);
            $table->enum('outcome', ['success', 'declined', 'insufficient_funds', 'timeout']);
            $table->string('gateway_reference')->nullable();
            $table->string('authorization_code')->nullable();
            $table->string('card_brand', 32)->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->string('request_token_hash', 64);
            $table->json('response_payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['payment_transaction_id', 'stage']);
            $table->index(['provider', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_attempts');
    }
};
