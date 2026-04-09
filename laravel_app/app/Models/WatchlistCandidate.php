<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WatchlistCandidate extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'run_id',
        'symbol_id',
        'stage',
        'status',
        'setup_type',
        'score_total',
        'breakout_low_price',
        'breakout_high_price',
        'support_low_price',
        'support_high_price',
        'trigger_price',
        'trigger_band_low',
        'trigger_band_high',
        'reasoning_text',
        'prompt_output_json',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'score_total' => 'decimal:3',
            'breakout_low_price' => 'decimal:4',
            'breakout_high_price' => 'decimal:4',
            'support_low_price' => 'decimal:4',
            'support_high_price' => 'decimal:4',
            'trigger_price' => 'decimal:4',
            'trigger_band_low' => 'decimal:4',
            'trigger_band_high' => 'decimal:4',
            'prompt_output_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    public function symbol(): BelongsTo
    {
        return $this->belongsTo(Symbol::class);
    }

    public function tradeSetups(): HasMany
    {
        return $this->hasMany(TradeSetup::class, 'source_candidate_id');
    }
}
