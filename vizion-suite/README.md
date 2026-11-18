# Vizion Suite Bundle

This bundle contains the current Vizion theme and separately installable native
Pterodactyl extensions:

- `vizion.pteroext` is the current Vizion theme package.
- `extensions/*.pteroext` are independent extension packages from the supplied
  `Telegram.zip`. Drift Register is updated to version 1.6.2 with Cloudflare
  Turnstile support.

Install Vizion and each extension separately through the panel's extension
manager. Each extension retains its own settings, permissions, routes, and
database migrations; installing this ZIP does not install them automatically.

The Branding extension is not redistributed here because its package license
prohibits redistribution. The other supplied extension packages are included.

The Vizion theme package is unchanged in this bundle. Its React/TypeScript
sources were not supplied, so its compiled settings UI and navigation could not
be safely extended with a feature hub or additional editor controls.
