<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyPointTransaction extends Model
{
    protected $fillable = [
        'loyalty_point_id',
        'pedido_id',
        'user_id',
        'type',
        'points_delta',
        'balance_after',
        'reference',
        'notes',
    ];

    public function loyaltyPoint()
    {
        return $this->belongsTo(LoyaltyPoint::class);
    }

    public function pedido()
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
