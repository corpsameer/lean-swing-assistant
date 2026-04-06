<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_type', 50);
            $table->string('status', 30);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->json('meta_json')->nullable();

            $table->index(['run_type', 'status']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runs');
    }
};
