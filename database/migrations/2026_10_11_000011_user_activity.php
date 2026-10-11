<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Who signed in and when (login_events), and each login's latest activity in the portal (on users). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->timestamp('last_seen_at')->nullable();
            $t->string('last_activity', 160)->nullable();
        });
        Schema::create('login_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_events');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['last_seen_at', 'last_activity']));
    }
};
