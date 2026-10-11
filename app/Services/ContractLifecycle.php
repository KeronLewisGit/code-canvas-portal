<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * State machine: draft -> approved -> active -> completed | expired | terminated, with cancelled from draft/approved.
 * Every transition is audited. Amendments snapshot the previous version first.
 */
class ContractLifecycle
{
    public const TRANSITIONS = [
        'approve' => ['from' => ['draft'], 'to' => 'approved'],
        'activate' => ['from' => ['draft', 'approved'], 'to' => 'active'],
        'complete' => ['from' => ['active'], 'to' => 'completed'],
        'expire' => ['from' => ['active'], 'to' => 'expired'],
        'terminate' => ['from' => ['active', 'approved'], 'to' => 'terminated'],
        'cancel' => ['from' => ['draft', 'approved'], 'to' => 'cancelled'],
        'reopen' => ['from' => ['approved', 'cancelled'], 'to' => 'draft'],
    ];

    public static function can(Contract $contract, string $action): bool
    {
        return isset(self::TRANSITIONS[$action]) && in_array($contract->status, self::TRANSITIONS[$action]['from']);
    }

    public static function available(Contract $contract): array
    {
        return array_values(array_filter(array_keys(self::TRANSITIONS), fn ($a) => self::can($contract, $a) && $a !== 'expire'));
    }

    public function transition(Contract $contract, string $action, ?User $by = null, array $extra = []): Contract
    {
        if (! self::can($contract, $action)) {
            throw new InvalidArgumentException("Cannot {$action} a contract that is {$contract->status}.");
        }
        $to = self::TRANSITIONS[$action]['to'];
        $fill = ['status' => $to];
        match ($action) {
            'approve' => $fill += ['approved_at' => now(), 'approved_by' => $by?->id],
            'activate' => $fill += ['activated_at' => $contract->activated_at ?? now()],
            'terminate' => $fill += ['terminated_at' => $extra['terminated_at'] ?? now(), 'termination_reason' => $extra['reason'] ?? null, 'end_date' => $extra['terminated_at'] ?? now()],
            'reopen' => $fill += ['approved_at' => null, 'approved_by' => null],
            default => null,
        };
        $from = $contract->status;
        $contract->forceFill($fill)->save();
        Audit::log($contract, $action, ['status' => ['from' => $from, 'to' => $to]] + ($extra ? ['detail' => $extra] : []));

        return $contract;
    }

    /** Apply new material terms, keeping an immutable copy of the previous version. */
    /**
     * Issue the contract to the client: a draft is approved on the way out, the request is stamped and logged,
     * and the client is emailed to review and sign. It activates when they sign.
     */
    public function sendForSignature(Contract $contract, ?User $by = null, ?string $clientNote = null, bool $notifyAdmins = true): Contract
    {
        DB::transaction(function () use ($contract, $by) {
            if ($contract->status === 'draft') {
                $this->transition($contract, 'approve', $by);
            }
            $contract->forceFill(['sent_for_signature_at' => now()])->save();
            Audit::log($contract, 'sent_for_signature');
        });
        app(Notifier::class)->contractSentForSignature($contract->fresh(['client.users']), $clientNote, $notifyAdmins);

        return $contract;
    }

    /** Unsigned draft, approved or active contracts can be (re)sent for signature. */
    public static function canSendForSignature(Contract $contract): bool
    {
        return in_array($contract->status, ['draft', 'approved', 'active']) && ! $contract->isSigned();
    }

    public function amend(Contract $contract, array $attributes, ?User $by = null, ?string $reason = null): Contract
    {
        $contract->refresh();
        $before = $contract->materialSnapshot();
        $contract->fill($attributes);
        $after = $contract->materialSnapshot();
        $diff = Audit::diff($before, $after);
        $other = array_values(array_diff(array_keys($contract->getDirty()), Contract::MATERIAL_FIELDS));
        if ($diff === [] || $contract->status === 'draft') {
            $contract->save();
            if ($diff !== [] || $other !== []) {
                Audit::log($contract, 'updated', $diff + ($other ? ['fields' => $other] : []));
            }

            return $contract;
        }
        $contract->versions()->create(['version' => $contract->version, 'snapshot' => $before, 'reason' => $reason, 'created_by' => $by?->id, 'created_at' => now()]);
        $contract->version = $contract->version + 1;
        $contract->save();
        Audit::log($contract, 'amended', $diff + ['version' => $contract->version, 'reason' => $reason] + ($other ? ['fields' => $other] : []));

        return $contract;
    }

    /** New draft that starts the day after this one ends, carrying the same terms. */
    public function renew(Contract $contract, ?User $by = null): Contract
    {
        $contract->refresh();
        $start = ($contract->end_date ?? now())->copy()->addDay();
        $end = $contract->end_date ? $start->copy()->addDays($contract->start_date->diffInDays($contract->end_date)) : null;
        $renewal = Contract::create(array_merge($contract->materialSnapshot(), [
            'client_id' => $contract->client_id, 'start_date' => $start, 'end_date' => $end, 'status' => 'draft', 'renewal_of_id' => $contract->id, 'confidential' => $contract->confidential,
        ]));
        Audit::log($contract, 'renewed', ['renewal' => $renewal->reference]);
        Audit::log($renewal, 'created', ['renewal_of' => $contract->reference]);

        return $renewal;
    }

    /** Called by the scheduler: active contracts past their end date become expired (or renew themselves). */
    public function expireDue(): int
    {
        $n = 0;
        $ids = Contract::where('status', 'active')->whereNotNull('end_date')->whereDate('end_date', '<', now()->toDateString())->pluck('id');
        foreach ($ids as $id) {
            try {
                $c = Contract::find($id);
                if (! $c || $c->status !== 'active') {
                    continue;
                }
                if ($c->auto_renew && $c->renewals()->doesntExist()) {
                    $renewal = $this->renew($c);
                    $this->transition($renewal, 'activate');
                }
                $this->transition($c, 'expire');
                $n++;
            } catch (\Throwable $e) {
                report($e); // one bad contract must not stop the rest of the batch
            }
        }

        return $n;
    }

    public function destroyFiles(Contract $contract): void
    {
        foreach ([$contract->file_path, $contract->signature_path] as $path) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
        }
    }
}
