<?php

namespace Tests\Feature;

use App\Models\PosUser;
use App\Services\ProductInformationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ProductInformationTest extends IsolatedFeatureTestCase
{
    private function staff(string $role = 'inventory_staff'): PosUser
    {
        return PosUser::where('username', 'demo_'.$role)->firstOrFail();
    }

    private function url(int $id): string
    {
        return '/manage/products/'.$id.'/information';
    }

    private function revision(int $id): string
    {
        return ProductInformationService::revision($id, DB::connection('pos')->table('product_details')->where('product_id', $id)->first(), ProductInformationService::context($id));
    }

    private function data(int $id, string $action = 'save', string $description = 'Synthetic native description'): array
    {
        $data = ['action' => $action, 'revision' => $this->revision($id)];
        if (in_array($action, ['save', 'submit', 'resubmit'], true)) {
            $data += ['description' => $description, 'brand' => 'Synthetic brand', 'unit_name' => 'Pack', 'storage_notes' => 'Synthetic storage'];
        }

        return $data;
    }

    private function facts(string $state = 'draft', array $extra = []): int
    {
        $id = $this->product();
        DB::connection('pos')->table('product_details')->insert(array_replace(['product_id' => $id, 'review_status' => $state, 'description' => 'Synthetic original description', 'ingredients' => 'Synthetic ingredients', 'allergens' => 'Synthetic allergen', 'nutrition_basis' => 'per_100g', 'nutrition_source' => 'manual', 'sugar_g' => '0.00', 'calories' => null], $extra));

        return $id;
    }

    public function test_draft_creation_is_audited_and_stays_out_of_public_projection(): void
    {
        $id = $this->product();
        $this->staffSession($this->staff())->get($this->url($id))->assertOk()->assertSee('Recent review history');
        $this->postJson($this->url($id), $this->data($id))->assertOk()->assertJsonPath('review_status', 'draft');
        $this->assertDatabaseHas('product_details', ['product_id' => $id, 'description' => 'Synthetic native description', 'review_status' => 'draft', 'updated_by' => $this->staff()->id, 'verified_by' => null], 'pos');
        $this->assertDatabaseHas('product_detail_audit', ['product_id' => $id, 'action' => 'save', 'source' => 'manual', 'review_status' => 'draft'], 'pos');
        $this->get('/catalog/'.$id)->assertOk()->assertDontSee('Synthetic native description');
    }

    public function test_inventory_submits_and_only_admin_verifies_saved_information_for_public_display(): void
    {
        $id = $this->facts();
        Http::preventStrayRequests();
        $this->staffSession($this->staff())->postJson($this->url($id), $this->data($id, 'submit'))->assertOk()->assertJsonPath('review_status', 'needs_review');
        $this->postJson($this->url($id), $this->data($id, 'verify'))->assertForbidden();
        $this->get('/catalog/'.$id)->assertDontSee('Synthetic native description');
        $this->staffSession($this->staff('admin'))->postJson($this->url($id), $this->data($id, 'verify'))->assertOk()->assertJsonPath('review_status', 'verified');
        $this->assertDatabaseHas('product_details', ['product_id' => $id, 'verified_by' => $this->staff('admin')->id, 'review_status' => 'verified'], 'pos');
        $this->get('/catalog/'.$id)->assertOk()->assertSee('Synthetic native description')->assertSee('Synthetic ingredients')->assertSee('<td>Sugar (g)</td><td>0</td>', false)->assertDontSee('<td>Calories</td>', false);
        Http::assertNothingSent();
    }

    public function test_editing_verified_information_unpublishes_it_and_clears_verifier_metadata(): void
    {
        $id = $this->facts('verified', ['verified_by' => $this->staff('admin')->id, 'verified_at' => now()]);
        $this->staffSession($this->staff())->postJson($this->url($id), $this->data($id))->assertOk()->assertJsonPath('review_status', 'needs_review');
        $this->assertDatabaseHas('product_details', ['product_id' => $id, 'verified_by' => null, 'verified_at' => null, 'ingredients' => 'Synthetic ingredients', 'sugar_g' => '0.00'], 'pos');
        $this->get('/catalog/'.$id)->assertDontSee('Synthetic original description')->assertDontSee('Synthetic native description')->assertDontSee('Synthetic ingredients');
    }

    public function test_rejection_requires_reason_and_rejected_information_can_be_resubmitted(): void
    {
        $id = $this->facts('needs_review');
        $this->staffSession($this->staff('admin'));
        $this->postJson($this->url($id), $this->data($id, 'reject'))->assertUnprocessable();
        $this->postJson($this->url($id), $this->data($id, 'reject') + ['rejected_reason' => 'Synthetic label mismatch'])->assertOk()->assertJsonPath('review_status', 'rejected');
        $this->get($this->url($id))->assertSee('Synthetic label mismatch');
        $this->get('/catalog/'.$id)->assertDontSee('Synthetic label mismatch');
        $this->staffSession($this->staff())->postJson($this->url($id), $this->data($id, 'resubmit'))->assertOk()->assertJsonPath('review_status', 'needs_review');
        $this->assertDatabaseHas('product_details', ['product_id' => $id, 'rejected_reason' => null], 'pos');
    }

    public function test_cashier_and_finance_cannot_open_or_mutate_information(): void
    {
        $id = $this->facts('needs_review');
        foreach (['cashier', 'finance'] as $role) {
            $this->staffSession($this->staff($role))->get($this->url($id))->assertForbidden();
            $this->postJson($this->url($id), $this->data($id))->assertForbidden();
        }
        $this->staffSession($this->staff())->postJson($this->url($id), $this->data($id, 'reject') + ['rejected_reason' => 'Synthetic denial'])->assertForbidden();
        $this->assertDatabaseHas('product_details', ['product_id' => $id, 'review_status' => 'needs_review'], 'pos');
    }

    public function test_stale_editor_and_stale_nutrient_review_cannot_overwrite_or_publish_newer_data(): void
    {
        $id = $this->facts('needs_review');
        $old = $this->data($id);
        $decision = $this->data($id, 'verify');
        $this->staffSession($this->staff())->postJson($this->url($id), $this->data($id, 'save', 'Synthetic latest description'))->assertOk();
        $this->postJson($this->url($id), $old)->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->staffSession($this->staff('admin'))->postJson($this->url($id), $decision)->assertUnprocessable()->assertJsonValidationErrors('revision');
        $decision = $this->data($id, 'verify');
        DB::connection('pos')->table('product_details')->where('product_id', $id)->update(['sugar_g' => '5.00']);
        $this->postJson($this->url($id), $decision)->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->assertDatabaseHas('product_details', ['product_id' => $id, 'review_status' => 'needs_review', 'description' => 'Synthetic latest description', 'sugar_g' => '5.00'], 'pos');
    }

    public function test_html_revision_conflict_discards_stale_flashes_before_rendering_current_fields(): void
    {
        $id = $this->facts();
        $stale = $this->data($id, 'save', 'Synthetic stale attempted overwrite');
        DB::connection('pos')->table('product_details')->where('product_id', $id)->update(['description' => 'Synthetic concurrent current value']);
        $this->staffSession($this->staff())->from($this->url($id))->post($this->url($id), $stale)->assertRedirect($this->url($id))->assertSessionHasErrors('revision')->assertSessionMissing('_old_input');
        $this->get($this->url($id))->assertOk()->assertSee('Synthetic concurrent current value')->assertDontSee('Synthetic stale attempted overwrite');
    }

    public function test_invalid_states_and_posted_actor_status_or_review_content_are_rejected(): void
    {
        $id = $this->facts();
        $this->staffSession($this->staff('admin'));
        foreach (['verify', 'reject', 'resubmit'] as $action) {
            $data = $this->data($id, $action);
            if ($action === 'reject') {
                $data['rejected_reason'] = 'Synthetic rejection';
            }
            $this->postJson($this->url($id), $data)->assertUnprocessable();
        }
        $this->postJson($this->url($id), $this->data($id) + ['review_status' => 'verified', 'verified_by' => 1])->assertUnprocessable();
        $this->postJson($this->url($id), $this->data($id) + ['calories' => '999.00'])->assertUnprocessable();
        $this->postJson($this->url($id), array_replace($this->data($id), ['description' => str_repeat('A', 5001)]))->assertUnprocessable();
        DB::connection('pos')->table('product_details')->where('product_id', $id)->update(['review_status' => 'needs_review']);
        $this->postJson($this->url($id), $this->data($id, 'verify') + ['description' => 'Synthetic unsaved content'])->assertUnprocessable();
        $this->assertDatabaseHas('product_details', ['product_id' => $id, 'description' => 'Synthetic original description', 'review_status' => 'needs_review'], 'pos');
    }

    public function test_web_source_or_latest_ai_web_fields_cannot_be_published_through_manual_editor(): void
    {
        foreach ([['nutrition_source' => 'web'], [], []] as $index => $extra) {
            $id = $this->facts('needs_review', $extra);
            if ($index > 0) {
                DB::connection('pos')->table('product_detail_audit')->insert(['product_id' => $id, 'action' => 'ai_extract', 'source' => 'ai', 'after_json' => $index === 1 ? json_encode(['web_fields' => ['ingredients']]) : json_encode('Synthetic invalid provenance')]);
            }
            $this->staffSession($this->staff('admin'))->get($this->url($id))->assertOk()->assertSee('Web-sourced fields require')->assertDontSee('Verify saved information');
            $this->postJson($this->url($id), $this->data($id, 'verify'))->assertUnprocessable();
            $this->assertDatabaseHas('product_details', ['product_id' => $id, 'review_status' => 'needs_review'], 'pos');
        }
    }

    public function test_new_ai_provenance_invalidates_a_previously_open_review_revision(): void
    {
        $id = $this->facts('needs_review');
        $decision = $this->data($id, 'verify');
        DB::connection('pos')->table('product_detail_audit')->insert(['product_id' => $id, 'action' => 'ai_extract', 'source' => 'ai', 'after_json' => json_encode(['web_fields' => ['allergens']])]);
        $this->staffSession($this->staff('admin'))->postJson($this->url($id), $decision)->assertUnprocessable()->assertJsonValidationErrors('revision');
    }

    public function test_label_derived_ai_filled_record_can_be_submitted_for_admin_review_without_an_external_call(): void
    {
        $id = $this->facts('ai_filled', ['nutrition_source' => 'label_photo']);
        Http::preventStrayRequests();
        $this->staffSession($this->staff())->postJson($this->url($id), $this->data($id, 'submit'))->assertOk()->assertJsonPath('review_status', 'needs_review');
        $this->staffSession($this->staff('admin'))->postJson($this->url($id), $this->data($id, 'verify'))->assertOk();
        Http::assertNothingSent();
    }

    public function test_product_audit_failure_rolls_back_edit_creation_and_publication_metadata(): void
    {
        $id = $this->facts('verified', ['verified_by' => $this->staff('admin')->id, 'verified_at' => now()]);
        $data = $this->data($id);
        $before = DB::connection('pos')->table('application_audit_events')->count();
        DB::listen(function ($query): void {
            if (str_starts_with($query->sql, 'insert into `product_detail_audit`')) {
                throw new \RuntimeException('Synthetic information audit failure');
            }
        });
        try {
            app(ProductInformationService::class)->change($this->staff()->id, $id, $data);
            $this->fail('Unaudited information edit committed.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic information audit failure', $error->getMessage());
        }
        $this->assertDatabaseHas('product_details', ['product_id' => $id, 'review_status' => 'verified', 'description' => 'Synthetic original description', 'verified_by' => $this->staff('admin')->id], 'pos');
        $this->assertDatabaseCount('application_audit_events', $before, 'pos');
        $new = $this->product();
        try {
            app(ProductInformationService::class)->change($this->staff()->id, $new, $this->data($new));
            $this->fail('Unaudited draft was created.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic information audit failure', $error->getMessage());
        }
        $this->assertDatabaseMissing('product_details', ['product_id' => $new], 'pos');
    }

    public function test_information_escapes_saved_text_and_source_references_without_exposing_raw_audit_json(): void
    {
        $id = $this->facts('draft', ['description' => '<script>Synthetic edit text</script>', 'nutrition_source_url' => 'javascript:synthetic_source()']);
        DB::connection('pos')->table('product_detail_audit')->insert(['product_id' => $id, 'action' => 'save', 'source' => 'manual', 'after_json' => json_encode(['private_unrendered_marker' => 'Synthetic audit payload hidden'])]);
        $this->staffSession($this->staff())->get($this->url($id))->assertOk()->assertSee('&lt;script&gt;Synthetic edit text&lt;/script&gt;', false)->assertDontSee('<script>Synthetic edit text</script>', false)->assertDontSee('href="javascript:', false)->assertDontSee('Synthetic audit payload hidden');
    }

    public function test_credential_bearing_source_reference_is_suppressed_in_editor_and_new_audit_snapshots(): void
    {
        $id = $this->facts('draft', ['nutrition_source_url' => 'https://synthetic-user:synthetic-secret@example.invalid/label']);
        $this->staffSession($this->staff())->get($this->url($id))->assertOk()->assertDontSee('synthetic-secret')->assertDontSee('synthetic-user:');
        $this->postJson($this->url($id), $this->data($id))->assertOk();
        $audit = DB::connection('pos')->table('product_detail_audit')->where('product_id', $id)->orderByDesc('audit_id')->first();
        $this->assertNull(json_decode($audit->after_json, true)['nutrition_source_url']);
        $this->assertNull(json_decode($audit->before_json, true)['nutrition_source_url']);
        $this->assertStringNotContainsString('synthetic-secret', $audit->after_json.$audit->before_json);
    }

    public function test_revision_from_another_product_cannot_authorize_an_edit(): void
    {
        $first = $this->facts();
        $second = $this->facts();
        $data = $this->data($second);
        $data['revision'] = $this->revision($first);
        $this->staffSession($this->staff())->postJson($this->url($second), $data)->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->assertDatabaseHas('product_details', ['product_id' => $second, 'description' => 'Synthetic original description'], 'pos');
    }

    public function test_inactive_and_unknown_products_cannot_be_changed_and_route_ids_are_bounded(): void
    {
        $id = $this->facts();
        $data = $this->data($id);
        DB::connection('pos')->table('products')->where('id', $id)->update(['is_active' => 0]);
        $this->staffSession($this->staff())->get($this->url($id))->assertOk()->assertSee('This product is inactive.');
        $this->postJson($this->url($id), $data)->assertUnprocessable();
        $this->get($this->url(2147483647))->assertNotFound();
        $this->get('/manage/products/999999999999999999999999/information')->assertNotFound();
    }
}
