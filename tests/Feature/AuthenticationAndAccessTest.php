<?php

namespace Tests\Feature;

use App\Mail\StaffVerification;
use App\Models\HrUser;
use App\Services\StaffAuthentication;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

class AuthenticationAndAccessTest extends IsolatedFeatureTestCase
{
    public function test_login_template_renders_password_change_status_and_validation_errors_together(): void
    {
        $this->withSession(['status' => 'Password changed. Sign in again.'])->withViewErrors(['username' => 'Synthetic validation message.'])->view('auth.login')->assertSee('Password changed. Sign in again.')->assertSee('role="alert"', false)->assertSee('Synthetic validation message.');
    }

    public function test_recovery_remains_available_when_automatic_mail_is_throttled(): void
    {
        Mail::fake();
        $user = $this->cashier();
        $user->update(['two_factor_backup_codes' => json_encode([password_hash('SYNTHETIC-MAIL-RESCUE', PASSWORD_BCRYPT)])]);
        $key = 'mfa-send:'.hash('sha256', 'pos:'.$user->id);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            RateLimiter::hit($key, 900);
        }
        $this->post('/login', ['username' => $user->username, 'password' => 'Synthetic!Pass123'])->assertRedirect(route('mfa'));
        $this->assertGuest('pos');
        $this->get('/two-factor')->assertOk()->assertSee('temporarily unavailable');
        $this->post('/two-factor', ['code' => 'SYNTHETIC-MAIL-RESCUE'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user, 'pos');
        Mail::assertNothingSent();
    }

