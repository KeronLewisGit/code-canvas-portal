<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Set your password" links in welcome emails. Kept apart from password_reset_tokens so invite links can last a
 * week while ordinary reset links still expire after an hour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invite_tokens', function (Blueprint $t) {
            $t->string('email')->primary();
            $t->string('token');
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invite_tokens');
    }
};
