<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('symbol_id')->nullable()->after('trade_setup_id')->constrained('symbols')->nullOnDelete();
            $table->index(['symbol_id', 'status'], 'orders_symbol_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_symbol_status_idx');
            $table->dropConstrainedForeignId('symbol_id');
        });
    }
};
