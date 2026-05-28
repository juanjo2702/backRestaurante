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
        Schema::table('mesas', function (Blueprint $table) {
            $table->string('llamada_tipo')->nullable()->comment('attention, bill');
            $table->timestamp('llamada_timestamp')->nullable();
            $table->foreignId('mesero_asignado_id')->nullable()->constrained('usuarios')->onDelete('set null');
            $table->decimal('pago_pendiente_monto', 10, 2)->nullable();
            $table->boolean('pago_pendiente_cliente_pago')->default(false);
            $table->string('pago_pendiente_metodo')->nullable()->comment('qr, cash, card');
            $table->timestamp('pago_pendiente_fecha')->nullable();
            $table->timestamp('ocupada_desde')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mesas', function (Blueprint $table) {
            $table->dropForeign(['mesero_asignado_id']);
            $table->dropColumn([
                'llamada_tipo',
                'llamada_timestamp',
                'mesero_asignado_id',
                'pago_pendiente_monto',
                'pago_pendiente_cliente_pago',
                'pago_pendiente_metodo',
                'pago_pendiente_fecha',
                'ocupada_desde'
            ]);
        });
    }
};
