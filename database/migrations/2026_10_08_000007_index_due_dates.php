<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The scheduler and dashboards filter on these dates every run. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', fn (Blueprint $t) => $t->index(['status', 'end_date']));
        Schema::table('invoices', fn (Blueprint $t) => $t->index(['status', 'due_date']));
    }

    public function down(): void
    {
        Schema::table('contracts', fn (Blueprint $t) => $t->dropIndex(['status', 'end_date']));
        Schema::table('invoices', fn (Blueprint $t) => $t->dropIndex(['status', 'due_date']));
    }
};
