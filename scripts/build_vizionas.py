#!/usr/bin/env python3

import io
import json
import tempfile
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BUNDLE = ROOT / "vizion-suite-bundle.zip"
OUTPUT = ROOT / "vizionas.pteroext"
SOURCE = ROOT / "vizionas" / "source"
PREFIX_RENAMES = {"demo": ("dm", "dmo"), "mcp": ("mcp", "mcpai")}
PACKAGE_VERSIONS = {
    "demo": "1.0.1",
    "drift-register": "1.6.3",
    "drift-mod": "1.1.1",
    "drift-plugins": "1.5.1",
    "drift-properties": "1.0.1",
    "mcp": "1.1.1",
}
PACKAGE_NAMES = {
    "drift-register": "Register",
    "drift-mod": "Mod",
    "drift-plugins": "Plugins",
    "drift-properties": "Properties",
}


def rewrite_extension(
    package: bytes,
    old_prefix: str | None,
    new_prefix: str | None,
    version: str | None = None,
    name: str | None = None,
) -> bytes:
    source = zipfile.ZipFile(io.BytesIO(package))
    result = io.BytesIO()

    with zipfile.ZipFile(result, "w", zipfile.ZIP_DEFLATED) as target:
        for item in source.infolist():
            contents = source.read(item.filename)
            if item.filename == "extension.json":
                manifest = json.loads(contents)
                if old_prefix is not None:
                    manifest["ui"]["prefix"] = new_prefix
                if version is not None:
                    manifest["version"] = version
                if name is not None:
                    manifest["name"] = name
                contents = json.dumps(manifest, indent=4, ensure_ascii=False).encode() + b"\n"
            elif old_prefix is not None and item.filename.startswith(("dist/", "src/")):
                contents = contents.replace(f"{old_prefix}:".encode(), f"{new_prefix}:".encode())
                contents = contents.replace(
                    f".{old_prefix}\\:".encode(), f".{new_prefix}\\:".encode()
                )
                contents = contents.replace(
                    f"--{old_prefix}-".encode(), f"--{new_prefix}-".encode()
                )
                contents = contents.replace(
                    f"prefix({old_prefix})".encode(), f"prefix({new_prefix})".encode()
                )
            target.writestr(item, contents)

    return result.getvalue()


