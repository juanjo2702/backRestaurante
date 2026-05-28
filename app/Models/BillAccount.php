<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillAccount extends Model
{
    protected $fillable = [
        'mesa_bill_id',
        'table_session_id',
        'display_name',
        'owner_type',
        'status',
        'subtotal_amount',
        'paid_amount',
        'outstanding_amount',
        'sort_order',
    ];

    protected $casts = [
        'subtotal_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'outstanding_amount' => 'decimal:2',
    ];

    public function mesaBill()
    {
        return $this->belongsTo(MesaBill::class);
    }

    public function tableSession()
    {
        return $this->belongsTo(TableSession::class);
    }

    public function allocations()
    {
        return $this->hasMany(BillAccountAllocation::class);
    }

    public function paymentTransactions()
    {
        return $this->hasMany(PaymentTransaction::class);
    }
}
