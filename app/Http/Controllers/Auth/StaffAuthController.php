<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\VerifyCodeRequest;
use App\Services\StaffAuthentication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StaffAuthController extends Controller
{
    public function login()
    {
        return view('auth.login');
    }

    public function authenticate(LoginRequest $request, StaffAuthentication $auth)
    {
        $auth->begin($request, $request->validated());

        return redirect()->route('mfa');
    }

    public function challenge(Request $request, StaffAuthentication $auth)
    {
        if (! $request->session()->has('mfa_pending')) {
            return redirect()->route('login');
        }$user = $auth->pending($request);

        $needsEmail = ! $user->two_factor_enabled || ! filter_var($user->two_factor_email, FILTER_VALIDATE_EMAIL);
        if ($needsEmail) {
            return view('auth.challenge', ['needsEmail' => true]);
        }
        [$local, $host] = explode('@', $user->two_factor_email, 2);

        return view($request->session()->get('mfa_pending.domain') === 'pos' ? 'auth.verify-pos' : 'auth.verify-hrms', [
            'pending' => ['full_name' => $user->full_name, 'role' => $user->role],
            'tfaEmailMasked' => mb_substr($local, 0, 1).'•••@'.$host,
            'mailUnavailable' => ! $request->session()->has('mfa_pending.otp_hash'),
        ]);
    }

    public function send(Request $request, StaffAuthentication $auth)
    {
        $data = $request->validate(['email' => ['nullable', 'email:rfc', 'max:150']]);
        $auth->send($request, $data['email'] ?? '');

        return redirect()->route('mfa')->with('status', 'A verification code has been sent.');
    }

    public function verify(VerifyCodeRequest $request, StaffAuthentication $auth)
    {
        $codes = $auth->verify($request, $request->validated('code'));
        if ($codes !== []) {
            return redirect()->route('mfa.recovery')->with('new_backup_codes', $codes);
        }

        return redirect()->route('dashboard');
    }

    public function recovery(Request $request)
    {
        if (! $request->session()->has('new_backup_codes')) {
            return redirect()->route('dashboard');
        }

        return view('auth.recovery', ['codes' => $request->session()->get('new_backup_codes')]);
    }

    public function logout(Request $request)
    {
        foreach (['pos', 'hrms'] as $guard) {
            Auth::guard($guard)->logout();
        }$request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
