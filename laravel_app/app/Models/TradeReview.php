<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeReview extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'trade_setup_id',
        'outcome_status',
        'pnl_amount',
        'pnl_percent',
        'review_text',
        'lessons_json',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'pnl_amount' => 'decimal:2',
            'pnl_percent' => 'decimal:3',
            'lessons_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function tradeSetup(): BelongsTo
    {
        return $this->belongsTo(TradeSetup::class);
    }
}
