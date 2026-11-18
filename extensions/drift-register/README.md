# Drift Register

This package is a native Pterodactyl v2 extension. It keeps its registration, SSO,
and profile logic in the extension backend; it is not merged into the Vizion theme.

## Cloudflare Turnstile

Turnstile is optional and is disabled by default. Configure it under **Admin →
Extensions → Drift Register → Settings**:

1. Enable **Protect registration with Cloudflare Turnstile**.
2. Set the Turnstile site key and secret key from the Cloudflare widget.
3. Keep the existing panel Google reCAPTCHA configuration unchanged; it continues
   to apply to the panel's own authentication routes.

Registration form posts and SSO registration starts are verified server-side with
Cloudflare's Siteverify API. Tokens are checked for a successful result, the current
host, and the `register` action. The secret is stored as a secret extension setting
and is never sent to the browser. Failed verification blocks account creation.
If the panel uses a restrictive Content Security Policy, allow
`challenges.cloudflare.com` for scripts, frames, and connections.
