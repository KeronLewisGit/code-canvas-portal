<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * contracts.deposit_percent overrides the Settings deposit for one contract (null = use the setting, 0 = no deposit).
 * invoices.kind labels how an invoice relates to its contract: standard, deposit, balance or cycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', fn (Blueprint $t) => $t->decimal('deposit_percent', 5, 2)->nullable());
        Schema::table('invoices', fn (Blueprint $t) => $t->string('kind', 20)->default('standard'));
    }

    public function down(): void
    {
        Schema::table('contracts', fn (Blueprint $t) => $t->dropColumn('deposit_percent'));
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('kind'));
    }
};
