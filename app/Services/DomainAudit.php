<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DomainAudit
{
    public static function record(string $entity, int $id, string $action, array $fields, ?int $actorId = null, string $domain = 'pos'): void
    {
        DB::connection('pos')->table('application_audit_events')->insert(['actor_domain' => $domain, 'actor_id' => $actorId, 'entity' => $entity, 'entity_id' => $id, 'action' => $action, 'changed_fields' => json_encode(array_values($fields), JSON_THROW_ON_ERROR), 'occurred_at' => now()]);
    }
}
