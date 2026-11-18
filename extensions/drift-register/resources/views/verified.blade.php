<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ config('app.name') }}</title>
    <style>
        :root { color-scheme: light dark; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: system-ui, sans-serif; background: Canvas; color: CanvasText; }
        main { max-width: 28rem; margin: 1rem; padding: 2rem; border: 1px solid color-mix(in srgb, CanvasText 20%, transparent); border-radius: .75rem; }
        h1 { margin: 0 0 .75rem; font-size: 1.25rem; }
        p { margin: 0 0 1rem; line-height: 1.5; }
        a { display: inline-block; padding: .5rem 1rem; border-radius: .5rem; background: CanvasText; color: Canvas; text-decoration: none; }
    </style>
</head>
<body>
<main>
    @if ($state === 'verified')
        <h1>Email verified</h1>
        <p>Your account has been created. We just emailed you a link to choose your password. If it does not arrive, use "Forgot password" on the login page.</p>
    @elseif ($state === 'closed')
        <h1>Registration is closed</h1>
        <p>New sign-ups are currently disabled.</p>
    @else
        <h1>This link is no longer valid</h1>
        <p>It may have expired or already been used. If you already verified your email, your account exists: check your inbox for the link to choose a password, or use "Forgot password" on the login page. Otherwise, try registering again.</p>
    @endif
    <a href="/auth/login">Go to login</a>
</main>
</body>
</html>
