<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromptLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'run_id',
        'symbol_id',
        'prompt_type',
        'input_json',
        'output_json',
        'model_name',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'input_json' => 'array',
            'output_json' => 'array',
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
}
