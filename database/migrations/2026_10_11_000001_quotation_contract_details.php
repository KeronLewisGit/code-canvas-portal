<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the contract should look like if the client accepts, so acceptance can generate it in full and,
 * when auto_send_contract is on, send it straight out for signature. Existing quotations keep the old
 * behaviour: the contract is drafted for an admin to review.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $t) {
            $t->string('contract_type', 40)->nullable();
            $t->string('billing_cycle', 20)->nullable();
            $t->unsignedSmallInteger('contract_term_months')->nullable();
            $t->boolean('auto_send_contract')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('quotations', fn (Blueprint $t) => $t->dropColumn(['contract_type', 'billing_cycle', 'contract_term_months', 'auto_send_contract']));
    }
};
