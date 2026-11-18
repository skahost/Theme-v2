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


def rewrite_extension(package: bytes, old_prefix: str | None, new_prefix: str | None) -> bytes:
    source = zipfile.ZipFile(io.BytesIO(package))
    result = io.BytesIO()

    with zipfile.ZipFile(result, "w", zipfile.ZIP_DEFLATED) as target:
        for item in source.infolist():
            contents = source.read(item.filename)
            if item.filename == "extension.json":
                manifest = json.loads(contents)
                if old_prefix is not None:
                    manifest["ui"]["prefix"] = new_prefix
                contents = json.dumps(manifest, indent=4, ensure_ascii=False).encode() + b"\n"
            elif old_prefix is not None and item.filename.startswith("dist/"):
                contents = contents.replace(f"{old_prefix}:".encode(), f"{new_prefix}:".encode())
                contents = contents.replace(
                    f".{old_prefix}\\\\:".encode(), f".{new_prefix}\\\\:".encode()
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
            package = rewrite_extension(package, *(old_new or (None, None)))
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
        manifest["version"] = "1.1.0"
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
