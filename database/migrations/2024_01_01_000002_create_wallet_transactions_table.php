<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10);
            $table->string('category', 20);
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('balance_before');
            $table->unsignedBigInteger('balance_after');
            $table->string('reference')->unique();
            $table->string('status', 20)->default('completed');
            $table->nullableMorphs('subject');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('initiated_by')->nullable();
            $table->ipAddress('initiated_ip')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['wallet_id', 'created_at']);
            $table->index(['wallet_id', 'category', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
