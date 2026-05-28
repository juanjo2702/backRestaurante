<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('loyalty_points', function (Blueprint $table) {
            $table->id();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('usuarios')->onDelete('cascade');
            $table->integer('points')->default(0);
            $table->foreignId('last_order_id')->nullable()->constrained('pedidos')->onDelete('set null');
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
            
            $table->index(['customer_phone']);
            $table->index(['user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_points');
    }
};
