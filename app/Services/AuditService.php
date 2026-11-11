<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditService
{
    public static function log(Request $request, string $event, ?object $auditable = null, array $oldValues = [], array $newValues = []): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()?->id,
            'event' => $event,
            'auditable_type' => $auditable ? get_class($auditable) : null,
            'auditable_id' => $auditable->id ?? null,
            'old_values' => $oldValues ? json_encode($oldValues, JSON_UNESCAPED_SLASHES) : null,
            'new_values' => $newValues ? json_encode($newValues, JSON_UNESCAPED_SLASHES) : null,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
            'created_at' => now(),
        ]);
    }
}
