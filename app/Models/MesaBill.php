<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MesaBill extends Model
{
    protected $fillable = [
        'mesa_id',
        'status',
        'total_amount',
        'paid_amount',
        'outstanding_amount',
        'opened_at',
        'closed_at',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'outstanding_amount' => 'decimal:2',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function mesa()
    {
        return $this->belongsTo(Mesa::class, 'mesa_id');
    }

    public function accounts()
    {
        return $this->hasMany(BillAccount::class);
    }

    public function paymentTransactions()
    {
        return $this->hasMany(PaymentTransaction::class);
    }
}
