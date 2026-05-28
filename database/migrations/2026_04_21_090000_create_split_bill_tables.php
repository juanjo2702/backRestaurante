<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->foreignId('table_session_id')
                ->nullable()
                ->after('usuario_id')
                ->constrained('table_sessions')
                ->nullOnDelete();
            $table->enum('order_source', ['public_table', 'staff', 'takeaway'])
                ->default('staff')
                ->after('table_session_id');

            $table->index(['mesa_id', 'table_session_id']);
        });

        Schema::create('mesa_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mesa_id')->constrained('mesas')->cascadeOnDelete();
            $table->enum('status', ['open', 'settling', 'settled', 'cancelled'])->default('open');
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->decimal('paid_amount', 10, 2)->default(0);
            $table->decimal('outstanding_amount', 10, 2)->default(0);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['mesa_id', 'status']);
        });

        Schema::create('bill_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mesa_bill_id')->constrained('mesa_bills')->cascadeOnDelete();
            $table->foreignId('table_session_id')->nullable()->constrained('table_sessions')->nullOnDelete();
            $table->string('display_name');
            $table->enum('owner_type', ['session', 'manual', 'shared'])->default('manual');
            $table->enum('status', ['open', 'partial', 'paid', 'merged'])->default('open');
            $table->decimal('subtotal_amount', 10, 2)->default(0);
            $table->decimal('paid_amount', 10, 2)->default(0);
            $table->decimal('outstanding_amount', 10, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['mesa_bill_id', 'status']);
        });

        Schema::create('bill_account_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_account_id')->constrained('bill_accounts')->cascadeOnDelete();
            $table->foreignId('detalle_pedido_id')->constrained('detalles_pedido')->cascadeOnDelete();
            $table->foreignId('source_table_session_id')->nullable()->constrained('table_sessions')->nullOnDelete();
            $table->enum('allocation_type', ['full', 'split_equal', 'split_custom'])->default('full');
            $table->decimal('allocated_amount', 10, 2);
            $table->decimal('allocated_ratio', 10, 4)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['bill_account_id', 'detalle_pedido_id']);
        });

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->foreignId('mesa_bill_id')
                ->nullable()
                ->after('mesa_id')
                ->constrained('mesa_bills')
                ->nullOnDelete();
            $table->foreignId('bill_account_id')
                ->nullable()
                ->after('mesa_bill_id')
                ->constrained('bill_accounts')
                ->nullOnDelete();

            $table->index(['mesa_bill_id', 'status']);
            $table->index(['bill_account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bill_account_id');
            $table->dropConstrainedForeignId('mesa_bill_id');
        });

        Schema::dropIfExists('bill_account_allocations');
        Schema::dropIfExists('bill_accounts');
        Schema::dropIfExists('mesa_bills');

        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('table_session_id');
            $table->dropColumn('order_source');
        });
    }
};
