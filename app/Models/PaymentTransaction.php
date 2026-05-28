<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentTransaction extends Model
{
    protected $fillable = [
        'pedido_id',
        'mesa_id',
        'mesa_bill_id',
        'bill_account_id',
        'initiated_by',
        'confirmed_by',
        'amount',
        'method',
        'status',
        'reference',
        'client_paid_at',
        'confirmed_at',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'client_paid_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function pedido()
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }

    public function mesa()
    {
        return $this->belongsTo(Mesa::class, 'mesa_id');
    }

    public function mesaBill()
    {
        return $this->belongsTo(MesaBill::class);
    }

    public function billAccount()
    {
        return $this->belongsTo(BillAccount::class);
    }

    public function initiatedBy()
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function gatewayAttempts()
    {
        return $this->hasMany(PaymentGatewayAttempt::class);
    }
}
