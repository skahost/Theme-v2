# Vizion Suite Packages

`vizion-suite-bundle.zip` is the earlier multi-package release. The current
single-upload package is [`vizionas.pteroext`](../vizionas.pteroext), which
contains the Vizion theme and its native extension manager.

After installing and enabling `vizionas.pteroext`, open **Vizion Extension** in
the admin menu. Installation or updates happen only after clicking **Install /
update all** or an individual **Install** button; opening the page does not
reinstall extensions that were removed. The hub also provides enable, disable,
configure, and remove controls. **Configure** opens the selected extension's
settings inline in the Vizion Extension hub.

The hub's **Enable Vizion + all extensions** master action installs or updates
the bundled packages, enables them in dependency order, and activates Vizion.
**Turn Vizion + extensions off** switches to Classic first, then disables the
bundled extensions. Vizion's extension stays enabled while the master switch is
off so the hub remains available to turn it back on. These coordinated actions
are available from the Vizion Extension hub; changing the active theme directly
in Pterodactyl's native Themes manager does not trigger the bundled extensions'
master switch.
The bundled extensions are filtered out of the global Admin → Extensions list;
their settings and management remain available from the Vizion Extension hub.
The theme editor also includes server-rendered Open Graph, Twitter, favicon, and
theme-color tags, an indexing switch, configurable social links, an optional live
Discord widget, and announcement/CTA controls. Its dashboard-style preview is
rendered locally at a reduced scale rather than loaded through an iframe.

The package includes 23 native extensions from the supplied `Telegram.zip`,
including Register 1.6.3 with Cloudflare Turnstile. The four Drift extensions
are displayed as Register, Mod, Plugins, and Properties; their internal
identifiers remain unchanged so existing routes and settings continue working.
Their routes, permissions, migrations, and settings remain separate native
extension packages. Two colliding Tailwind prefixes were made unique so those
packages can be enabled together.

The Branding extension is not redistributed because its package license
prohibits redistribution.

Rebuild the single-upload package with `python3 scripts/build_vizionas.py`.

The Panel's Laravel file cache also requires `storage/framework/cache/data` to
exist and be writable by the PHP/web-server user. A `file_put_contents` error
under that directory is a Panel filesystem-permissions/setup issue, not a
missing file in this extension package.
