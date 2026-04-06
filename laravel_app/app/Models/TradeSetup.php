<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TradeSetup extends Model
{
    use HasFactory;

    protected $fillable = [
        'symbol_id',
        'source_candidate_id',
        'status',
        'entry_price',
        'stop_price',
        'target1_price',
        'target2_price',
        'risk_percent',
        'sizing_json',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'entry_price' => 'decimal:4',
            'stop_price' => 'decimal:4',
            'target1_price' => 'decimal:4',
            'target2_price' => 'decimal:4',
            'risk_percent' => 'decimal:3',
            'sizing_json' => 'array',
        ];
    }

    public function symbol(): BelongsTo
    {
        return $this->belongsTo(Symbol::class);
    }

    public function sourceCandidate(): BelongsTo
    {
        return $this->belongsTo(WatchlistCandidate::class, 'source_candidate_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function tradeReview(): HasOne
    {
        return $this->hasOne(TradeReview::class);
    }
}