    public function test_transport_failure_keeps_mfa_pending_without_granting_access(): void
    {
        $user = $this->cashier();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Synthetic transport failure'));
        $this->post('/login', ['username' => $user->username, 'password' => 'Synthetic!Pass123'])->assertRedirect(route('mfa'));
        $this->assertGuest('pos');
        $this->get('/two-factor')->assertOk()->assertSee('temporarily unavailable')->assertSee('Resend code');
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_lost_revocation_cache_invalidates_a_session_instead_of_reviving_it(): void
    {
        $user = $this->cashier();
        $this->staffSession($user)->get('/dashboard')->assertOk();
        Cache::forget('staff-session-version:pos:'.$user->id);
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest('pos');
    }

    public function test_forced_logout_cannot_be_undone_for_old_sessions_by_a_new_login(): void
    {
        Mail::fake();
        $user = $this->cashier();
        DB::connection('pos')->table('users')->where('id', $user->id)->update(['force_logout' => 1]);
        $this->post('/login', ['username' => $user->username, 'password' => 'Synthetic!Pass123']);
        $this->post('/two-factor', ['code' => Mail::sent(StaffVerification::class)->first()->code])->assertRedirect(route('dashboard'));
        $this->assertFalse((bool) $user->fresh()->force_logout);
        $this->get('/dashboard')->assertOk();
        $this->withSession(['staff_session_version' => '0'])->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest('pos');
    }

    public function test_mirrored_admin_uses_hr_password_and_maps_pos_actor_without_reusing_hr_id(): void
    {
        Mail::fake();
        $pos = $this->cashier();
        DB::connection('pos')->table('users')->where('id', $pos->id)->update(['role' => 'admin']);
        $hrId = DB::connection('hrms')->table('users')->insertGetId(['username' => $pos->username, 'full_name' => 'Synthetic HR Admin', 'password' => Hash::make('HrSynthetic!Pass123'), 'role' => 'admin', 'is_active' => 1, 'two_factor_enabled' => 1, 'two_factor_email' => 'admin@example.invalid']);
        $this->assertNotSame((int) $pos->id, (int) $hrId);
        $this->post('/login', ['username' => $pos->username, 'password' => 'Synthetic!Pass123'])->assertSessionHasErrors('username');
        $this->post('/login', ['username' => $pos->username, 'password' => 'HrSynthetic!Pass123'])->assertRedirect(route('mfa'));
        $this->post('/two-factor', ['code' => Mail::sent(StaffVerification::class)->first()->code])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs(HrUser::findOrFail($hrId), 'hrms');
        $this->assertGuest('pos');
        $id = $this->product();
        $this->post('/inventory/restock', ['product_id' => $id, 'quantity' => 1, 'cost_price' => '10.00', 'reason' => 'Synthetic mapped actor'])->assertRedirect();
        $this->assertDatabaseHas('stock_logs', ['product_id' => $id, 'user_id' => $pos->id, 'change_type' => 'restock'], 'pos');
    }

    public function test_password_alone_does_not_grant_workspace_access(): void
    {
        Mail::fake();
        $user = $this->cashier();
        $this->post('/login', ['username' => $user->username, 'password' => 'Synthetic!Pass123'])->assertRedirect(route('mfa'));
        $this->assertGuest('pos');
        $this->assertGuest('hrms');
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_email_code_completes_sign_in_and_cashier_cannot_reach_admin(): void
    {
        Mail::fake();
        $user = $this->cashier();
        $this->post('/login', ['username' => $user->username, 'password' => 'Synthetic!Pass123'])->assertRedirect();
        $code = Mail::sent(StaffVerification::class)->first()->code;
        $this->post('/two-factor', ['code' => $code])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user, 'pos');
        $this->get('/dashboard')->assertOk();
        $this->get('/admin')->assertForbidden();
        $this->get('/manage/products')->assertForbidden();
        $this->get('/pos')->assertOk();
    }

    public function test_existing_two_factor_email_cannot_be_rebound_with_only_a_password(): void
    {
        Mail::fake();
        $user = $this->cashier();
        $this->post('/login', ['username' => $user->username, 'password' => 'Synthetic!Pass123']);
        $this->post('/two-factor/send', ['email' => 'attacker@example.invalid'])->assertRedirect();
        Mail::assertSent(StaffVerification::class, 2);
        foreach (Mail::sent(StaffVerification::class) as $message) {
            $this->assertTrue($message->hasTo('cashier@example.invalid'));
            $this->assertFalse($message->hasTo('attacker@example.invalid'));
        }
        $this->assertGuest('pos');
    }

    public function test_inactive_account_is_denied_before_mail_is_sent(): void
    {
        Mail::fake();
        $user = $this->cashier();
        DB::connection('pos')->table('users')->where('id', $user->id)->update(['is_active' => 0]);
        $this->post('/login', ['username' => $user->username, 'password' => 'Synthetic!Pass123'])->assertSessionHasErrors('username');
        $this->assertGuest('pos');
        Mail::assertNothingSent();
    }

    public function test_login_is_throttled_after_five_failed_attempts(): void
    {
        Mail::fake();
        $user = $this->cashier();
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->post('/login', ['username' => $user->username, 'password' => 'wrong synthetic password'])->assertSessionHasErrors('username');
        }
        $this->assertSame(5, RateLimiter::attempts(StaffAuthentication::key($user->username, '127.0.0.1')));
        $this->assertGuest('pos');
        Mail::assertNothingSent();
    }

    public function test_force_logout_revokes_an_existing_session(): void
    {
        $user = $this->cashier();
        $this->staffSession($user);
        // actingAs retains its object in memory within this test; refresh it to model a new request.
        DB::connection('pos')->table('users')->where('id', $user->id)->update(['force_logout' => 1]);
        $user->refresh();
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest('pos');
    }

    public function test_forged_checkout_is_rejected_before_any_sale(): void
    {
        $user = $this->cashier();
        $id = $this->product();
        $this->staffSession($user);
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->postJson('/api/pos/checkout', $this->cart($id))->assertStatus(419);
        $this->assertDatabaseCount('sales', 0, 'pos');
    }

