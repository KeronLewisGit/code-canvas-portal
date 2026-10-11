<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A client is a company or an individual. `company` stays the client's display name everywhere: the business
 * name for a company, the person's full name for an individual (who is also their own contact).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', fn (Blueprint $t) => $t->string('type', 20)->default('company')->after('id'));
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('type'));
    }
};
