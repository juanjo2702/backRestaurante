<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentGatewayAttempt extends Model
{
    protected $fillable = [
        'payment_transaction_id',
        'provider',
        'stage',
        'outcome',
        'gateway_reference',
        'authorization_code',
        'card_brand',
        'card_last4',
        'request_token_hash',
        'response_payload',
        'processed_at',
    ];

    protected $casts = [
        'response_payload' => 'array',
        'processed_at' => 'datetime',
    ];

    public function paymentTransaction()
    {
        return $this->belongsTo(PaymentTransaction::class);
    }
}
