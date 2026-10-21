<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Recurring contracts can be invoiced at the end of each billing period instead of at its start. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', fn (Blueprint $t) => $t->boolean('bill_in_arrears')->default(false)->after('billing_cycle'));
    }

    public function down(): void
    {
        Schema::table('contracts', fn (Blueprint $t) => $t->dropColumn('bill_in_arrears'));
    }
};
