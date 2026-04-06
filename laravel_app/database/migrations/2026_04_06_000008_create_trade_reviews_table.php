<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('trade_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_setup_id')->constrained('trade_setups')->cascadeOnDelete();
            $table->string('outcome_status', 30);
            $table->decimal('pnl_amount', 12, 2)->nullable();
            $table->decimal('pnl_percent', 8, 3)->nullable();
            $table->text('review_text')->nullable();
            $table->json('lessons_json')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique('trade_setup_id');
            $table->index('outcome_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_reviews');
    }
};
