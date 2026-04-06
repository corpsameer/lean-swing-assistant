<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('market_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('runs')->cascadeOnDelete();
            $table->foreignId('symbol_id')->constrained('symbols')->cascadeOnDelete();
            $table->string('snapshot_type', 50);
            $table->json('payload_json');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['run_id', 'symbol_id', 'snapshot_type'], 'market_snapshots_lookup_idx');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_snapshots');
    }
};
