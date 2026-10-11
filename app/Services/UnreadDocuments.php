<?php

namespace App\Services;

use App\Http\Controllers\Admin\ClientPreviewController;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "New" documents for a client login: quotations, contracts and invoices they can see but have not opened since
 * they were issued. Opening one marks it read; re-issuing it (e.g. a contract sent for signature again after an
 * amendment) makes it new again. An admin previewing the portal never marks anything read.
 */
class UnreadDocuments
{
    /** @var array<int, array<string, list<int>>> per-user memo for one request */
    private array $memo = [];

    public function markRead(User $user, Quotation|Contract|Invoice $doc): void
    {
        if (! $user->isClient() || ClientPreviewController::active()) {
            return;
        }
        DB::table('document_reads')->upsert(
            [['user_id' => $user->id, 'readable_type' => $doc->getMorphClass(), 'readable_id' => $doc->getKey(), 'read_at' => now()]],
            ['user_id', 'readable_type', 'readable_id'],
            ['read_at'],
        );
        unset($this->memo[$user->id]);
    }

    /** @return array{quotations: int, contracts: int, invoices: int} */
    public function counts(User $user): array
    {
        return array_map('count', $this->unreadIds($user));
    }

    public function isUnread(User $user, Model $doc): bool
    {
        $key = match (true) {
            $doc instanceof Quotation => 'quotations',
            $doc instanceof Contract => 'contracts',
            default => 'invoices',
        };

        return in_array($doc->getKey(), $this->unreadIds($user)[$key], true);
    }

    /** @return array{quotations: list<int>, contracts: list<int>, invoices: list<int>} */
    public function unreadIds(User $user): array
    {
        if (! $user->isClient()) {
            return ['quotations' => [], 'contracts' => [], 'invoices' => []];
        }

        return $this->memo[$user->id] ??= $this->compute($user);
    }

    /** @return array{quotations: list<int>, contracts: list<int>, invoices: list<int>} */
    private function compute(User $user): array
    {
        $client = $user->client;
        $reads = DB::table('document_reads')->where('user_id', $user->id)->get()
            ->mapWithKeys(fn ($r) => [$r->readable_type.':'.$r->readable_id => Carbon::parse($r->read_at)]);
        $unread = function ($docs, callable $issuedAt) use ($reads): array {
            return $docs->filter(function (Model $doc) use ($reads, $issuedAt) {
                $read = $reads[$doc->getMorphClass().':'.$doc->getKey()] ?? null;
                $issued = $issuedAt($doc);

                return ! $read || ($issued && $issued->gt($read));
            })->map->getKey()->values()->all();
        };

        return [
            'quotations' => $unread($client->quotations()->where('status', '!=', 'draft')->get(), fn (Quotation $q) => $q->sent_at),
            'contracts' => $unread($client->contracts()->visibleToClient()->get(), fn (Contract $c) => $c->sent_for_signature_at ?? $c->activated_at),
            'invoices' => $unread($client->invoices()->where('status', '!=', 'draft')->get(), fn (Invoice $i) => $i->sent_at),
        ];
    }
}
