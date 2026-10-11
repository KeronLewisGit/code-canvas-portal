<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Receipt numbers (RCT-2026-001) for confirmed payments. Payments already verified get numbers too, in the order
 * they were confirmed, so every past payment has a receipt the client can view (no emails are sent for these).
 * Credit notes and refunds are not payments received, so they never get a receipt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->string('receipt_number', 20)->nullable()->unique());

        $next = [];
        $rows = DB::table('payments')->where('status', 'verified')->whereNotIn('method', ['credit_note', 'refund'])
            ->orderByRaw('coalesce(verified_at, paid_on)')->orderBy('id')->get(['id', 'verified_at', 'paid_on']);
        foreach ($rows as $row) {
            $year = Carbon::parse($row->verified_at ?? $row->paid_on)->format('Y');
            $next[$year] = ($next[$year] ?? 0) + 1;
            DB::table('payments')->where('id', $row->id)->update(['receipt_number' => 'RCT-'.$year.'-'.str_pad((string) $next[$year], 3, '0', STR_PAD_LEFT)]);
        }
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->dropUnique(['receipt_number']);
            $t->dropColumn('receipt_number');
        });
    }
};
