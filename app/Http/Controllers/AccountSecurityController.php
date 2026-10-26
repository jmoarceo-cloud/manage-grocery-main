<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangeOwnPasswordRequest;
use App\Services\AccountSecurityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AccountSecurityController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->attributes->get('staff');
        $email = $user->two_factor_email;
        $masked = 'Not configured';
        if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            [$local,$host] = explode('@', $email, 2);
            $masked = mb_substr($local, 0, 1).'•••@'.$host;
        }
        $codes = json_decode($user->two_factor_backup_codes ?? '[]', true);

        return view('account.security', ['user' => $user, 'verificationEmail' => $masked, 'recoveryCount' => is_array($codes) ? count($codes) : 0]);
    }

    public function update(ChangeOwnPasswordRequest $request, AccountSecurityService $service)
    {
        $service->change($request->attributes->get('staff_domain'), $request->attributes->get('staff'), $request->validated());
        foreach (['pos', 'hrms'] as $guard) {
            Auth::guard($guard)->logout();
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $request->expectsJson() ? response()->json(['message' => 'Password changed. Sign in again.', 'redirect' => route('login')]) : redirect()->route('login')->with('status', 'Password changed. Sign in again.');
    }
}
