<?php

namespace App\Services;

use App\Http\Requests\ProductInformationRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductInformationService
{
    public const EDITABLE = ['description', 'brand', 'unit_name', 'storage_notes'];

    public const RECORDED = ['ingredients' => 'Ingredients', 'allergens' => 'Allergens', 'net_weight' => 'Net weight', 'nutrition_basis' => 'Measurement basis', 'serving_size' => 'Serving size', 'servings_per_pack' => 'Servings per pack', 'nutrition_source' => 'Nutrition source', 'nutrition_source_url' => 'Source reference'];

    public static function context(int $id): array
    {
        $audit = DB::connection('pos')->table('product_detail_audit')->where('product_id', $id)->where('action', 'ai_extract')->orderByDesc('audit_id')->first(['audit_id', 'after_json']);
        $after = $audit ? json_decode($audit->after_json, true) : [];

        return ['ai_audit_id' => (int) ($audit->audit_id ?? 0), 'web_fields' => ! is_array($after) || ! empty($after['web_fields'])];
    }

    public static function revision(int $id, ?object $row, array $context): string
    {
        return hash_hmac('sha256', json_encode([$id, $row, $context], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function invalid(string $message, string $field = 'information'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function snapshot(?object $row): ?array
    {
        if (! $row) {
            return null;
        }

        $snapshot = array_intersect_key((array) $row, array_flip([...self::EDITABLE, ...array_keys(self::RECORDED), ...array_keys(PublicProductFacts::NUTRIENTS), 'review_status', 'rejected_reason']));
        $snapshot['nutrition_source_url'] = PublicProductFacts::safeSourceUrl($snapshot['nutrition_source_url'] ?? null);

        return $snapshot;
    }

    public function change(int $actorId, int $id, array $data): string
    {
        $data = Validator::make($data, ProductInformationRequest::constraints())->validate();
        $db = DB::connection('pos');

        return $db->transaction(function () use ($db, $actorId, $id, $data): string {
            $actor = $db->table('users')->where('id', $actorId)->whereIn('role', ['admin', 'inventory_staff'])->where('is_active', 1)->where('is_locked', 0)->where('force_logout', 0)->lockForUpdate()->first();
            abort_unless($actor, 403);
            foreach (['locked_until', 'two_factor_locked_until'] as $field) {
                abort_if(! empty($actor->$field) && now()->lt($actor->$field), 403);
            }
            $action = $data['action'];
            abort_if(in_array($action, ['verify', 'reject'], true) && $actor->role !== 'admin', 403);
            $product = $db->table('products')->where('id', $id)->lockForUpdate()->first(['id', 'is_active']);
            abort_unless($product, 404);
            if (! $product->is_active) {
                $this->invalid('Reactivate the product before changing its information.');
            }
            $current = $db->table('product_details')->where('product_id', $id)->lockForUpdate()->first();
            $context = self::context($id);
            if (! hash_equals(self::revision($id, $current, $context), $data['revision'])) {
                $this->invalid('This information changed after you opened it. Review the latest version before trying again.', 'revision');
            }
            $state = $current->review_status ?? 'draft';
            $values = ['updated_by' => $actorId, 'verified_by' => null, 'verified_at' => null, 'rejected_reason' => null];
            if (in_array($action, ['save', 'submit', 'resubmit'], true)) {
                if (($action === 'submit' && ! in_array($state, ['draft', 'ai_filled', 'needs_review'], true)) || ($action === 'resubmit' && $state !== 'rejected')) {
                    $this->invalid('This review action is no longer available. Reload the current information.');
                }
                foreach (self::EDITABLE as $field) {
                    $values[$field] = $data[$field] === '' ? null : $data[$field];
                }
                $next = $action !== 'save' || in_array($state, ['verified', 'rejected'], true) ? 'needs_review' : $state;
            } else {
                if ($state !== 'needs_review') {
                    $this->invalid('Only information awaiting review can be verified or rejected.');
                }
                if ($action === 'verify' && (($current->nutrition_source ?? null) === 'web' || $context['web_fields'])) {
                    $this->invalid('Web-sourced fields require the label-information review workflow before verification.');
                }
                $next = $action === 'verify' ? 'verified' : 'rejected';
                if ($next === 'verified') {
                    $values['verified_by'] = $actorId;
                    $values['verified_at'] = now();
                } else {
                    $values['rejected_reason'] = $data['rejected_reason'];
                }
            }
            $values['review_status'] = $next;
            $db->table('product_details')->updateOrInsert(['product_id' => $id], $values);
            $after = $db->table('product_details')->where('product_id', $id)->first();
            $db->table('product_detail_audit')->insert(['product_id' => $id, 'changed_by' => $actorId, 'action' => $action, 'source' => 'manual', 'review_status' => $next, 'before_json' => $current ? json_encode($this->snapshot($current), JSON_THROW_ON_ERROR) : null, 'after_json' => json_encode($this->snapshot($after), JSON_THROW_ON_ERROR)]);
            DomainAudit::record('product_details', $id, $action, array_keys($values), $actorId);

            return $next;
        }, 3);
    }
}
