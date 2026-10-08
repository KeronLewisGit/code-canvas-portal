<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // one_time | weekly | monthly | quarterly | half_yearly | yearly. Amount is per cycle when recurring.
            $table->string('billing_cycle', 12)->default('one_time')->after('amount')->index();
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('billing_cycle');
        });
    }
};
