@extends('layouts.workspace')
@section('title','Account security')
@section('content')
<div class="page-heading"><div><span class="eyebrow">YOUR ACCOUNT, IN YOUR HANDS</span><h1>Account security.</h1><p>Review your sign-in details and change your password.</p></div></div>
<div class="account-security-grid"><section class="panel"><div class="panel-heading"><h2>Sign-in details</h2></div><dl class="ingredient-facts"><dt>Name</dt><dd>{{ $user->full_name }}</dd><dt>Username</dt><dd>{{ $user->username }}</dd><dt>Role</dt><dd>{{ ucwords(str_replace('_',' ',$user->role)) }}</dd><dt>Verification email</dt><dd>{{ $verificationEmail }}</dd><dt>Recovery codes remaining</dt><dd>{{ $recoveryCount }}</dd></dl></section>
<section class="panel"><div class="panel-heading"><h2>Change your password</h2></div><form method="post" action="{{ route('account.password') }}" class="form-panel" data-busy>@csrf
<p class="muted">Confirm your current password or use a one-time recovery code. You will sign in again after the change.</p>
<label>Confirm with<select name="credential_type" aria-label="Password change verification" data-password-credential><option value="password" @selected(old('credential_type','password')==='password')>Current password</option><option value="recovery" @selected(old('credential_type')==='recovery')>One-time recovery code</option></select></label>
<label data-current-password>Current password<input type="password" name="current_password" autocomplete="current-password" maxlength="1024" required></label>
<label data-password-recovery hidden>Recovery code<input name="backup_code" autocomplete="off" maxlength="64" disabled></label>
<label>New password<input type="password" name="password" autocomplete="new-password" minlength="12" required></label><p class="form-note">Use at least 12 characters with uppercase and lowercase letters, a number and a symbol.</p>
<label>Confirm new password<input type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required></label>
<div class="form-actions"><button class="button">Change password &amp; sign out</button></div></form></section></div>
@endsection
