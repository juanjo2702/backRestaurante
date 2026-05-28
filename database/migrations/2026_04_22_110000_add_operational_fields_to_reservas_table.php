<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->string('codigo_reserva')->nullable()->unique()->after('id');
            $table->enum('origen', ['public', 'staff'])->default('public')->after('estado');
            $table->enum('operational_status', ['scheduled', 'arrived', 'seated', 'no_show', 'cancelled', 'completed'])->default('scheduled')->after('origen');
            $table->decimal('garantia_monto', 10, 2)->default(0)->after('operational_status');
            $table->enum('garantia_estado', ['not_required', 'pending_review', 'approved', 'rejected'])->default('not_required')->after('garantia_monto');
            $table->string('garantia_referencia')->nullable()->after('garantia_estado');
            $table->string('garantia_comprobante_disk')->nullable()->default('public')->after('garantia_referencia');
            $table->string('garantia_comprobante_path')->nullable()->after('garantia_comprobante_disk');
            $table->timestamp('garantia_subida_at')->nullable()->after('garantia_comprobante_path');
            $table->foreignId('garantia_revisada_por')->nullable()->after('garantia_subida_at')->constrained('usuarios')->nullOnDelete();
            $table->timestamp('garantia_revisada_at')->nullable()->after('garantia_revisada_por');
            $table->text('garantia_revision_notas')->nullable()->after('garantia_revisada_at');
            $table->string('tracking_token', 80)->nullable()->unique()->after('garantia_revision_notas');
            $table->timestamp('arrived_at')->nullable()->after('tracking_token');
            $table->timestamp('seated_at')->nullable()->after('arrived_at');
            $table->timestamp('no_show_at')->nullable()->after('seated_at');
            $table->timestamp('cancelled_at')->nullable()->after('no_show_at');
            $table->timestamp('completed_at')->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('garantia_revisada_por');
            $table->dropColumn([
                'codigo_reserva',
                'origen',
                'operational_status',
                'garantia_monto',
                'garantia_estado',
                'garantia_referencia',
                'garantia_comprobante_disk',
                'garantia_comprobante_path',
                'garantia_subida_at',
                'garantia_revisada_at',
                'garantia_revision_notas',
                'tracking_token',
                'arrived_at',
                'seated_at',
                'no_show_at',
                'cancelled_at',
                'completed_at',
            ]);
        });
    }
};
