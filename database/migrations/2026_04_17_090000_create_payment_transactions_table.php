<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->nullable()->constrained('pedidos')->nullOnDelete();
            $table->foreignId('mesa_id')->nullable()->constrained('mesas')->nullOnDelete();
            $table->foreignId('initiated_by')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('method', 20)->nullable();
            $table->enum('status', ['pending', 'client_paid', 'confirmed', 'cancelled'])->default('pending');
            $table->string('reference')->nullable();
            $table->timestamp('client_paid_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['mesa_id', 'status']);
            $table->index(['pedido_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