    public function test_client_prices_and_nonpositive_quantities_are_rejected(): void
    {
        $user = $this->cashier();
        $id = $this->product();
        $this->staffSession($user);
        $cart = $this->cart($id);
        $cart['items'][0]['price'] = '0.01';
        $this->postJson('/api/pos/checkout', $cart)->assertUnprocessable();
        unset($cart['items'][0]['price']);
        $cart['items'][0]['quantity'] = -3;
        $this->postJson('/api/pos/checkout', $cart)->assertUnprocessable();
        $this->assertDatabaseCount('sales', 0, 'pos');
    }

    public function test_first_enrollment_returns_hashed_recovery_codes_once(): void
    {
        Mail::fake();
        $user = $this->cashier();
        $user->update(['two_factor_enabled' => 0, 'two_factor_email' => null]);
        $this->post('/login', ['username' => $user->username, 'password' => 'Synthetic!Pass123']);
        $this->post('/two-factor/send', ['email' => 'enrollment@example.invalid']);
        $code = Mail::sent(StaffVerification::class)->first()->code;
        $response = $this->post('/two-factor', ['code' => $code])->assertRedirect(route('mfa.recovery'));
        $codes = $response->getSession()->get('new_backup_codes');
        $this->assertCount(8, $codes);
        $stored = json_decode($user->fresh()->two_factor_backup_codes, true);
        $this->assertCount(8, $stored);
        foreach ($codes as $index => $plain) {
            $this->assertNotSame($plain, $stored[$index]);
            $this->assertTrue(password_verify($plain, $stored[$index]));
        }
        $this->get('/account/recovery-codes')->assertOk()->assertSee($codes[0]);
        $this->get('/account/recovery-codes')->assertRedirect(route('dashboard'));
    }

    public function test_a_recovery_code_is_consumed_and_cannot_be_replayed(): void
    {
        Mail::fake();
        $user = $this->cashier();
        $user->update(['two_factor_backup_codes' => json_encode([password_hash('SYNTHETIC-ONCE', PASSWORD_BCRYPT)])]);
        $credentials = ['username' => $user->username, 'password' => 'Synthetic!Pass123'];
        $this->post('/login', $credentials);
        $this->post('/two-factor', ['code' => 'SYNTHETIC-ONCE'])->assertRedirect(route('dashboard'));
        $this->assertSame([], json_decode($user->fresh()->two_factor_backup_codes, true));
        $this->post('/logout');
        $this->post('/login', $credentials);
        $this->post('/two-factor', ['code' => 'SYNTHETIC-ONCE'])->assertSessionHasErrors('code');
        $this->assertGuest('pos');
    }

    public function test_five_invalid_codes_persist_an_account_lock(): void
    {
        Mail::fake();
        $user = $this->cashier();
        $this->post('/login', ['username' => $user->username, 'password' => 'Synthetic!Pass123']);
        for ($index = 0; $index < 5; $index++) {
            $this->post('/two-factor', ['code' => 'INVALID-SYNTHETIC'])->assertSessionHasErrors('code');
        }
        $this->assertSame(5, (int) $user->fresh()->two_factor_failed_attempts);
        $this->assertTrue($user->fresh()->two_factor_locked_until->isFuture());
        $this->assertGuest('pos');
        $this->post('/login', ['username' => $user->username, 'password' => 'Synthetic!Pass123'])->assertSessionHasErrors('username');
    }

    public function test_expired_mfa_lock_starts_a_new_attempt_window(): void
    {
        Mail::fake();
        $user = $this->cashier();
        $user->update(['two_factor_failed_attempts' => 5, 'two_factor_locked_until' => now()->subMinute()]);
        $this->post('/login', ['username' => $user->username, 'password' => 'Synthetic!Pass123']);
        $this->post('/two-factor', ['code' => 'INVALID-SYNTHETIC'])->assertSessionHasErrors('code');
        $this->assertSame(1, (int) $user->fresh()->two_factor_failed_attempts);
        $this->assertNull($user->fresh()->two_factor_locked_until);
    }
}
