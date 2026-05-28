<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class Mesa extends Model
{
    use HasFactory;

    protected $table = 'mesas';
    protected $fillable = [
        'uuid',
        'qr_signature',
        'is_qr_enabled',
        'numero', 
        'capacidad', 
        'estado', 
        'ubicacion_x', 
        'ubicacion_y',
        'llamada_tipo',
        'llamada_estado',
        'llamada_timestamp',
        'llamada_atendida_por',
        'llamada_atendida_timestamp',
        'mesero_asignado_id',
        'pago_pendiente_monto',
        'pago_pendiente_cliente_pago',
        'pago_pendiente_metodo',
        'pago_pendiente_fecha',
        'ocupada_desde'
    ];

    protected $casts = [
        'is_qr_enabled' => 'boolean',
        'llamada_timestamp' => 'datetime',
        'llamada_atendida_timestamp' => 'datetime',
        'pago_pendiente_fecha' => 'datetime',
        'ocupada_desde' => 'datetime',
    ];

    public function reservas()
    {
        return $this->hasMany(Reserva::class, 'mesa_id');
    }

    public function pedidos()
    {
        return $this->hasMany(Pedido::class, 'mesa_id');
    }

    public function meseroAsignado()
    {
        return $this->belongsTo(User::class, 'mesero_asignado_id');
    }

    public function callAttendedBy()
    {
        return $this->belongsTo(User::class, 'llamada_atendida_por');
    }

    public function paymentTransactions()
    {
        return $this->hasMany(PaymentTransaction::class, 'mesa_id');
    }

    public function tableSessions()
    {
        return $this->hasMany(TableSession::class, 'mesa_id');
    }

    public function mesaBills()
    {
        return $this->hasMany(MesaBill::class, 'mesa_id');
    }
}
