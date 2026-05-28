<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TableSession extends Model
{
    protected $fillable = [
        'mesa_id',
        'session_token_hash',
        'status',
        'started_at',
        'expires_at',
        'last_seen_at',
        'ended_at',
        'client_fingerprint',
        'ip_address',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function mesa()
    {
        return $this->belongsTo(Mesa::class, 'mesa_id');
    }

    public function pedidos()
    {
        return $this->hasMany(Pedido::class);
    }

    public function billAccounts()
    {
        return $this->hasMany(BillAccount::class);
    }
}
