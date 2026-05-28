<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderStatusHistory extends Model
{
    protected $table = 'order_status_history';

    protected $fillable = [
        'pedido_id',
        'user_id',
        'from_status',
        'to_status',
        'reason',
    ];

    public function pedido()
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
