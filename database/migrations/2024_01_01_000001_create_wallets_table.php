<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->morphs('holder');
            $table->string('name')->default('default');
            $table->string('currency', 3);
            $table->unsignedBigInteger('balance')->default(0);
            $table->unsignedBigInteger('min_balance')->default(0);
            $table->unsignedBigInteger('max_balance')->nullable();
            $table->string('status', 20)->default('active');
            $table->string('frozen_reason')->nullable();
            $table->timestamp('frozen_at')->nullable();
            $table->unsignedBigInteger('frozen_by')->nullable();
            $table->boolean('low_balance_alert')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['holder_type', 'holder_id', 'name', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
