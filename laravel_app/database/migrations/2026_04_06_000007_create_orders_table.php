<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_setup_id')->constrained('trade_setups')->cascadeOnDelete();
            $table->string('broker_order_id', 100)->nullable();
            $table->string('order_type', 30);
            $table->string('side', 10);
            $table->decimal('quantity', 14, 4);
            $table->decimal('limit_price', 12, 4)->nullable();
            $table->decimal('stop_price', 12, 4)->nullable();
            $table->string('status', 30);
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('filled_at')->nullable();
            $table->json('meta_json')->nullable();

            $table->index(['trade_setup_id', 'status'], 'orders_setup_status_idx');
            $table->index('broker_order_id');
            $table->index('placed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
