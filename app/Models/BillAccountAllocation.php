<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillAccountAllocation extends Model
{
    protected $fillable = [
        'bill_account_id',
        'detalle_pedido_id',
        'source_table_session_id',
        'allocation_type',
        'allocated_amount',
        'allocated_ratio',
        'notes',
    ];

    protected $casts = [
        'allocated_amount' => 'decimal:2',
        'allocated_ratio' => 'decimal:4',
    ];

    public function billAccount()
    {
        return $this->belongsTo(BillAccount::class);
    }

    public function detallePedido()
    {
        return $this->belongsTo(DetallePedido::class, 'detalle_pedido_id');
    }

    public function sourceTableSession()
    {
        return $this->belongsTo(TableSession::class, 'source_table_session_id');
    }
}
