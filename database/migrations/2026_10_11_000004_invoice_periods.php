<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The billing period a recurring contract's invoice covers, so each period is invoiced exactly once. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->date('period_start')->nullable();
            $t->index(['contract_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropIndex(['contract_id', 'period_start']);
            $t->dropColumn('period_start');
        });
    }
};
