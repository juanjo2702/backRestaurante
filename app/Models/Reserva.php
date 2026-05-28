<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Reserva extends Model
{
    use HasFactory;

    protected $table = 'reservas';
    protected $fillable = [
        'codigo_reserva',
        'mesa_id',
        'nombre_cliente',
        'cantidad_personas',
        'hora_reserva',
        'telefono',
        'estado',
        'origen',
        'operational_status',
        'garantia_monto',
        'garantia_estado',
        'garantia_referencia',
        'garantia_comprobante_disk',
        'garantia_comprobante_path',
        'garantia_subida_at',
        'garantia_revisada_por',
        'garantia_revisada_at',
        'garantia_revision_notas',
        'tracking_token',
        'arrived_at',
        'seated_at',
        'no_show_at',
        'cancelled_at',
        'completed_at',
    ];

    protected $casts = [
        'hora_reserva' => 'datetime',
        'garantia_monto' => 'decimal:2',
        'garantia_subida_at' => 'datetime',
        'garantia_revisada_at' => 'datetime',
        'arrived_at' => 'datetime',
        'seated_at' => 'datetime',
        'no_show_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function mesa()
    {
        return $this->belongsTo(Mesa::class, 'mesa_id');
    }

    public function garantiaRevisadaPorUsuario()
    {
        return $this->belongsTo(User::class, 'garantia_revisada_por');
    }

    public function getGarantiaComprobanteUrlAttribute(): ?string
    {
        if (!$this->garantia_comprobante_path) {
            return null;
        }

        return Storage::disk($this->garantia_comprobante_disk ?: 'public')->url($this->garantia_comprobante_path);
    }
}
