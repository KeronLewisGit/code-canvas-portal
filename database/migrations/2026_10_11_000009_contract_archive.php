<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Archived contracts drop out of the admin's working lists (and, if never signed, out of the client's portal). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', fn (Blueprint $t) => $t->timestamp('archived_at')->nullable()->index());
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $t) {
            $t->dropIndex(['archived_at']);
            $t->dropColumn('archived_at');
        });
    }
};
