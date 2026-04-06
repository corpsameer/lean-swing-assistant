<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Symbol extends Model
{
    use HasFactory;

    protected $fillable = [
        'symbol',
        'company_name',
        'exchange',
        'sector',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function marketSnapshots(): HasMany
    {
        return $this->hasMany(MarketSnapshot::class);
    }

    public function watchlistCandidates(): HasMany
    {
        return $this->hasMany(WatchlistCandidate::class);
    }

    public function promptLogs(): HasMany
    {
        return $this->hasMany(PromptLog::class);
    }

    public function tradeSetups(): HasMany
    {
        return $this->hasMany(TradeSetup::class);
    }
}
