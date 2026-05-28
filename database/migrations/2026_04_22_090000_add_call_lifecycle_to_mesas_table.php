<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mesas', function (Blueprint $table) {
            $table->string('llamada_estado')
                ->nullable()
                ->after('llamada_tipo')
                ->comment('pending, acknowledged');
            $table->foreignId('llamada_atendida_por')
                ->nullable()
                ->after('llamada_timestamp')
                ->constrained('usuarios')
                ->nullOnDelete();
            $table->timestamp('llamada_atendida_timestamp')
                ->nullable()
                ->after('llamada_atendida_por');
        });
    }

    public function down(): void
    {
        Schema::table('mesas', function (Blueprint $table) {
            $table->dropForeign(['llamada_atendida_por']);
            $table->dropColumn([
                'llamada_estado',
                'llamada_atendida_por',
                'llamada_atendida_timestamp',
            ]);
        });
    }
};
