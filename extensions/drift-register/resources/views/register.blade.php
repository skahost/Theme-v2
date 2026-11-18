<!doctype html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#0e4688">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Create an account &middot; {{ config('app.name') }}</title>
    @if ($enabled && $turnstileEnabled && $turnstileConfigured)
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endif
    <style>
        /* Same tokens and look as the Panel's dark theme (the shipping v2 theme). */
        :root {
            color-scheme: dark;
            --radius: 0.5rem;
            --background: oklch(0.366 0.027 246.8);
            --foreground: oklch(0.858 0.013 248);
            --card: oklch(0.413 0.029 246.8);
            --muted-foreground: oklch(0.617 0.023 250);
            --primary: oklch(0.55 0.188 259.8);
            --primary-foreground: oklch(0.975 0.005 258.3);
            --border: oklch(0.478 0.027 246.6);
            --ring: oklch(0.714 0.143 254.6);
            --destructive: oklch(0.637 0.237 25.331);
            --sunken: color-mix(in oklab, var(--card) 85%, black);
            --success: oklch(0.723 0.219 149.579);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem 0.75rem;
            font-family: 'IBM Plex Sans', 'Roboto', system-ui, sans-serif;
            background: var(--background);
            color: var(--foreground);
            -webkit-font-smoothing: antialiased;
        }
        .wrap { width: 100%; max-width: 43.75rem; }
        h2.title { margin: 0; padding: 1rem 0; font-size: 1.875rem; font-weight: 500; text-align: center; line-height: 2.25rem; }
        .card {
            display: flex;
            gap: 0;
            background: var(--card);
            border-radius: var(--radius);
            box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.25), 0 4px 6px -4px rgb(0 0 0 / 0.25);
            padding: 1.5rem;
            margin: 0 0.25rem;
        }
        .mark { flex: none; align-self: center; width: 14rem; padding: 0 3rem; user-select: none; }
        .mark img { display: block; width: 100%; height: auto; border-radius: 1rem; }
        .content { flex: 1; min-width: 0; }
        @media (max-width: 47.99rem) {
            .card { display: block; }
            .mark { width: 8rem; padding: 0; margin: 0 auto 1.5rem; }
        }
        .lead { margin: 0 0 1.25rem; font-size: 0.875rem; color: var(--muted-foreground); line-height: 1.55; }
        .field { margin-bottom: 1.25rem; }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
        .row.single { grid-template-columns: 1fr; }
        .opt { text-transform: none; letter-spacing: 0; font-weight: 400; opacity: 0.8; }
        .connected { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.625rem 0.875rem; font-size: 0.875rem; border-radius: var(--radius); border: 1px solid color-mix(in oklab, var(--success) 50%, transparent); background: color-mix(in oklab, var(--success) 12%, transparent); color: var(--success); }
        .connected a { color: var(--muted-foreground); font-size: 0.75rem; }
        @media (max-width: 30rem) { .row { grid-template-columns: 1fr; } }
        label { display: block; margin-bottom: 0.375rem; font-size: 0.75rem; font-weight: 500; letter-spacing: 0.05em; text-transform: uppercase; color: var(--muted-foreground); }
        input {
            width: 100%;
            height: 2.75rem;
            padding: 0 0.875rem;
            font: inherit;
            font-size: 0.9375rem;
            color: var(--foreground);
            background: var(--sunken);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            outline: none;
            transition: border-color .15s, box-shadow .15s;
        }
        input:hover { border-color: color-mix(in oklab, var(--border) 60%, var(--foreground)); }
        input:focus-visible { border-color: var(--ring); box-shadow: 0 0 0 3px color-mix(in oklab, var(--ring) 30%, transparent); }
        input[aria-invalid='true'] { border-color: var(--destructive); }
        .error { margin: 0.375rem 0 0; font-size: 0.8125rem; color: var(--destructive); }
        .notice { margin: 0 0 1.25rem; padding: 0.75rem 0.875rem; font-size: 0.875rem; line-height: 1.5; border-radius: var(--radius); border: 1px solid; }
        .notice.danger { color: var(--destructive); border-color: color-mix(in oklab, var(--destructive) 50%, transparent); background: color-mix(in oklab, var(--destructive) 12%, transparent); }
        .notice.success { color: var(--success); border-color: color-mix(in oklab, var(--success) 50%, transparent); background: color-mix(in oklab, var(--success) 12%, transparent); }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.625rem;
            width: 100%;
            height: 3rem;
            padding: 0 1rem;
            font: inherit;
            font-size: 0.875rem;
            font-weight: 500;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            text-decoration: none;
            border: 0;
            border-radius: var(--radius);
            cursor: pointer;
            transition: filter .15s, transform .15s, box-shadow .15s;
        }
        .btn:focus-visible { outline: 2px solid var(--ring); outline-offset: 2px; }
        .btn:disabled { opacity: 0.6; cursor: progress; }
        .btn-primary { color: var(--primary-foreground); background: var(--primary); box-shadow: inset 0 1px 0 rgb(255 255 255 / 0.12); }
        .btn-primary:hover:not(:disabled) { filter: brightness(1.12); }
        .btn-discord {
            margin-top: 0.75rem;
            color: #fff;
            background: linear-gradient(135deg, #5865f2, #4752c4);
            box-shadow: 0 4px 14px -4px rgb(88 101 242 / 0.7), inset 0 1px 0 rgb(255 255 255 / 0.18);
        }
        .btn-discord:hover { transform: translateY(-1px); box-shadow: 0 8px 20px -6px rgb(88 101 242 / 0.85), inset 0 1px 0 rgb(255 255 255 / 0.18); }
        .btn-discord:active { transform: translateY(0); }
        .btn svg { flex: none; width: 1.25rem; height: 1.25rem; }
        .btn-discord img { flex: none; width: 1.5rem; height: auto; }
        .btn-google { color: #1f1f1f; background: #fff; box-shadow: 0 1px 2px rgb(0 0 0 / 0.3); }
        .btn-google:hover { filter: brightness(0.96); }
        .sso-stack .btn-discord, .field .btn-discord { margin-top: 0; }
        .sso-stack .btn + .btn { margin-top: 0.75rem; }
        .sso-divider { margin: 1.25rem 0; }
        .divider { display: flex; align-items: center; gap: 0.75rem; margin: 1.25rem 0 0; font-size: 0.75rem; letter-spacing: 0.05em; text-transform: uppercase; color: var(--muted-foreground); }
        .divider::before, .divider::after { content: ''; flex: 1; height: 1px; background: var(--border); }
        .footer { margin: 1.5rem 0 0; text-align: center; font-size: 0.75rem; letter-spacing: 0.05em; text-transform: uppercase; color: var(--muted-foreground); }
        .footer a { color: var(--muted-foreground); text-decoration: none; }
        .footer a:hover { color: var(--foreground); }
        .copyright { margin: 1rem 0 0; text-align: center; font-size: 0.75rem; color: var(--muted-foreground); }
        [hidden] { display: none !important; }
    </style>
</head>
<body>
<div class="wrap">
    <h2 class="title">{{ $enabled ? 'Create an account' : 'Registration is closed' }}</h2>

    <main class="card">
        @if ($logo)
            <div class="mark"><img src="{{ $logo }}" alt="" width="224" height="224"></div>
        @endif
        <div class="content">
    @if (! $enabled)
            <p class="lead">New sign-ups are currently disabled.</p>
            <a class="btn btn-primary" href="/auth/login">Back to login</a>
            @if ($showDiscord)
            <a class="btn btn-discord" href="{{ $discordUrl }}" target="_blank" rel="noopener noreferrer">
                    <img src="data:image/png;base64,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" alt="" width="22" height="17" aria-hidden="true">
                    Join our Discord
                </a>
            @endif
    @else
            <p class="lead">
                @if ($fields['password'] === 'required')
                    Fill in your details to get started.
                @elseif ($fields['password'] === 'optional')
                    Choose a password now, or leave it blank and we email you a link to verify your address and choose one.
                @else
                    No password needed now. We email you a link to verify your address, then another to choose a password.
                @endif
            </p>

            <div id="notice" class="notice" role="status" aria-live="polite" hidden></div>

            @if ($turnstileEnabled && ! $turnstileConfigured)
                <div class="notice danger" role="alert">Registration security verification is temporarily unavailable. Please contact an administrator.</div>
            @endif

            @if ($ssoError)
                <div class="notice danger" role="alert">{{ $ssoError }}</div>
            @endif

            @if (count($sso) > 0)
                <div id="sso-area">
                <div class="sso-stack">
                    @foreach ($sso as $button)
                        <a class="btn btn-{{ $button['key'] }}" href="{{ $button['url'] }}" @if ($turnstileEnabled) data-turnstile-register-url="{{ $button['turnstile_url'] }}" @endif>
                            @if ($button['key'] === 'google')
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M23.49 12.27c0-.79-.07-1.54-.19-2.27H12v4.51h6.47c-.29 1.48-1.14 2.73-2.4 3.58v3h3.86c2.26-2.09 3.56-5.17 3.56-8.82z"/><path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.86-3c-1.08.72-2.45 1.16-4.07 1.16-3.13 0-5.78-2.11-6.73-4.96h-3.98v3.09C3.15 21.3 7.26 24 12 24z"/><path fill="#FBBC05" d="M5.27 14.29c-.25-.72-.38-1.49-.38-2.29s.14-1.57.38-2.29V6.62h-3.98a11.86 11.86 0 0 0 0 10.76l3.98-3.09z"/><path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.26 0 3.15 2.7 1.29 6.62l3.98 3.09C6.22 6.86 8.87 4.75 12 4.75z"/></svg>
                            @else
                                <img src="data:image/png;base64,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" alt="" width="22" height="17" aria-hidden="true">
                            @endif
                            Register with {{ $button['label'] }}
                        </a>
                    @endforeach
                </div>
                <div class="divider sso-divider">or use your email</div>
                </div>
            @endif

            <form id="form" novalidate>
                {{-- Required providers: the visitor proves they own the account before the rest of the form. --}}
                @foreach ($sso as $button)
                    @if ($button['mode'] === 'required')
                        <div class="field">
                            <label>{{ $button['label'] }} account</label>
                            @if ($button['connected'])
                                <div class="connected">
                                    <span>&#10003; {{ $button['label'] }} connected @if ($button['connected_email'] !== '') &middot; {{ $button['connected_email'] }}@endif</span>
                                    <a href="{{ $button['connect_url'] }}">Change</a>
                                </div>
                            @else
                                <a class="btn btn-{{ $button['key'] }} btn-connect" href="{{ $button['connect_url'] }}">
                                    @if ($button['key'] === 'google')
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M23.49 12.27c0-.79-.07-1.54-.19-2.27H12v4.51h6.47c-.29 1.48-1.14 2.73-2.4 3.58v3h3.86c2.26-2.09 3.56-5.17 3.56-8.82z"/><path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.86-3c-1.08.72-2.45 1.16-4.07 1.16-3.13 0-5.78-2.11-6.73-4.96h-3.98v3.09C3.15 21.3 7.26 24 12 24z"/><path fill="#FBBC05" d="M5.27 14.29c-.25-.72-.38-1.49-.38-2.29s.14-1.57.38-2.29V6.62h-3.98a11.86 11.86 0 0 0 0 10.76l3.98-3.09z"/><path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.26 0 3.15 2.7 1.29 6.62l3.98 3.09C6.22 6.86 8.87 4.75 12 4.75z"/></svg>
                                    @else
                                        <img src="data:image/png;base64,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" alt="" width="22" height="17" aria-hidden="true">
                                    @endif
                                    Connect {{ $button['label'] }} (required)
                                </a>
                            @endif
                            <p class="error" data-error-for="sso_{{ $button['key'] }}" role="alert" hidden></p>
                        </div>
                    @endif
                @endforeach

                @if ($fields['username'] !== 'hidden')
                    <div class="field">
                        <label for="username">Username @if ($fields['username'] === 'optional') <span class="opt">(optional)</span>@endif</label>
                        <input id="username" name="username" type="text" autocomplete="username" value="{{ $prefill['username'] }}" @if ($fields['username'] === 'required') required @endif>
                        <p class="error" data-error-for="username" role="alert" hidden></p>
                    </div>
                @endif
                <div class="field">
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" autocomplete="email" value="{{ $prefill['email'] }}" required>
                    <p class="error" data-error-for="email" role="alert" hidden></p>
                </div>
                @if ($fields['first_name'] !== 'hidden' || $fields['last_name'] !== 'hidden')
                    <div class="row @if ($fields['first_name'] === 'hidden' || $fields['last_name'] === 'hidden') single @endif">
                        @if ($fields['first_name'] !== 'hidden')
                            <div class="field">
                                <label for="name_first">First name @if ($fields['first_name'] === 'optional') <span class="opt">(optional)</span>@endif</label>
                                <input id="name_first" name="name_first" type="text" autocomplete="given-name" value="{{ $prefill['first'] }}" @if ($fields['first_name'] === 'required') required @endif>
                                <p class="error" data-error-for="name_first" role="alert" hidden></p>
                            </div>
                        @endif
                        @if ($fields['last_name'] !== 'hidden')
                            <div class="field">
                                <label for="name_last">Last name @if ($fields['last_name'] === 'optional') <span class="opt">(optional)</span>@endif</label>
                                <input id="name_last" name="name_last" type="text" autocomplete="family-name" value="{{ $prefill['last'] }}" @if ($fields['last_name'] === 'required') required @endif>
                                <p class="error" data-error-for="name_last" role="alert" hidden></p>
                            </div>
                        @endif
                    </div>
                @endif
                @if ($fields['company'] !== 'hidden')
                    <div class="field">
                        <label for="company">Company name @if ($fields['company'] === 'optional') <span class="opt">(optional)</span>@endif</label>
                        <input id="company" name="company" type="text" autocomplete="organization" maxlength="191" @if ($fields['company'] === 'required') required @endif>
                        <p class="error" data-error-for="company" role="alert" hidden></p>
                    </div>
                @endif
                @if ($fields['phone'] !== 'hidden')
                    <div class="field">
                        <label for="phone">Phone number @if ($fields['phone'] === 'optional') <span class="opt">(optional)</span>@endif</label>
                        <input id="phone" name="phone" type="tel" autocomplete="tel" maxlength="32" @if ($fields['phone'] === 'required') required @endif>
                        <p class="error" data-error-for="phone" role="alert" hidden></p>
                    </div>
                @endif
                @if ($fields['password'] !== 'hidden')
                    <div class="field">
                        <label for="password">Password @if ($fields['password'] === 'optional') <span class="opt">(optional)</span>@endif</label>
                        <input id="password" name="password" type="password" autocomplete="new-password" @if ($fields['password'] === 'required') required @endif>
                        <p class="error" data-error-for="password" role="alert" hidden></p>
                    </div>
                    @if ($fields['password_confirm'] !== 'hidden')
                        <div class="field">
                            <label for="password_confirmation">Retype password @if ($fields['password_confirm'] === 'optional') <span class="opt">(optional)</span>@endif</label>
                            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" @if ($fields['password_confirm'] === 'required') required @endif>
                        </div>
                    @endif
                @endif
                @if ($turnstileEnabled && $turnstileConfigured)
                    <div class="field">
                        <div id="turnstile-widget" class="cf-turnstile" data-sitekey="{{ $turnstileSiteKey }}" data-action="register" data-theme="dark"></div>
                        <p class="error" data-error-for="cf-turnstile-response" role="alert" hidden></p>
                    </div>
                @endif
                <button id="submit" class="btn btn-primary" type="submit" @if ($turnstileEnabled && ! $turnstileConfigured) disabled @endif>Register</button>
            </form>

            @if ($showDiscord)
            <div class="divider">or</div>
            <a class="btn btn-discord" href="{{ $discordUrl }}" target="_blank" rel="noopener noreferrer">
                    <img src="data:image/png;base64,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" alt="" width="22" height="17" aria-hidden="true">
                    Join our Discord
                </a>
            @endif

            <p class="footer"><a href="/auth/login">Already have an account? Sign in</a></p>
            <noscript><p class="notice danger">JavaScript is required to create an account.</p></noscript>

        <script>
            (function () {
                var endpoint = @json($endpoint);
                var form = document.getElementById('form');
                var button = document.getElementById('submit');
                var notice = document.getElementById('notice');

                function showNotice(kind, text) {
                    notice.className = 'notice ' + kind;
                    notice.textContent = text;
                    notice.hidden = false;
                }

                function clearErrors() {
                    notice.hidden = true;
                    form.querySelectorAll('[data-error-for]').forEach(function (el) { el.hidden = true; el.textContent = ''; });
                    form.querySelectorAll('input').forEach(function (el) { el.removeAttribute('aria-invalid'); });
                }

                // The Panel answers 422 with { errors: [{ detail, meta: { source_field } }] };
                // plain Laravel uses { errors: { field: [message] } }. Handle both.
                function fieldErrors(body) {
                    var out = {};
                    var errors = body && body.errors;
                    if (Array.isArray(errors)) {
                        errors.forEach(function (item) {
                            var field = item && item.meta && item.meta.source_field;
                            if (field && item.detail && !out[field]) out[field] = item.detail;
                        });
                    } else if (errors && typeof errors === 'object') {
                        Object.keys(errors).forEach(function (field) {
                            if (errors[field] && errors[field][0]) out[field] = errors[field][0];
                        });
                    }
                    return out;
                }

                function cookie(name) {
                    var match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
                    return match ? decodeURIComponent(match[1]) : '';
                }

                function resetTurnstile() {
                    if (window.turnstile && document.getElementById('turnstile-widget')) {
                        window.turnstile.reset();
                    }
                }

                document.querySelectorAll('[data-turnstile-register-url]').forEach(function (link) {
                    link.addEventListener('click', function (event) {
                        event.preventDefault();
                        if (link.getAttribute('aria-disabled') === 'true') return;
                        var token = form.elements['cf-turnstile-response'];
                        if (!token || !token.value) {
                            showNotice('danger', 'Complete the Cloudflare security check before continuing.');
                            return;
                        }

                        link.setAttribute('aria-disabled', 'true');
                        fetch('/sanctum/csrf-cookie', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                            .then(function () {
                                return fetch(link.dataset.turnstileRegisterUrl, {
                                    method: 'POST',
                                    credentials: 'same-origin',
                                    headers: {
                                        'Accept': 'application/json',
                                        'Content-Type': 'application/json',
                                        'X-Requested-With': 'XMLHttpRequest',
                                        'X-XSRF-TOKEN': cookie('XSRF-TOKEN')
                                    },
                                    body: JSON.stringify({ 'cf-turnstile-response': token.value })
                                });
                            })
                            .then(function (response) {
                                return response.json().catch(function () { return {}; }).then(function (body) {
                                    return { ok: response.ok, body: body };
                                });
                            })
                            .then(function (result) {
                                if (result.ok && typeof result.body.redirect_url === 'string') {
                                    window.location.assign(result.body.redirect_url);
                                    return;
                                }
                                var errors = fieldErrors(result.body);
                                showNotice('danger', errors['cf-turnstile-response'] || 'Could not verify the security check. Please try again.');
                                resetTurnstile();
                            })
                            .catch(function () {
                                showNotice('danger', 'Could not reach the security verification service. Please try again.');
                                resetTurnstile();
                            })
                            .then(function () { link.removeAttribute('aria-disabled'); });
                    });
                });

                form.addEventListener('submit', function (event) {
                    event.preventDefault();
                    clearErrors();
                    button.disabled = true;
                    button.textContent = 'Creating account…';

                    // Only fields that are on the form are sent; the server decides what is required.
                    var payload = {};
                    ['username', 'email', 'name_first', 'name_last', 'company', 'phone', 'password', 'password_confirmation'].forEach(function (name) {
                        if (form.elements[name]) payload[name] = form.elements[name].value;
                    });
                    if (form.elements['cf-turnstile-response']) {
                        payload['cf-turnstile-response'] = form.elements['cf-turnstile-response'].value;
                    }

                    // Same handshake the Panel's own login uses: set the CSRF cookie, then send it back.
                    fetch('/sanctum/csrf-cookie', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                        .then(function () {
                            return fetch(endpoint, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: {
                                    'Accept': 'application/json',
                                    'Content-Type': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                    'X-XSRF-TOKEN': cookie('XSRF-TOKEN')
                                },
                                body: JSON.stringify(payload)
                            });
                        })
                        .then(function (response) {
                            return response.json().catch(function () { return {}; }).then(function (body) {
                                return { ok: response.ok, status: response.status, body: body };
                            });
                        })
                        .then(function (result) {
                            if (result.ok) {
                                form.hidden = true;
                                var ssoArea = document.getElementById('sso-area');
                                if (ssoArea) ssoArea.hidden = true;
                                showNotice('success', result.body.status === 'created'
                                    ? 'Account created. You can sign in now.'
                                    : 'Check your email and open the verification link to finish creating your account.');
                                return;
                            }

                            var errors = result.status === 422 ? fieldErrors(result.body) : {};
                            resetTurnstile();
                            var fields = Object.keys(errors);
                            if (fields.length === 0) {
                                showNotice('danger', result.status === 429
                                    ? 'Too many attempts. Please wait a minute and try again.'
                                    : 'Something went wrong. Please try again.');
                                return;
                            }
                            var unplaced = [];
                            fields.forEach(function (field) {
                                var message = form.querySelector('[data-error-for="' + field + '"]');
                                var input = form.elements[field];
                                if (message) { message.textContent = errors[field]; message.hidden = false; }
                                else unplaced.push(errors[field]);
                                if (input) input.setAttribute('aria-invalid', 'true');
                            });
                            // An error with no field of its own on this form must still be seen.
                            if (unplaced.length > 0) showNotice('danger', unplaced.join(' '));
                        })
                        .catch(function () {
                            showNotice('danger', 'Could not reach the server. Please try again.');
                            resetTurnstile();
                        })
                        .then(function () { button.disabled = false; button.textContent = 'Register'; });
                });
            })();
        </script>
    @endif
        </div>
    </main>
    <p class="copyright">&copy; {{ date('Y') }} {{ config('app.name') }}</p>
</div>
</body>
</html>
