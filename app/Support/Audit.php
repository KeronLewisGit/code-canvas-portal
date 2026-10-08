<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

/** Append-only audit trail for material changes and sensitive reads. */
class Audit
{
    public static function log(Model $subject, string $action, ?array $changes = null): ActivityLog
    {
        return ActivityLog::create([
            'user_id' => auth()->id(),
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'action' => $action,
            'changes' => $changes ?: null,
            'ip' => request()?->ip(),
            'created_at' => now(),
        ]);
    }

    /** Only the keys whose value actually changed, as before/after pairs. */
    public static function diff(array $before, array $after): array
    {
        $out = [];
        foreach ($after as $key => $value) {
            $old = $before[$key] ?? null;
            if ((string) $old !== (string) $value) {
                $out[$key] = ['from' => $old, 'to' => $value];
            }
        }

        return $out;
    }
}
