<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('watchlist_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('runs')->cascadeOnDelete();
            $table->foreignId('symbol_id')->constrained('symbols')->cascadeOnDelete();
            $table->string('stage', 30);
            $table->string('status', 30);
            $table->string('setup_type', 50)->nullable();
            $table->decimal('score_total', 8, 3)->nullable();
            $table->decimal('breakout_low_price', 12, 4)->nullable();
            $table->decimal('breakout_high_price', 12, 4)->nullable();
            $table->decimal('support_low_price', 12, 4)->nullable();
            $table->decimal('support_high_price', 12, 4)->nullable();
            $table->decimal('trigger_price', 12, 4)->nullable();
            $table->text('reasoning_text')->nullable();
            $table->json('prompt_output_json')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['run_id', 'stage', 'status'], 'watchlist_run_stage_status_idx');
            $table->index(['symbol_id', 'stage'], 'watchlist_symbol_stage_idx');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watchlist_candidates');
    }
};
