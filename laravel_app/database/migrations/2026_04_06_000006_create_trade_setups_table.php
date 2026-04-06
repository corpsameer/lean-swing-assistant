<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('trade_setups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('symbol_id')->constrained('symbols')->cascadeOnDelete();
            $table->foreignId('source_candidate_id')->constrained('watchlist_candidates')->cascadeOnDelete();
            $table->string('status', 30);
            $table->decimal('entry_price', 12, 4);
            $table->decimal('stop_price', 12, 4);
            $table->decimal('target1_price', 12, 4)->nullable();
            $table->decimal('target2_price', 12, 4)->nullable();
            $table->decimal('risk_percent', 6, 3)->nullable();
            $table->json('sizing_json')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['symbol_id', 'status'], 'trade_setups_symbol_status_idx');
            $table->index('source_candidate_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_setups');
    }
};
