<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Onboarding state per login: when they last signed in, whether they have seen the first-login welcome,
 * and whether they still use the password we issued (null) or have chosen their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->timestamp('last_login_at')->nullable();
            $t->timestamp('welcomed_at')->nullable();
            $t->timestamp('password_changed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['last_login_at', 'welcomed_at', 'password_changed_at']));
    }
};
