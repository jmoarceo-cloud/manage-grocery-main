@extends('layouts.base')
@section('title','Verify your sign-in - GroceryOne')
@section('body')
<main id="main" class="challenge-page"><div class="challenge-card"><x-brand/><span class="eyebrow">ONE MORE STEP</span><h1>Make it securely yours.</h1><p class="muted">Use the code from your email or a one-time recovery code.</p>
@if(session('status'))<div class="notice notice-success" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="notice notice-error" role="alert">{{ $errors->first() }}</div>@endif
@if($needsEmail)
<form method="post" action="{{ route('mfa.send') }}" data-busy>@csrf<label>Verification email<input type="email" name="email" required autocomplete="email" maxlength="150"></label><button class="button">Send verification code</button></form>
@endif
<form method="post" action="{{ route('mfa.verify') }}" data-busy>@csrf<label>Verification or recovery code<input name="code" autocomplete="one-time-code" required maxlength="32" autofocus></label><button class="button">Verify and sign in &#8594;</button></form>
@if(!$needsEmail)<form method="post" action="{{ route('mfa.send') }}" data-busy>@csrf<button class="button button-outline">Resend verification code</button></form>@endif
<a class="text-link" href="{{ route('login') }}">Start again</a></div></main>
@endsection