<!DOCTYPE html>
<html lang="tl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log In — HCI GroceryOne</title>






    @include('legacy.login-theme-init')





    @vite(['resources/css/legacy/login.css','resources/js/legacy/login.js'])
</head>

<body class="login-page">

    <div class="login-shell">

        <!-- ── LEFT — BRAND / DESCRIPTION ── -->
        <aside class="brand-panel">
            <span class="brand-orb brand-orb--1"></span>
            <span class="brand-orb brand-orb--2"></span>
            <span class="brand-orb brand-orb--3"></span>
            <div class="brand-crest">
                <div class="crest-mark" tabindex="0" role="img" aria-label="Grocery Store shopping bag logo">
                    <svg viewBox="0 0 48 48" aria-hidden="true">
                        <path d="M10 18h28l-2.5 18.5a3 3 0 0 1-3 2.5h-17a3 3 0 0 1-3-2.5L10 18z" fill="#4d8dff" />
                        <path d="M10 18h28v4a2 2 0 0 1-2 2H12a2 2 0 0 1-2-2v-4z" fill="#FFFFFF" fill-opacity=".9" />
                        <path d="M16 18c0-5 3.5-9 8-9s8 4 8 9" fill="none" stroke="#FFFFFF" stroke-width="2.5"
                            stroke-linecap="round" />
                    </svg>
                </div>
                <div>
                    <div class="crest-text">Grocery Store</div>
                    <div class="crest-tag">Point of Sale</div>
                </div>
            </div>

            <div class="brand-body">
                <div class="brand-eyebrow">Secure Staff Access</div>
                <h1 class="brand-heading">One login for<br>every <span>team</span>,<br>every workspace.</h1>
                <p class="brand-desc">Sign in once with your staff account. Your role determines whether you are routed
                    to POS, Finance, HR, Payroll, or your employee workspace.</p>

                <ul class="brand-features">
                    <li><span class="feat-icon">$</span> POS, inventory &amp; finance workspaces</li>
                    <li><span class="feat-icon">+</span> HR, payroll &amp; employee services</li>
                    <li><span class="feat-icon">=</span> Role-based access with 2FA</li>
                </ul>
            </div>

        </aside>

        <!-- ── RIGHT — LOGIN FORM ── -->
        <main class="login-panel">
            <div class="login-wrap">

                <div class="top-row">
                    <a href="{{ route('home') }}" class="back-link">
                        <svg viewBox="0 0 20 20" fill="none">
                            <path d="M12 4l-6 6 6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                                stroke-linejoin="round" />
                        </svg>
                        Back
                    </a>

                    <button class="theme-toggle" id="themeToggle" type="button" aria-label="Switch to dark mode"
                        aria-pressed="false">
                        <span>Dark mode</span>
                    </button>
                </div>

                <div class="login-card">

                    <h1 class="login-title">Welcome back</h1>
                    <p class="login-subtitle">Log in to continue</p>

                    @if(session('status'))
                        <div class="alert alert--warning" role="status">
                            <svg viewBox="0 0 20 20" fill="none">
                                <path d="M10 3l8 14H2L10 3z" stroke="currentColor" stroke-width="1.6"
                                    stroke-linejoin="round" />
                                <path d="M10 8v4M10 14.5v.01" stroke="currentColor" stroke-width="1.6"
                                    stroke-linecap="round" />
                            </svg>
                            <span>{{ session('status') }}</span>
                        </div>
                    @endif

                    @if($errors->any())
                        <div class="alert alert--error" role="alert">
                            <svg viewBox="0 0 20 20" fill="none">
                                <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="1.6" />
                                <path d="M10 6.5v4M10 13.5v.01" stroke="currentColor" stroke-width="1.6"
                                    stroke-linecap="round" />
                            </svg>
                            <span>{{ $errors->first() }}</span>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('login.submit') }}"
                        autocomplete="off">
                        @csrf

                        <div class="field-group">
                            <label class="field-label" for="username">Username</label>
                            <input type="text" id="username" name="username" class="field-input"
                                placeholder="Enter username."
                                value="{{ old('username') }}"
                                autocomplete="username" required>
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="password">Password</label>
                            <div class="password-field">
                                <input type="password" id="password" name="password" class="field-input"
                                    placeholder="Enter password" autocomplete="current-password" required>
                                <button type="button" class="password-toggle" id="togglePassword"
                                    aria-label="Show password">
                                    <svg id="eyeIcon" viewBox="0 0 20 20" fill="none">
                                        <path d="M1.5 10S4.5 4 10 4s8.5 6 8.5 6-3 6-8.5 6-8.5-6-8.5-6z"
                                            stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" />
                                        <circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.5" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <button type="submit" class="btn-submit">Login</button>
                    </form>
                    <a class="reset-password-link" href="{{ rtrim(config('app.legacy_url'),'/') }}/password_reset_request.php">Forgot your password?</a>

                    <p class="login-footer-note">Grocery Store &amp; Point of Sale</p>
                </div>
            </div>
        </main>

    </div>


</body>

</html>
