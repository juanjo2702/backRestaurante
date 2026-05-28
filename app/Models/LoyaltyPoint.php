<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use App\Models\Pedido;

class LoyaltyPoint extends Model
{
    protected $fillable = [
        'customer_name',
        'customer_phone',
        'user_id',
        'points',
        'last_order_id',
        'last_activity_at',
    ];

    protected $casts = [
        'last_activity_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function lastOrder()
    {
        return $this->belongsTo(Pedido::class, 'last_order_id');
    }

    public function transactions()
    {
        return $this->hasMany(LoyaltyPointTransaction::class);
    }
}
