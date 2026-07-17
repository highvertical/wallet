<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transfers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('from_wallet_id')->constrained('wallets');
            $table->foreignId('to_wallet_id')->constrained('wallets');
            $table->foreignId('debit_transaction_id')->constrained('wallet_transactions');
            $table->foreignId('credit_transaction_id')->constrained('wallet_transactions');
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('fee')->default(0);
            $table->string('status', 20)->default('completed');
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transfers');
    }
};
