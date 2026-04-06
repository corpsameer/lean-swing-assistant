<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Run extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'run_type',
        'status',
        'started_at',
        'completed_at',
        'meta_json',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'meta_json' => 'array',
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
}
