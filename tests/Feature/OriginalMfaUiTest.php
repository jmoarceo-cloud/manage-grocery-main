<?php

namespace Tests\Feature;

use App\Models\HrUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

class OriginalMfaUiTest extends IsolatedFeatureTestCase
{
    public function test_pos_verification_preserves_original_controls_and_projects_only_escaped_identity_and_masked_email(): void
    {
        Mail::fake();
        $user = $this->cashier();
        $user->update(['full_name' => '<script>Synthetic staff</script>']);
        $this->post('/login', ['username' => $user->username, 'password' => 'Synthetic!Pass123'])->assertRedirect(route('mfa'));
        $response = $this->get('/two-factor')->assertOk()->assertViewIs('auth.verify-pos')->assertSee("Verify it's you", false)->assertSee('Point of Sale')->assertSee('Verify &amp; Continue', false)->assertSee('Use backup code')->assertSee('c•••@example.invalid')->assertSee('&lt;script&gt;Synthetic staff&lt;/script&gt;', false)->assertDontSee('<script>Synthetic staff</script>', false)->assertDontSee('cashier@example.invalid')->assertDontSee($user->password)->assertSee('maxlength="6"', false)->assertSee('aria-label="Backup code"', false)->assertSee(route('logout'), false)->assertSee('name="_token"', false);
        $this->assertSame(['full_name', 'role'], array_keys($response->viewData('pending')));
        $this->assertFalse($response->viewData('mailUnavailable'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertGuest('pos');
    }

    public function test_hr_verification_uses_the_canonical_domain_and_original_hr_brand_even_if_query_requests_pos(): void
    {
        Mail::fake();
        $id = DB::connection('hrms')->table('users')->insertGetId(['username' => 'synthetic_mfa_hr', 'full_name' => 'Synthetic MFA HR', 'password' => Hash::make('Synthetic!Pass123'), 'role' => 'hr_manager', 'is_active' => 1, 'two_factor_enabled' => 1, 'two_factor_email' => 'hr_manager@example.invalid']);
        $this->post('/login', ['username' => 'synthetic_mfa_hr', 'password' => 'Synthetic!Pass123'])->assertRedirect(route('mfa'));
        $this->get('/two-factor?domain=pos')->assertOk()->assertViewIs('auth.verify-hrms')->assertSee('HR &amp; Payroll', false)->assertSee('Synthetic MFA HR')->assertSee('Hr manager')->assertSee('h•••@example.invalid')->assertDontSee('hr_manager@example.invalid');
        $this->assertGuest('hrms');
        $this->post('/logout')->assertRedirect(route('login'))->assertSessionMissing('mfa_pending');
        $this->get('/two-factor')->assertRedirect(route('login'));
        $this->assertGuest('pos');
        $this->assertSame(1, (int) HrUser::findOrFail($id)->two_factor_enabled);
    }

    public function test_mail_unavailable_keeps_original_backup_visible_and_native_retry_without_bypassing_mfa(): void
    {
        Mail::fake();
        $user = $this->cashier();
        $key = 'mfa-send:'.hash('sha256', 'pos:'.$user->id);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            RateLimiter::hit($key, 900);
        }
        $this->post('/login', ['username' => $user->username, 'password' => 'Synthetic!Pass123']);
        $response = $this->get('/two-factor')->assertOk()->assertSee('Email delivery is unavailable')->assertSee('Enter a saved one-time backup code')->assertSee('Resend code');
        $this->assertTrue($response->viewData('mailUnavailable'));
        $this->assertMatchesRegularExpression('/<details\s+class="backup-box"\s+open\s*>/', $response->getContent());
        $this->post('/two-factor', ['code' => 'Synthetic-invalid'])->assertSessionHasErrors('code');
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest('pos');
        Mail::assertNothingSent();
    }

    public function test_original_mfa_styles_are_copied_without_changes_and_new_fonts_keep_upstream_licenses(): void
    {
        foreach (['pos' => 'project/verify_2fa.php', 'hrms' => 'project/HRMS/verify_2fa.php'] as $domain => $source) {
            preg_match_all('~<style>(.*?)</style>~s', file_get_contents(base_path($source)), $styles);
            foreach ($styles[1] as $index => $css) {
                $this->assertSame(hash('sha256', $css), hash_file('sha256', resource_path('css/legacy/source/mfa-'.$domain.'-'.$index.'.css')));
            }
        }
        foreach (['inter', 'sora'] as $family) {
            $this->assertSame(hash_file('sha256', resource_path('fonts/'.$family.'/OFL.txt')), hash_file('sha256', public_path('fonts/'.$family.'/OFL.txt')));
        }
    }
}
