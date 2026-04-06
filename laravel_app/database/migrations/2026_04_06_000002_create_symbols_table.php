<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('symbols', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 20)->unique();
            $table->string('company_name')->nullable();
            $table->string('exchange', 50)->nullable();
            $table->string('sector', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'symbol']);
            $table->index('exchange');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('symbols');
    }
};