def main() -> None:
    with zipfile.ZipFile(BUNDLE) as bundle:
        packages = []
        seen_ids = set()
        for name in bundle.namelist():
            if not name.startswith("extensions/") or not name.endswith(".pteroext"):
                continue

            package = bundle.read(name)
            with zipfile.ZipFile(io.BytesIO(package)) as archive:
                manifest = json.loads(archive.read("extension.json"))
            identifier = manifest["id"]
            if identifier.lower() == "branding":
                raise ValueError("The Branding package cannot be redistributed.")
            if identifier in seen_ids:
                raise ValueError(f"Duplicate bundled extension id: {identifier}")
            seen_ids.add(identifier)

            old_new = PREFIX_RENAMES.get(identifier)
            if old_new is not None and manifest["ui"].get("prefix") != old_new[0]:
                raise ValueError(f"Unexpected Tailwind prefix for {identifier}")
            package = rewrite_extension(
                package,
                *(old_new or (None, None)),
                version=PACKAGE_VERSIONS.get(identifier),
                name=PACKAGE_NAMES.get(identifier),
            )
            with zipfile.ZipFile(io.BytesIO(package)) as rewritten:
                manifest = json.loads(rewritten.read("extension.json"))
                if old_new is not None:
                    for asset in rewritten.namelist():
                        if not asset.startswith("dist/"):
                            continue
                        contents = rewritten.read(asset)
                        if (
                            f".{old_new[0]}\\:".encode() in contents
                            or f"--{old_new[0]}-".encode() in contents
                            or f"prefix({old_new[0]})".encode() in contents
                            or f"{old_new[0]}:".encode() in contents
                        ):
                            raise ValueError(
                                f"Unprefixed Tailwind utility or theme variable remains in {identifier}/{asset}"
                            )
            packages.append(
                {
                    "id": identifier,
                    "name": manifest["name"],
                    "version": manifest["version"],
                    "file": Path(name).name,
                    "package": package,
                }
            )

        if len(packages) != 23:
            raise ValueError(f"Expected 23 redistributable packages, found {len(packages)}")

        prefixes = {}
        for package in packages:
            with zipfile.ZipFile(io.BytesIO(package["package"])) as archive:
                manifest = json.loads(archive.read("extension.json"))
            prefix = manifest.get("ui", {}).get("prefix")
            if prefix:
                if prefix in prefixes:
                    raise ValueError(
                        f"Duplicate UI prefix {prefix}: {prefixes[prefix]} and {package['id']}"
                    )
                prefixes[prefix] = package["id"]

        base_theme = bundle.read("vizion.pteroext")

    with tempfile.TemporaryDirectory() as temporary:
        theme_path = Path(temporary) / "vizion.pteroext"
        with zipfile.ZipFile(io.BytesIO(base_theme)) as original:
            entries = {item.filename: (item, original.read(item.filename)) for item in original.infolist()}

        manifest = json.loads(entries["extension.json"][1])
        manifest["version"] = "1.1.5"
        manifest["ui"]["screens"].append(
            {
                "id": "extensions",
                "area": "admin",
                "path": "vizion-extensions",
                "nav": {"label": "Vizion Extension", "order": 2, "icon": "puzzle"},
            }
        )
        entries["extension.json"] = (
            entries["extension.json"][0],
            json.dumps(manifest, indent=4, ensure_ascii=False).encode() + b"\n",
        )

        provider_info, provider = entries["php/Provider.php"]
        boot = "$this->registerHeadTags(fn (): array => $this->headTags());"
        if boot not in provider.decode():
            raise ValueError("Could not locate Vizion provider boot method.")
        provider = provider.decode().replace(
            boot,
            "$this->registerApiRoutes();\n        " + boot,
            1,
        ).encode()
        entries["php/Provider.php"] = (provider_info, provider)

        client_info, client = entries["dist/client.js"]
        screen_registration = (
            "t.register(`settings`,()=>A(()=>import(`./chunk.VizionSettingsPage.CBuhquzc.js`),[],import.meta.url))"
        )
        if client.count(screen_registration.encode()) != 1:
            raise ValueError("Could not locate Vizion settings screen registration.")
        client = client.replace(
            screen_registration.encode(),
            (
                screen_registration
                + ";t.register(`extensions`,()=>A(()=>import(`./chunk.VizionExtensionHub.js`),[],import.meta.url))"
            ).encode(),
            1,
        )
        entries["dist/client.js"] = (client_info, client)

        settings_info, settings = entries["dist/chunk.VizionSettingsPage.CBuhquzc.js"]
        settings = settings.decode()
        return_admin = (
            "})]}),u(`section`,{className:`min-w-0 space-y-6 p-4 sm:p-6 "
            "xl:max-h-dvh xl:overflow-y-auto xl:border-r xl:border-border`"
        )
        extension_link = (
            ",u(`a`,{href:`${window.location.pathname.split(`/panel/`)[0]}/panel/vizion-extensions`,"
            "title:`Vizion Extension`,className:`flex shrink-0 flex-col items-center justify-center "
            "gap-1 rounded-xl px-2 py-2 text-center text-xs leading-tight text-muted-foreground "
            "transition hover:bg-popover hover:text-foreground`,children:`Vizion Extension`})"
        )
        if settings.count(return_admin) != 1:
            raise ValueError("Could not locate Vizion settings sidebar.")
        link_position = settings.index(return_admin) + 2
        settings = settings[:link_position] + extension_link + settings[link_position:]

        old_preview = (
            "className:`flex min-h-[65dvh] flex-1 justify-center overflow-hidden rounded-2xl "
            "border border-border/70 bg-background/40 p-3 sm:p-6 xl:min-h-0`,children:l(`div`,"
            "{className:`h-full min-h-[58dvh] w-full overflow-hidden rounded-xl border "
            "border-border/70 shadow-2xl transition-[max-width] duration-300 xl:min-h-0 "
            "${b===`mobile`?`max-w-[420px]`:`max-w-none`}`,children:l(`iframe`,{ref:J,"
            "title:`Live Vizion dashboard preview`,src:`/?vizionPreview=1`,onLoad:Z,"
            "className:`block h-full w-full border-0 bg-background`})})"
        )
        new_preview = (
            "className:`flex min-h-[65dvh] flex-1 justify-center overflow-hidden rounded-2xl "
            "border border-border/70 bg-background/40 p-3 sm:p-6 xl:min-h-0`,"
            "style:{height:`72dvh`,minHeight:`560px`},children:l(`div`,"
            "{className:`h-full min-h-[58dvh] w-full overflow-hidden rounded-xl border "
            "border-border/70 shadow-2xl transition-[max-width] duration-300 xl:min-h-0 "
            "${b===`mobile`?`max-w-[420px]`:`max-w-none`}`,children:l(`iframe`,{ref:J,"
            "title:`Live Vizion dashboard preview`,"
            "src:`${window.location.pathname.split(`/panel/`)[0]}/?vizionPreview=1`,onLoad:Z,"
            "className:`block h-full w-full border-0 bg-background`,style:{"
            "transform:`scale(.75)`,transformOrigin:`top left`,width:`133.333%`,height:`133.333%`}})})"
        )
        if settings.count(old_preview) != 1:
            raise ValueError("Could not locate Vizion live preview iframe.")
        settings = settings.replace(old_preview, new_preview, 1)
        entries["dist/chunk.VizionSettingsPage.CBuhquzc.js"] = (settings_info, settings.encode())

        with zipfile.ZipFile(theme_path, "w", zipfile.ZIP_DEFLATED) as target:
            for item, contents in entries.values():
                target.writestr(item, contents)
            for path in SOURCE.rglob("*"):
                if path.is_file():
                    target.write(path, path.relative_to(SOURCE).as_posix())

            index = [
                {key: package[key] for key in ("id", "name", "version", "file")}
                for package in packages
            ]
            target.writestr(
                "resources/packages/index.json",
                json.dumps(index, indent=2, ensure_ascii=False).encode() + b"\n",
            )
            for package in packages:
                target.writestr(
                    f"resources/packages/{package['file']}",
                    package["package"],
                )

        OUTPUT.write_bytes(theme_path.read_bytes())

    print(f"Built {OUTPUT.name} with {len(packages)} native extensions.")


if __name__ == "__main__":
    main()
