<?php

namespace App\Services;

use App\Mail\StaffVerification;
use App\Models\HrUser;
use App\Models\PosUser;
use App\Models\StaffUser;
use App\Support\RecoveryCodes;
use App\Support\StaffSessionVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class StaffAuthentication
{
    public static function canonicalDomain(?object $pos, ?object $hr): string
    {
        return $pos && in_array($pos->role, ['cashier', 'inventory_staff', 'finance'], true) ? 'pos' : ($hr ? 'hrms' : 'pos');
    }

    public static function key(string $username, string $ip): string
    {
        return 'staff-login:'.hash('sha256', $username.'|'.$ip);
    }

    public static function eligible(StaffUser $user, string $domain): bool
    {
        if (! $user->is_active || ($user->is_locked ?? false) || ($user->locked_until && $user->locked_until->isFuture()) || ($user->two_factor_locked_until && $user->two_factor_locked_until->isFuture())) {
            return false;
        }
        if ($user->employee_id) {
            $employee = DB::connection('hrms')->table('employees')->where('employee_id', $user->employee_id)->first(['status', 'position', 'suspension_start_date', 'suspension_end_date']);
            if (! self::employeeEligible($user, $domain, $employee)) {
                return false;
            }
        }

        return in_array($user->role, $domain === 'pos' ? ['admin', 'cashier', 'inventory_staff', 'finance'] : ['admin', 'hr', 'hr_manager', 'supervisor', 'accountant', 'employee'], true);
    }

    public static function employeeEligible(object $user, string $domain, ?object $employee): bool
    {
        if (! $employee || in_array($employee->status, ['inactive', 'resigned', 'terminated', 'suspended'], true)) {
            return false;
        }
        if ($employee->suspension_start_date && $employee->suspension_start_date <= date('Y-m-d') && (! $employee->suspension_end_date || $employee->suspension_end_date >= date('Y-m-d'))) {
            return false;
        }
        if ($domain === 'pos' && ! in_array($employee->position, ['Cashier', 'Inventory Staff', 'Accountant'], true)) {
            return false;
        }

        return ! ($domain === 'hrms' && $user->role === 'employee' && in_array($employee->position, ['Cashier', 'Inventory Staff', 'Accountant'], true));
    }

    public function begin(Request $request, array $data): void
    {
        $key = self::key($data['username'], $request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['username' => 'Unable to sign in. Please try again later.']);
        }
        $pos = PosUser::whereRaw('BINARY username = ?', [$data['username']])->first();
        $hr = HrUser::whereRaw('BINARY username = ?', [$data['username']])->first();
        $domain = self::canonicalDomain($pos, $hr);
        $user = $domain === 'pos' ? $pos : $hr;
        if (! $user || ! Hash::check($data['password'], $user->password) || ! self::eligible($user, $domain)) {
            RateLimiter::hit($key, 900);
            throw ValidationException::withMessages(['username' => 'Unable to sign in with those details.']);
        }
        $request->session()->regenerate();
        $request->session()->forget(['staff_domain', 'staff_mfa', 'staff_password_signature']);
        foreach (['pos', 'hrms'] as $guard) {
            Auth::guard($guard)->logout();
        }
        $request->session()->put('mfa_pending', ['domain' => $domain, 'id' => $user->id, 'password_signature' => hash('sha256', $user->password), 'expires' => time() + 600, 'rate_key' => $key]);
        if ($user->two_factor_enabled && filter_var($user->two_factor_email, FILTER_VALIDATE_EMAIL)) {
            try {
                $this->send($request, $user->two_factor_email);
            } catch (ValidationException $exception) {
                $request->session()->flash('status', 'Email verification is temporarily unavailable. Use an existing recovery code, or request another code later.');
            }
        }
    }

    public function pending(Request $request): StaffUser
    {
        $pending = $request->session()->get('mfa_pending');
        if (! $pending || $pending['expires'] < time()) {
            $request->session()->forget('mfa_pending');
            throw ValidationException::withMessages(['code' => 'Verification expired. Please sign in again.']);
        }
        $user = ($pending['domain'] === 'pos' ? PosUser::class : HrUser::class)::find($pending['id']);
        if (! $user || ! self::eligible($user, $pending['domain']) || ! hash_equals($pending['password_signature'], hash('sha256', $user->password))) {
            $request->session()->forget('mfa_pending');
            abort(403);
        }

        return $user;
    }

    public function send(Request $request, string $email): void
    {
        $user = $this->pending($request);
        $pending = $request->session()->get('mfa_pending');
        if ($user->two_factor_enabled && filter_var($user->two_factor_email, FILTER_VALIDATE_EMAIL)) {
            $email = $user->two_factor_email;
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['email' => 'Enter a valid verification email.']);
        }
        $key = 'mfa-send:'.hash('sha256', $pending['domain'].':'.$pending['id']);
        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw ValidationException::withMessages(['email' => 'Please wait before requesting another code.']);
        }
        RateLimiter::hit($key, 900);
        $code = (string) random_int(100000, 999999);
        $pending['otp_hash'] = Hash::make($code);
        $pending['otp_expires'] = time() + 300;
        $pending['email'] = $email;
        $request->session()->put('mfa_pending', $pending);
        try {
            Mail::to($email)->send(new StaffVerification($code));
        } catch (\Throwable $exception) {
            $request->session()->forget('mfa_pending.otp_hash');
            throw ValidationException::withMessages(['email' => 'The verification message could not be sent. Please try again later.']);
        }
    }

    public function verify(Request $request, string $code): array
    {
        $user = $this->pending($request);
        $pending = $request->session()->get('mfa_pending');
        $key = 'mfa-verify:'.hash('sha256', $pending['domain'].':'.$pending['id'].'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => 'Please wait before trying again.']);
        }
        RateLimiter::hit($key, 900);
        $newCodes = [];
        $revoke = false;
        $verified = DB::connection($pending['domain'])->transaction(function () use ($code, $pending, $user, &$newCodes, &$revoke): ?StaffUser {
            $locked = $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! self::eligible($locked, $pending['domain']) || ! hash_equals($pending['password_signature'], hash('sha256', $locked->password))) {
                throw ValidationException::withMessages(['code' => 'Sign in again to continue.']);
            }
            if ($locked->two_factor_locked_until && $locked->two_factor_locked_until->isPast()) {
                $locked->two_factor_failed_attempts = 0;
                $locked->two_factor_locked_until = null;
            }
            $validOtp = isset($pending['otp_hash'],$pending['otp_expires']) && $pending['otp_expires'] >= time() && Hash::check($code, $pending['otp_hash']);
            $valid = $validOtp;
            if (! $valid) {
                $remaining = RecoveryCodes::consume($locked->two_factor_backup_codes, $code);
                if ($remaining !== null) {
                    $valid = true;
                    $locked->two_factor_backup_codes = $remaining;
                }
            }
            if (! $valid) {
                $locked->two_factor_failed_attempts = (int) $locked->two_factor_failed_attempts + 1;
                if ($locked->two_factor_failed_attempts >= 5) {
                    $locked->two_factor_locked_until = now()->addMinutes(15);
                }
                $locked->save();

                return null;
            }
            if ($validOtp && isset($pending['email'])) {
                if (! $locked->two_factor_enabled && ! $locked->two_factor_backup_codes) {
                    for ($index = 0; $index < 8; $index++) {
                        $raw = strtoupper(bin2hex(random_bytes(8)));
                        $newCodes[] = implode('-', str_split($raw, 4));
                    }
                    $locked->two_factor_backup_codes = json_encode(array_map(fn ($value) => Hash::make($value), $newCodes));
                }
                $locked->two_factor_enabled = 1;
                $locked->two_factor_email = $pending['email'];
            }
            $revoke = (bool) $locked->force_logout;
            $locked->force_logout = 0;
            $locked->failed_attempts = 0;
            $locked->two_factor_failed_attempts = 0;
            $locked->two_factor_locked_until = null;
            $locked->save();
            if ($pending['domain'] === 'pos') {
                DomainAudit::record('users', (int) $locked->id, 'mfa_verified', ['two_factor_enabled', 'two_factor_email', 'two_factor_backup_codes'], (int) $locked->id);
            } else {
                DB::connection('hrms')->table('hr_audit_log')->insert(['module' => 'auth', 'record_id' => $locked->id, 'action' => 'mfa_verified', 'details' => json_encode(['changed_fields' => ['two_factor_enabled', 'two_factor_email', 'two_factor_backup_codes']]), 'user_id' => $locked->id, 'user_name' => 'Verified staff']);
            }

            return $locked;
        });
        if (! $verified) {
            throw ValidationException::withMessages(['code' => 'The verification code is invalid or expired.']);
        }
        if ($revoke) {
            StaffSessionVersion::revoke($pending['domain'], (int) $verified->id);
        }
        Auth::guard($pending['domain'])->login($verified);
        RateLimiter::clear($key);
        RateLimiter::clear($pending['rate_key']);
        $request->session()->forget('mfa_pending');
        $request->session()->regenerate();
        $request->session()->put(['staff_domain' => $pending['domain'], 'staff_mfa' => true, 'staff_password_signature' => hash('sha256', $user->password), 'staff_activity' => time(), 'staff_session_version' => StaffSessionVersion::current($pending['domain'], (int) $verified->id)]);

        return $newCodes;
    }
}
