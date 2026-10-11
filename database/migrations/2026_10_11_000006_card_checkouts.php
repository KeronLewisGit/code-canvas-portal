<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One row per card checkout started with WiPay, so the result can be matched, verified and recorded exactly once. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_checkouts', function (Blueprint $t) {
            $t->id();
            $t->string('order_id', 64)->unique();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('amount', 12, 2);
            $t->string('currency', 3);
            $t->string('status', 20)->default('started'); // started | succeeded | failed
            $t->string('transaction_id', 100)->nullable();
            $t->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $t->string('message', 255)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_checkouts');
    }
};
