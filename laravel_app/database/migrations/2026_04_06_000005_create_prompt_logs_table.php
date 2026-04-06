<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('prompt_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('runs')->cascadeOnDelete();
            $table->foreignId('symbol_id')->nullable()->constrained('symbols')->nullOnDelete();
            $table->string('prompt_type', 50);
            $table->json('input_json');
            $table->json('output_json');
            $table->string('model_name', 100);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['run_id', 'prompt_type'], 'prompt_logs_run_type_idx');
            $table->index('symbol_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prompt_logs');
    }
};
