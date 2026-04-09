<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('watchlist_candidates', function (Blueprint $table): void {
            $table->decimal('trigger_band_low', 12, 4)->nullable()->after('trigger_price');
            $table->decimal('trigger_band_high', 12, 4)->nullable()->after('trigger_band_low');
        });
    }

    public function down(): void
    {
        Schema::table('watchlist_candidates', function (Blueprint $table): void {
            $table->dropColumn(['trigger_band_low', 'trigger_band_high']);
        });
    }
};
