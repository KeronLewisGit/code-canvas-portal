<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which quotations, contracts and invoices each client login has opened, for the "new" counts in the portal menu.
 * Existing documents that are already settled start as read, so clients do not see a pile of old items as new;
 * open ones (to answer, sign or pay) start unread.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_reads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('readable_type');
            $t->unsignedBigInteger('readable_id');
            $t->timestamp('read_at');
            $t->unique(['user_id', 'readable_type', 'readable_id']);
        });

        $settled = [
            ['quotations', 'App\\Models\\Quotation', ['accepted', 'declined', 'expired']],
            ['contracts', 'App\\Models\\Contract', ['completed', 'expired', 'terminated', 'cancelled']],
            ['invoices', 'App\\Models\\Invoice', ['paid', 'cancelled']],
        ];
        $now = now();
        foreach (DB::table('users')->where('role', 'client')->whereNotNull('client_id')->get(['id', 'client_id']) as $user) {
            foreach ($settled as [$table, $type, $statuses]) {
                $rows = DB::table($table)->where('client_id', $user->client_id)->whereIn('status', $statuses)->pluck('id')
                    ->map(fn ($id) => ['user_id' => $user->id, 'readable_type' => $type, 'readable_id' => $id, 'read_at' => $now])->all();
                foreach (array_chunk($rows, 200) as $chunk) {
                    DB::table('document_reads')->insertOrIgnore($chunk);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_reads');
    }
};
