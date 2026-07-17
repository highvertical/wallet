<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_holds', function (Blueprint $table) {
            $table->string('reference')->nullable()->unique()->after('reason');
            $table->index(['wallet_id', 'status', 'expires_at'], 'wallet_holds_wallet_id_status_expires_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_holds', function (Blueprint $table) {
            $table->dropIndex('wallet_holds_wallet_id_status_expires_at_index');
            $table->dropUnique(['reference']);
            $table->dropColumn('reference');
        });
    }
};
