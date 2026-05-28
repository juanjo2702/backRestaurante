<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pedido extends Model
{
    use HasFactory;

    protected $table = 'pedidos';
    protected $fillable = [
        'mesa_id',
        'usuario_id',
        'table_session_id',
        'order_source',
        'nombre_cliente',
        'telefono_cliente',
        'tipo_pedido',
        'estado',
        'total',
        'metodo_pago',
        'fecha_pago'
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'fecha_pago' => 'datetime',
    ];

    public function mesa()
    {
        return $this->belongsTo(Mesa::class, 'mesa_id');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function tableSession()
    {
        return $this->belongsTo(TableSession::class);
    }

    public function detalles()
    {
        return $this->hasMany(DetallePedido::class, 'pedido_id');
    }

    public function paymentTransactions()
    {
        return $this->hasMany(PaymentTransaction::class, 'pedido_id');
    }

    public function statusHistory()
    {
        return $this->hasMany(OrderStatusHistory::class, 'pedido_id');
    }
}
