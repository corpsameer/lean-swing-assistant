<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'trade_setup_id',
        'symbol_id',
        'broker_order_id',
        'order_type',
        'side',
        'quantity',
        'limit_price',
        'stop_price',
        'status',
        'placed_at',
        'filled_at',
        'meta_json',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'limit_price' => 'decimal:4',
            'stop_price' => 'decimal:4',
            'placed_at' => 'datetime',
            'filled_at' => 'datetime',
            'meta_json' => 'array',
        ];
    }

    public function tradeSetup(): BelongsTo
    {
        return $this->belongsTo(TradeSetup::class);
    }

    public function symbol(): BelongsTo
    {
        return $this->belongsTo(Symbol::class);
    }
}
