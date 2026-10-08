<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['invoices', 'quotations', 'contracts'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->timestamp('reminded_at')->nullable());
        }
    }

    public function down(): void
    {
        foreach (['invoices', 'quotations', 'contracts'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('reminded_at'));
        }
    }
};
