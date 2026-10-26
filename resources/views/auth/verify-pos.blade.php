<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify It's You — HCI GroceryOne</title>






@vite(['resources/css/legacy/mfa-pos.css','resources/js/legacy/mfa-pos.js'])
</head>

<body>
    <div class="card">
        <button type="button" class="theme-toggle" id="themeToggle" aria-label="Switch to dark mode">
            <svg class="moon" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                <path d="M16.5 12.8A6.8 6.8 0 0 1 7.2 3.5 6.8 6.8 0 1 0 16.5 12.8z" stroke="currentColor"
                    stroke-width="1.6" stroke-linejoin="round" />
            </svg>
            <svg class="sun" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                <circle cx="10" cy="10" r="3.2" stroke="currentColor" stroke-width="1.5" />
                <path
                    d="M10 2.5v2M10 15.5v2M2.5 10h2M15.5 10h2M4.7 4.7l1.4 1.4M13.9 13.9l1.4 1.4M15.3 4.7l-1.4 1.4M6.1 13.9l-1.4 1.4"
                    stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
            </svg>
            <span>Dark</span>
        </button>
        <div class="brand">
            <div class="brand-mark" tabindex="0" role="img" aria-label="Grocery Store shopping bag logo"><svg
                    viewBox="0 0 48 48" aria-hidden="true">
                    <path d="M10 18h28l-2.5 18.5a3 3 0 0 1-3 2.5h-17a3 3 0 0 1-3-2.5L10 18z" fill="#fff" />
                    <path d="M10 18h28v4a2 2 0 0 1-2 2H12a2 2 0 0 1-2-2v-4z" fill="#dbeafe" />
                    <path d="M16 18c0-5 3.5-9 8-9s8 4 8 9" fill="none" stroke="#fff" stroke-width="2.5"
                        stroke-linecap="round" />
                </svg></div>
            <div>
                <div class="brand-name">Grocery Store</div>
                <div class="brand-role">Point of Sale</div>
            </div>
        </div>
        <div class="icon-badge"><svg viewBox="0 0 20 20" fill="none">
                <rect x="4" y="9" width="12" height="8" rx="1.5" stroke="currentColor" stroke-width="1.6" />
                <path d="M6.5 9V6.5a3.5 3.5 0 017 0V9" stroke="currentColor" stroke-width="1.6" />
            </svg></div>
        <h1>Verify it's you</h1>
        <p class="sub">@if($mailUnavailable)Email delivery is unavailable for
            <strong>{{ $pending['full_name'] }}</strong> ({{ ucfirst($pending['role']) }}). Use a one-time backup code to finish logging in.
            @else We sent a 6-digit code to <strong>{{ $tfaEmailMasked }}</strong> — the email on file for
            <strong>{{ $pending['full_name'] }}</strong> ({{ ucfirst($pending['role']) }}). Enter it
            below to finish logging in.@endif</p>

        @if($errors->any())
            <div class="alert alert--error" role="alert">
                <svg viewBox="0 0 20 20" fill="none">
                    <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="1.6" />
                    <path d="M10 6.5v4M10 13.5v.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                </svg>
                <span>{{ $errors->first() }}</span>
            </div>
        @endif
        @if(session('status'))
            <div class="alert alert--info" role="status">
                <svg viewBox="0 0 20 20" fill="none">
                    <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="1.6" />
                    <path d="M10 9.5v4.5M10 6.5v.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                </svg>
                <span>{{ session('status') }}</span>
            </div>
        @endif

        @if(!$mailUnavailable)
            <form method="POST" action="{{ route('mfa.verify') }}">
                @csrf
                <input type="text" name="code" aria-label="Verification code" autocomplete="one-time-code" class="otp-input" maxlength="6" inputmode="numeric" pattern="[0-9]*"
                    placeholder="000000" autofocus required>
                <button type="submit" class="btn-submit">Verify &amp; Continue</button>
            </form>
        @endif

        <div class="link-row">
            <form method="POST" action="{{ route('mfa.verify') }}" style="margin:0;">
                    @csrf
                    <button type="submit" name="resend" value="1" formaction="{{ route('mfa.send') }}">Resend code</button>
                </form>

            <form method="post" action="{{ route('logout') }}" style="margin:0">@csrf<button type="submit" class="cancel-link" style="font:inherit;color:var(--muted);text-decoration:none">Cancel &amp; log out</button></form>
        </div>

        <details class="backup-box" @if($mailUnavailable) open @endif>
            <summary>{{ $mailUnavailable ? 'Enter a saved one-time backup code' : 'Lost access to your email? Use a backup code instead' }}</summary>
            <form method="POST" action="{{ route('mfa.verify') }}" style="margin-top:12px;">
                @csrf
                <input type="text" name="code" aria-label="Backup code" maxlength="32" autocomplete="off" required placeholder="XXXX-XXXX">
                <button type="submit" name="use_backup" value="1" class="btn-secondary">Use backup code</button>
            </form>
        </details>
    </div>

</body>

</html>
