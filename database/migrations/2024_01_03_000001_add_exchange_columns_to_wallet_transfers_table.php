<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transfers', function (Blueprint $table) {
            $table->string('exchange_rate')->nullable()->after('fee');
            $table->unsignedBigInteger('converted_amount')->nullable()->after('exchange_rate');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transfers', function (Blueprint $table) {
            $table->dropColumn(['exchange_rate', 'converted_amount']);
        });
    }
};
