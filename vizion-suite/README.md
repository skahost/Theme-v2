# Vizion Suite Packages

`vizion-suite-bundle.zip` is the earlier multi-package release. The current
single-upload package is [`vizionas.pteroext`](../vizionas.pteroext), which
contains the Vizion theme and its native extension manager.

After installing and enabling `vizionas.pteroext`, open **Vizion Extension** in
the admin menu. Its first load installs or updates the bundled extensions.
They remain disabled until enabled individually. **Configure** opens
Pterodactyl's native Extensions manager for each extension's settings.

The package includes 23 native extensions from the supplied `Telegram.zip`,
including Drift Register 1.6.2 with Cloudflare Turnstile. Their routes,
permissions, migrations, and settings remain separate native extension
packages. Two colliding Tailwind prefixes were made unique so those packages
can be enabled together.

The Branding extension is not redistributed because its package license
prohibits redistribution.

Rebuild the single-upload package with `python3 scripts/build_vizionas.py`.

The Panel's Laravel file cache also requires `storage/framework/cache/data` to
exist and be writable by the PHP/web-server user. A `file_put_contents` error
under that directory is a Panel filesystem-permissions/setup issue, not a
missing file in this extension package.
