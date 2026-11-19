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
                for screen in manifest.get("ui", {}).get("screens", []):
                    nav = screen.get("nav")
                    label = nav.get("label") if isinstance(nav, dict) else None
                    if isinstance(label, str) and label.lower().startswith("drift "):
                        nav["label"] = label[6:]
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
            elif item.filename.startswith("dist/") and item.filename.endswith(".js"):
                for visible_name in (
                    b"Drift Register",
                    b"Drift Mods",
                    b"Drift Mod",
                    b"Drift Plugins",
                    b"Drift Properties",
                    b"Drift Development",
                ):
                    contents = contents.replace(visible_name, visible_name.replace(b"Drift ", b""))
                contents = contents.replace(b"drift-register sections", b"Register sections")
            target.writestr(item, contents)

    return result.getvalue()


def patch_vizion_provider(provider: bytes) -> bytes:
    source = provider.decode()
    reset_route_marker = (
        "    private function invalidateHeadTags(mixed $value): mixed\n"
        "    {\n"
    )
    reset_route_methods = (
        "    protected function registerApiRoutes(?string $directory = null): void\n"
        "    {\n"
        "        parent::registerApiRoutes($directory);\n"
        "        $this->app['router']->post('/api/admin/extensions/vizion/reset', "
        "[\\Vizion\\Http\\Controllers\\BundleController::class, 'reset']);\n"
        "    }\n\n"
    )
    if source.count(reset_route_marker) != 1:
        raise ValueError("Could not locate Vizion Provider reset route insertion point.")
    source = source.replace(reset_route_marker, reset_route_methods + reset_route_marker, 1)

    marker = (
        "ExtensionSettingDefinition::make('metaDescription', 'metaDescription', '')"
        "\n                ->textarea(180)->normalizeUsing($this->invalidateHeadTags(...))"
        "->label('Meta Description')->tab('SEO & Social'),"
    )
    additional_settings = """
            ExtensionSettingDefinition::make('allowIndexing', 'allowIndexing', false)
                ->field('toggle')->label('Allow to be indexed')
                ->help('Allow search engines to index the panel and show it in search results.')
                ->normalizeUsing($this->invalidateHeadTags(...))->tab('SEO & Social'),
            ExtensionSettingDefinition::make('metaThemeColor', 'metaThemeColor', '#101114')
                ->color()->normalizeUsing($this->invalidateHeadTags(...))
                ->label('Meta Color')->tab('SEO & Social'),
            ExtensionSettingDefinition::make('baseColor', 'baseColor', '#21163a')
                ->color()->frontend()->public()->label('Base/Card Background Color')->tab('Appearance'),
            ExtensionSettingDefinition::make('socialLinks', 'socialLinks', [])
                ->list(['max:2048'], 16)->frontend()->public()
                ->label('Social Buttons')->tab('Social & Logins'),
            ExtensionSettingDefinition::make('discordEnabled', 'discordEnabled', false)
                ->field('toggle')->frontend()->public()->label('Show Discord Box')->tab('Social & Logins'),
            ExtensionSettingDefinition::make('discordGuildId', 'discordGuildId', '', ['nullable', 'string', 'regex:/^[0-9]{1,24}$/'])
                ->field('text')->frontend()->public()->label('Discord Guild ID')->tab('Social & Logins'),
            ExtensionSettingDefinition::make('discordInvite', 'discordInvite', '', ['nullable', 'url', 'max:2048'])
                ->field('text')->frontend()->public()->label('Discord Invite Link')->tab('Social & Logins'),
            ExtensionSettingDefinition::make('announcementPosition', 'announcementPosition', 'top')
                ->field('select', [['value' => 'header', 'label' => 'Header'], ['value' => 'top', 'label' => 'Top']])
                ->frontend()->public()->label('Position')->tab('Dashboard'),
            ExtensionSettingDefinition::make('announcementColor', 'announcementColor', '#21163a')
                ->color()->frontend()->public()->label('Announcement Background Color')->tab('Dashboard'),
            ExtensionSettingDefinition::make('announcementIcon', 'announcementIcon', 'megaphone')
                ->field('select', [['value' => 'megaphone', 'label' => 'Megaphone'], ['value' => 'sparkles', 'label' => 'Sparkles'], ['value' => 'info', 'label' => 'Info'], ['value' => 'none', 'label' => 'None']])
                ->frontend()->public()->label('Announcement Icon')->tab('Dashboard'),
            ExtensionSettingDefinition::make('announcementDismissible', 'announcementDismissible', true)
                ->field('toggle')->frontend()->public()->label('Dismissable')->tab('Dashboard'),
            ExtensionSettingDefinition::make('ctaColor', 'ctaColor', '#8b5cf6')
                ->color()->frontend()->public()->label('Call to Action Color')->tab('Dashboard'),
            ExtensionSettingDefinition::make('ctaTitle', 'ctaTitle', '', ['nullable', 'string', 'max:50'])
                ->field('text')->frontend()->public()->label('CTA Button Title')->tab('Dashboard'),
            ExtensionSettingDefinition::make('ctaUrl', 'ctaUrl', '', ['nullable', 'url', 'max:2048'])
                ->field('text')->frontend()->public()->label('CTA Button URL')->tab('Dashboard'),
"""
    if source.count(marker) != 1:
        raise ValueError("Could not locate Vizion SEO setting definitions.")
    source = source.replace(marker, marker + additional_settings, 1)

    banner_marker = "$banner = $settings->get('seoBanner');"
    if source.count(banner_marker) != 1:
        raise ValueError("Could not locate Vizion SEO banner setting.")
    source = source.replace(
        banner_marker,
        banner_marker + "\n        $themeColor = (string) $settings->get('metaThemeColor', '#101114');",
        1,
    )

    tags_marker = "['tag' => 'meta', 'property' => 'og:title', 'content' => $title],"
    if source.count(tags_marker) != 1:
        raise ValueError("Could not locate Vizion Open Graph title tag.")
    source = source.replace(
        tags_marker,
        tags_marker
        + "\n            ['tag' => 'meta', 'name' => 'theme-color', 'content' => $themeColor],",
        1,
    )

    tags_end = (
        "            ['tag' => 'meta', 'name' => 'twitter:title', 'content' => $title],\n"
        "        ];\n\n        if ($description !== '') {"
    )
    gated_tags_end = (
        "            ['tag' => 'meta', 'name' => 'twitter:title', 'content' => $title],\n"
        "        ];\n\n"
        "        if ($settings->get('allowIndexing', false) !== true) {\n"
        "            return [];\n"
        "        }\n\n"
        "        if ($description !== '') {"
    )
    if source.count(tags_end) != 1:
        raise ValueError("Could not locate Vizion SEO tag list end.")
    source = source.replace(tags_end, gated_tags_end, 1)

    description_tag = "['tag' => 'meta', 'property' => 'og:description', 'content' => $description]"
    if source.count(description_tag) != 1:
        raise ValueError("Could not locate the existing Open Graph description tag.")

    return source.encode()


def patch_theme_settings_store(settings: bytes) -> bytes:
    source = settings.decode()
    default_marker = "announcementEnabled:!1,glassmorphism:!0"
    default_settings = (
        "announcementEnabled:!1,announcementPosition:`top`,announcementColor:`#21163a`,"
        "announcementIcon:`megaphone`,announcementDismissible:!0,ctaColor:`#8b5cf6`,"
        "ctaTitle:``,ctaUrl:``,socialLinks:[],discordEnabled:!1,discordGuildId:``,"
        "discordInvite:``,metaThemeColor:`#101114`,baseColor:`#21163a`,glassmorphism:!0"
    )
    if source.count(default_marker) != 1:
        raise ValueError("Could not locate Vizion frontend settings defaults.")
    source = source.replace(default_marker, default_settings, 1)

    normalize_marker = "announcementEnabled:r.announcementEnabled===!0,glassmorphism:"
    normalized = (
        "announcementEnabled:r.announcementEnabled===!0,"
        "announcementPosition:r.announcementPosition===`header`?`header`:`top`,"
        "announcementColor:a(r.announcementColor)?r.announcementColor:`#21163a`,"
        "announcementIcon:a(r.announcementIcon)?r.announcementIcon:`megaphone`,"
        "announcementDismissible:r.announcementDismissible!==!1,"
        "ctaColor:a(r.ctaColor)?r.ctaColor:`#8b5cf6`,"
        "ctaTitle:a(r.ctaTitle)?r.ctaTitle:``,ctaUrl:a(r.ctaUrl)?r.ctaUrl:``,"
        "socialLinks:Array.isArray(r.socialLinks)?r.socialLinks:[],"
        "discordEnabled:r.discordEnabled===!0,discordGuildId:a(r.discordGuildId)?r.discordGuildId:``,"
        "discordInvite:a(r.discordInvite)?r.discordInvite:``,"
        "metaThemeColor:a(r.metaThemeColor)?r.metaThemeColor:`#101114`,"
        "baseColor:a(r.baseColor)?r.baseColor:`#21163a`,glassmorphism:"
    )
    if source.count(normalize_marker) != 1:
        raise ValueError("Could not locate Vizion frontend settings normalization.")
    source = source.replace(normalize_marker, normalized, 1)

    accent_style = "t.style.setProperty(`--vizion-accent`,e.accent),"
    if source.count(accent_style) != 1:
        raise ValueError("Could not locate Vizion root color variables.")
    source = source.replace(
        accent_style,
        "t.style.setProperty(`--vizion-base-color`,e.baseColor),"+accent_style,
        1,
    )

    return source.encode()


def patch_theme_stylesheet(stylesheet: bytes) -> bytes:
    source = stylesheet.decode()
    marker = "/* Vizion base color variable overrides */"
    if marker in source:
        raise ValueError("Vizion base color overrides were already applied.")

    return (
        source.rstrip()
        + "\n\n"
        + marker
        + """
:root,
.dark,
.light {
    --background: color-mix(in srgb, var(--vizion-base-color, #21163a) 76%, #000 24%);
    --card: var(--vizion-base-color, #21163a);
    --popover: color-mix(in srgb, var(--vizion-base-color, #21163a) 88%, #fff 12%);
    --muted: color-mix(in srgb, var(--vizion-base-color, #21163a) 82%, #fff 18%);
    --secondary: color-mix(in srgb, var(--vizion-base-color, #21163a) 76%, #fff 24%);
    --border: color-mix(in srgb, var(--vizion-base-color, #21163a) 68%, #fff 32%);
    --sidebar: color-mix(in srgb, var(--vizion-base-color, #21163a) 90%, #000 10%);
}
"""
    ).encode()


def patch_settings_page(page: bytes) -> bytes:
    source = 'import{View as PreviewView}from"@pterodactyl/sdk/theme";' + page.decode()

    state_defaults = (
        "metaDescription:``,seoBanner:null,announcement:``,announcementEnabled:!1,"
    )
    extended_defaults = (
        "metaDescription:``,seoBanner:null,allowIndexing:!1,metaThemeColor:`#101114`,"
        "baseColor:`#21163a`,"
        "socialLinks:[],discordEnabled:!1,discordGuildId:``,discordInvite:``,"
        "announcement:``,announcementEnabled:!1,announcementPosition:`top`,"
        "announcementColor:`#21163a`,announcementIcon:`megaphone`,"
        "announcementDismissible:!0,ctaColor:`#8b5cf6`,ctaTitle:``,ctaUrl:``,"
    )
    if source.count(state_defaults) != 1:
        raise ValueError("Could not locate Vizion settings page defaults.")
    source = source.replace(state_defaults, extended_defaults, 1)

    state_mapping = (
        "metaDescription:n(`metaDescription`),seoBanner:r(`seoBanner`),"
        "announcement:n(`announcement`),announcementEnabled:t.get(`announcementEnabled`)===!0,"
    )
    extended_mapping = (
        "metaDescription:n(`metaDescription`),seoBanner:r(`seoBanner`),"
        "allowIndexing:t.get(`allowIndexing`)===!0,metaThemeColor:n(`metaThemeColor`)||`#101114`,"
        "baseColor:n(`baseColor`)||`#21163a`,"
        "socialLinks:Array.isArray(t.get(`socialLinks`))?t.get(`socialLinks`):[],"
        "discordEnabled:t.get(`discordEnabled`)===!0,discordGuildId:n(`discordGuildId`),"
        "discordInvite:n(`discordInvite`),announcement:n(`announcement`),"
        "announcementEnabled:t.get(`announcementEnabled`)===!0,"
        "announcementPosition:t.get(`announcementPosition`)===`header`?`header`:`top`,"
        "announcementColor:n(`announcementColor`)||`#21163a`,"
        "announcementIcon:n(`announcementIcon`)||`megaphone`,"
        "announcementDismissible:t.get(`announcementDismissible`)!==!1,"
        "ctaColor:n(`ctaColor`)||`#8b5cf6`,ctaTitle:n(`ctaTitle`),ctaUrl:n(`ctaUrl`),"
    )
    if source.count(state_mapping) != 1:
        raise ValueError("Could not locate Vizion settings page state mapping.")
    source = source.replace(state_mapping, extended_mapping, 1)

    hooks = "[K,q]=s(null),J=o(null)"
    if source.count(hooks) != 1:
        raise ValueError("Could not locate Vizion settings page hook state.")
    source = source.replace(
        hooks,
        "[K,q]=s(null),[Re,Se]=s(null),[De,Ee]=s({icon:`globe`,title:``,description:``,url:``})",
        1,
    )

    social_parser = (
        "function V(e){return Object.fromEntries(e.map(({input:e,value:t})=>[e,t]))}"
    )
    social_parser_replacement = social_parser + (
        "function Le(e){return Array.isArray(e)?e.flatMap(e=>{if(typeof e!==`string`)return[];"
        "try{let t=JSON.parse(e);return t&&typeof t===`object`&&typeof t.title===`string`&&"
        "typeof t.url===`string`?[{icon:typeof t.icon===`string`?t.icon:`globe`,title:t.title,"
        "description:typeof t.description===`string`?t.description:``,url:t.url}]:[]}catch{return[]}}):[]}"
        "function Me(e){let t={globe:`M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18ZM3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18`,"
        "discord:`M8 7.5a11 11 0 0 1 8 0l1 1.5 1.5 8a12 12 0 0 1-4 2l-1-1.5M8 7.5 7 9l-1.5 8a12 12 0 0 0 4 2l1-1.5M9 13h.01M15 13h.01`,"
        "github:`M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22`,"
        "youtube:`M22.54 6.42a2.78 2.78 0 0 0-1.95-1.97C18.88 4 12 4 12 4s-6.88 0-8.59.45a2.78 2.78 0 0 0-1.95 1.97A29 29 0 0 0 1 12a29 29 0 0 0 .46 5.58 2.78 2.78 0 0 0 1.95 1.97C5.12 20 12 20 12 20s6.88 0 8.59-.45a2.78 2.78 0 0 0 1.95-1.97A29 29 0 0 0 23 12a29 29 0 0 0-.46-5.58ZM10 15.5v-7l6 3.5-6 3.5Z`,"
        "instagram:`M7 2h10a5 5 0 0 1 5 5v10a5 5 0 0 1-5 5H7a5 5 0 0 1-5-5V7a5 5 0 0 1 5-5Zm5 5a5 5 0 1 0 0 10 5 5 0 0 0 0-10Zm5.5-.5h.01`}[e]||`M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18`;return l(`svg`,{viewBox:`0 0 24 24`,fill:`none`,stroke:`currentColor`,strokeWidth:`1.8`,className:`size-5`,children:l(`path`,{d:t,strokeLinecap:`round`,strokeLinejoin:`round`})})}"
    )
    if source.count(social_parser) != 1:
        raise ValueError("Could not locate Vizion settings schema helper.")
    source = source.replace(social_parser, social_parser_replacement, 1)

    save_marker = (
        "settings:{metaTitle:t.metaTitle,metaDescription:t.metaDescription,logoHeight:t.logoHeight,"
        "announcement:t.announcement,announcementEnabled:t.announcementEnabled,"
        "glassmorphism:t.glassmorphism,dashboardBackground:t.dashboardBackground,"
        "font:t.font,accent:t.accent,blur:t.blur}"
    )
    extended_save = (
        "settings:{metaTitle:t.metaTitle,metaDescription:t.metaDescription,"
        "allowIndexing:t.allowIndexing,metaThemeColor:t.metaThemeColor,baseColor:t.baseColor,socialLinks:t.socialLinks,"
        "discordEnabled:t.discordEnabled,discordGuildId:t.discordGuildId,discordInvite:t.discordInvite,"
        "logoHeight:t.logoHeight,announcement:t.announcement,announcementEnabled:t.announcementEnabled,"
        "announcementPosition:t.announcementPosition,announcementColor:t.announcementColor,"
        "announcementIcon:t.announcementIcon,announcementDismissible:t.announcementDismissible,"
        "ctaColor:t.ctaColor,ctaTitle:t.ctaTitle,ctaUrl:t.ctaUrl,"
        "glassmorphism:t.glassmorphism,dashboardBackground:t.dashboardBackground,"
        "font:t.font,accent:t.accent,blur:t.blur}"
    )
    if source.count(save_marker) != 1:
        raise ValueError("Could not locate Vizion settings save payload.")
    source = source.replace(save_marker, extended_save, 1)

    preview_start = "Z=i(()=>{let e=J.current,n=e?.contentDocument,r=e?.contentWindow;if(!n||!r)return;"
    preview_end = "},[Z]);let te=async"
    if source.count(preview_start) != 1 or source.count(preview_end) != 1:
        raise ValueError("Could not locate Vizion preview iframe synchronization.")
    preview_begin = source.index(preview_start)
    preview_finish = source.index(preview_end, preview_begin) + len(preview_end) - len("let te=async")
    local_preview = (
        "Z=i(()=>{let e=document.documentElement;"
        "e.style.setProperty(`--vizion-accent`,t.accent);"
        "e.style.setProperty(`--vizion-base-color`,t.baseColor);"
        "e.style.setProperty(`--vizion-blur`,`${t.blur}px`);"
        "e.style.setProperty(`--vizion-logo-height`,`${t.logoHeight}px`);"
        "e.setAttribute(`data-vizion-glass`,String(t.glassmorphism));"
        "e.setAttribute(`data-vizion-dashboard-background`,String(t.dashboardBackground));"
        "e.setAttribute(`data-vizion-font`,t.font);"
        "},[t]);a(()=>{Z()},[Z]);"
    )
    source = source[:preview_begin] + local_preview + source[preview_finish:]
    source = source.replace("&&J.current?.contentWindow?.location.reload()", "", 2)

    appearance_marker = (
        "`Accent color`,l(`input`,{id:`vizion-accent`,type:`color`,value:t.accent,"
        "onChange:e=>X(`accent`,e.currentTarget.value),className:`block h-11 w-full cursor-pointer "
        "rounded-lg border border-border bg-background p-1`})]}),l(m,"
    )
    appearance_controls = (
        "`Accent color`,l(`input`,{id:`vizion-accent`,type:`color`,value:t.accent,"
        "onChange:e=>X(`accent`,e.currentTarget.value),className:`block h-11 w-full cursor-pointer "
        "rounded-lg border border-border bg-background p-1`})]}),"
        "u(`label`,{className:`block space-y-2 text-sm font-medium`,children:[`Base/Card Background Color`,"
        "u(`div`,{className:`flex items-center gap-3`,children:["
        "l(`input`,{type:`color`,value:t.baseColor,onChange:e=>X(`baseColor`,e.currentTarget.value),"
        "className:`size-11 cursor-pointer rounded-lg border border-border bg-background p-1`}),"
        "l(p,{value:t.baseColor,onChange:e=>X(`baseColor`,e.currentTarget.value),"
        "maxLength:64,className:`font-mono uppercase`,placeholder:`#21163a`})]})]}),l(m,"
    )
    if source.count(appearance_marker) != 1:
        raise ValueError("Could not locate Vizion appearance color controls.")
    source = source.replace(appearance_marker, appearance_controls, 1)

    seo_start = "f===`seo`&&u(H,{title:`SEO & social sharing`,children:["
    seo_end = ",f===`layout`&&u(H,{title:`Dashboard layout`"
    if source.count(seo_start) != 1 or source.count(seo_end) != 1:
        raise ValueError("Could not locate the Vizion SEO settings tab.")
    start = source.index(seo_start)
    end = source.index(seo_end, start)
    seo_content = """f===`seo`&&u(H,{title:`SEO & social sharing`,children:[
        u(`details`,{open:!0,className:`rounded-xl border border-border/70 bg-background/30 p-4`,children:[
            l(`summary`,{className:`cursor-pointer font-semibold`,children:`Indexing`}),
            l(m,{checked:t.allowIndexing,onChange:e=>X(`allowIndexing`,e),label:`Allow to be indexed`,description:`Allow search engines to index the panel and show it in search results.`})
        ]}),
        u(`details`,{open:!0,className:`rounded-xl border border-border/70 bg-background/30 p-4`,children:[
            l(`summary`,{className:`cursor-pointer font-semibold`,children:`Meta Settings`}),
            u(`div`,{className:`mt-4 space-y-5`,children:[
                l(U,{label:`Favicon`,help:`PNG or ICO. Maximum file size: 4 MB.`,value:t.favicon,accept:ee,pending:j===`favicon`,onUpload:e=>void Q(`favicon`,e),onClear:()=>void $(`favicon`)}),
                u(`label`,{className:`block space-y-2 text-sm font-medium`,htmlFor:`vizion-meta-title`,children:[`Meta Title`,l(p,{id:`vizion-meta-title`,maxLength:70,value:t.metaTitle,onChange:e=>X(`metaTitle`,e.currentTarget.value),placeholder:`Your panel title`})]}),
                u(`label`,{className:`block space-y-2 text-sm font-medium`,htmlFor:`vizion-meta-description`,children:[`Meta Description`,l(h,{id:`vizion-meta-description`,maxLength:180,rows:3,value:t.metaDescription,onChange:e=>X(`metaDescription`,e.currentTarget.value),placeholder:`A short description shown in search and link previews.`})]}),
                l(U,{label:`Page Banner`,help:`Used by Discord, WhatsApp, and other services. Maximum 4 MB.`,value:t.seoBanner,pending:j===`seoBanner`,onUpload:e=>void Q(`seoBanner`,e),onClear:()=>void $(`seoBanner`)}),
                u(`label`,{className:`block space-y-2 text-sm font-medium`,children:[`Meta Color`,u(`div`,{className:`flex items-center gap-3`,children:[l(`input`,{type:`color`,value:t.metaThemeColor,onChange:e=>X(`metaThemeColor`,e.currentTarget.value),className:`size-11 cursor-pointer rounded-lg border border-border bg-background p-1`}),l(p,{value:t.metaThemeColor,onChange:e=>X(`metaThemeColor`,e.currentTarget.value),maxLength:64,className:`font-mono uppercase`,placeholder:`#101114`})]})]})
            ]})
        ]}),
        u(H,{title:`Social Buttons`,children:[
            u(`div`,{className:`flex items-center justify-between gap-3`,children:[
                l(`p`,{className:`text-sm text-muted-foreground`,children:`Add links to your community and social profiles.`}),
                l(d,{type:`button`,onClick:()=>{Se(-1),Ee({icon:`globe`,title:``,description:``,url:``})},children:`New Link`})
            ]}),
            ...Le(t.socialLinks).map((e,n)=>u(`div`,{className:`flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border p-3`,children:[
                u(`div`,{className:`flex items-center gap-3`,children:[
                    l(`span`,{className:`grid size-9 place-items-center rounded-lg bg-primary/10 text-primary`,"aria-hidden":!0,children:Me(e.icon)}),
                    u(`div`,{children:[l(`p`,{className:`font-medium`,children:e.title}),l(`p`,{className:`text-xs text-muted-foreground`,children:e.description||e.url})]})
                ]}),
                u(`div`,{className:`flex gap-2`,children:[
                    l(d,{type:`button`,color:`grey`,size:`xsmall`,onClick:()=>{Se(n),Ee({...e})},children:`Edit`}),
                    l(d,{type:`button`,color:`grey`,size:`xsmall`,onClick:()=>{let r=Le(t.socialLinks).filter((e,t)=>t!==n);X(`socialLinks`,r.map(e=>JSON.stringify(e)))},children:`Delete`})
                ]})
            ]},`${e.url}-${n}`)),
            Re!==null&&u(`div`,{role:`dialog`,"aria-modal":`true`,"aria-label":Re===-1?`New Link`:`Edit Link`,className:`fixed inset-0 z-[100] grid place-items-center bg-black/70 p-4`,children:l(`div`,{className:`glass w-full max-w-lg space-y-4 rounded-2xl border border-border p-5 shadow-2xl`,children:[
                l(`h3`,{className:`text-lg font-semibold`,children:Re===-1?`New Link`:`Edit Link`}),
                u(`label`,{className:`block space-y-1 text-sm`,children:[`Icon`,u(`div`,{className:`flex items-center gap-3`,children:[l(`select`,{value:De.icon,onChange:e=>Ee({...De,icon:e.currentTarget.value}),className:`w-full rounded-lg border border-border bg-background px-3 py-2`,children:[l(`option`,{value:`globe`,children:`Globe`}),l(`option`,{value:`discord`,children:`Discord`}),l(`option`,{value:`github`,children:`GitHub`}),l(`option`,{value:`youtube`,children:`YouTube`}),l(`option`,{value:`instagram`,children:`Instagram`})]}),l(`span`,{className:`grid size-10 shrink-0 place-items-center rounded-lg border border-border bg-background`,children:Me(De.icon)})]})]}),
                u(`label`,{className:`block space-y-1 text-sm`,children:[`Title`,l(p,{value:De.title,onChange:e=>Ee({...De,title:e.currentTarget.value}),maxLength:60})]}),
                u(`label`,{className:`block space-y-1 text-sm`,children:[`Description`,l(p,{value:De.description,onChange:e=>Ee({...De,description:e.currentTarget.value}),maxLength:120})]}),
                u(`label`,{className:`block space-y-1 text-sm`,children:[`URL`,l(p,{type:`url`,value:De.url,onChange:e=>Ee({...De,url:e.currentTarget.value}),placeholder:`https://`})]}),
                u(`div`,{className:`flex justify-end gap-2`,children:[
                    l(d,{type:`button`,color:`grey`,onClick:()=>Se(null),children:`Cancel`}),
                    l(d,{type:`button`,onClick:()=>{let e=De.url.trim();try{let n=new URL(e);if(![`http:`,`https:`].includes(n.protocol))throw Error();let r=Le(t.socialLinks),i={...De,title:De.title.trim(),description:De.description.trim(),url:e};if(!i.title)throw Error(`Enter a title.`);if(Re===-1)r.push(i);else r[Re]=i;X(`socialLinks`,r.map(e=>JSON.stringify(e))),Se(null)}catch(e){G(e instanceof Error&&e.message?e.message:`Enter a valid http or https URL.`)}},children:`Save Link`})
                ]})
            ]})}),
        ]}),
        u(H,{title:`Discord Box`,children:[
            l(m,{checked:t.discordEnabled,onChange:e=>X(`discordEnabled`,e),label:`Show Discord Box`,description:`Show a live Discord widget on the dashboard.`}),
            t.discordEnabled&&u(`div`,{className:`grid gap-4 sm:grid-cols-2`,children:[
                u(`label`,{className:`block space-y-2 text-sm font-medium`,children:[`Discord Guild ID`,l(p,{value:t.discordGuildId,onChange:e=>X(`discordGuildId`,e.currentTarget.value),inputMode:`numeric`,placeholder:`Discord server ID`})]}),
                u(`label`,{className:`block space-y-2 text-sm font-medium`,children:[`Discord Invite Link`,l(p,{type:`url`,value:t.discordInvite,onChange:e=>X(`discordInvite`,e.currentTarget.value),placeholder:`https://discord.gg/...`})]})
            ]})
        ]})
    ]})"""
    social_start = "        u(H,{title:`Social Buttons`,children:["
    discord_start = "        u(H,{title:`Discord Box`,children:["
    seo_close = "\n    ]})"
    if (
        seo_content.count(social_start) != 1
        or seo_content.count(discord_start) != 1
        or seo_content.count(seo_close) != 1
    ):
        raise ValueError("Could not separate the Vizion social settings from SEO.")
    social_begin = seo_content.index(social_start)
    discord_begin = seo_content.index(discord_start, social_begin)
    social_end = seo_content.rindex(seo_close)
    social_buttons = seo_content[social_begin:discord_begin].strip().rstrip(",")
    discord_box = seo_content[discord_begin:social_end].strip().rstrip(",")
    seo_content = seo_content[:social_begin] + seo_content[social_end:]
    social_content = (
        "f===`social`&&u(H,{title:`Social & Logins`,children:["
        + social_buttons
        + ","
        + discord_box
        + "]})"
    )
    source = source[:start] + seo_content + source[end:]
    tab_marker = "{id:`seo`,label:`SEO & social`,icon:n},{id:`layout`"
    if source.count(tab_marker) != 1:
        raise ValueError("Could not locate the Vizion settings sidebar tabs.")
    source = source.replace(
        tab_marker,
        "{id:`seo`,label:`SEO`,icon:n},{id:`social`,label:`Social & Logins`,icon:n},{id:`layout`",
        1,
    )
    if source.count(seo_end) != 1:
        raise ValueError("Could not locate the Vizion dashboard layout tab.")
    source = source.replace(seo_end, "," + social_content + seo_end, 1)

    layout_marker = (
        "l(m,{checked:t.announcementEnabled,onChange:e=>X(`announcementEnabled`,e),"
        "label:`Show announcement banner`,description:`Display this message at the top of the client dashboard.`})"
    )
    if source.count(layout_marker) != 1:
        raise ValueError("Could not locate Vizion announcement controls.")
    layout_controls = layout_marker + (
        ",u(`div`,{className:`grid gap-4 sm:grid-cols-2`,children:["
        "u(`label`,{className:`block space-y-2 text-sm font-medium`,children:[`Position`,"
        "l(`select`,{value:t.announcementPosition,onChange:e=>X(`announcementPosition`,e.currentTarget.value),"
        "className:`w-full rounded-lg border border-border bg-background px-3 py-2`,children:["
        "l(`option`,{value:`header`,children:`Header`}),l(`option`,{value:`top`,children:`Top`})]})]}),"
        "u(`label`,{className:`block space-y-2 text-sm font-medium`,children:[`Background Color`,"
        "u(`div`,{className:`flex items-center gap-3`,children:["
        "l(`input`,{type:`color`,value:t.announcementColor,onChange:e=>X(`announcementColor`,e.currentTarget.value),"
        "className:`size-10 rounded-lg border border-border bg-background p-1`}),"
        "l(p,{value:t.announcementColor,onChange:e=>X(`announcementColor`,e.currentTarget.value)})]})]}),"
        "u(`label`,{className:`block space-y-2 text-sm font-medium`,children:[`Icon`,"
        "l(`select`,{value:t.announcementIcon,onChange:e=>X(`announcementIcon`,e.currentTarget.value),"
        "className:`w-full rounded-lg border border-border bg-background px-3 py-2`,children:["
        "l(`option`,{value:`megaphone`,children:`Megaphone`}),l(`option`,{value:`sparkles`,children:`Sparkles`}),"
        "l(`option`,{value:`info`,children:`Info`}),l(`option`,{value:`none`,children:`None`})]})]}),"
        "l(m,{checked:t.announcementDismissible,onChange:e=>X(`announcementDismissible`,e),"
        "label:`Dismissable`,description:`Let users close the announcement.`}),"
        "u(`label`,{className:`block space-y-2 text-sm font-medium`,children:[`CTA Button Title`,"
        "l(p,{value:t.ctaTitle,onChange:e=>X(`ctaTitle`,e.currentTarget.value),maxLength:50})]}),"
        "u(`label`,{className:`block space-y-2 text-sm font-medium`,children:[`CTA Button URL`,"
        "l(p,{type:`url`,value:t.ctaUrl,onChange:e=>X(`ctaUrl`,e.currentTarget.value),placeholder:`https://`})]}),"
        "u(`label`,{className:`block space-y-2 text-sm font-medium`,children:[`CTA / Discount Box Color`,"
        "u(`div`,{className:`flex items-center gap-3`,children:["
        "l(`input`,{type:`color`,value:t.ctaColor,onChange:e=>X(`ctaColor`,e.currentTarget.value),"
        "className:`size-10 rounded-lg border border-border bg-background p-1`}),"
        "l(p,{value:t.ctaColor,onChange:e=>X(`ctaColor`,e.currentTarget.value)})]})]})]})"
    )
    source = source.replace(layout_marker, layout_controls, 1)

    preview_start = (
        "l(`div`,{className:`flex min-h-[65dvh] flex-1 justify-center overflow-hidden rounded-2xl "
        "border border-border/70 bg-background/40 p-3 sm:p-6 xl:min-h-0`,children:l(`div`,"
        "{className:`h-full min-h-[58dvh] w-full overflow-hidden rounded-xl border "
        "border-border/70 shadow-2xl transition-[max-width] duration-300 xl:min-h-0 "
        "${b===`mobile`?`max-w-[420px]`:`max-w-none`}`,children:l(`iframe`"
    )
    preview_end = "})})})]})]})}"
    if source.count(preview_start) != 1 or source.count(preview_end) != 1:
        raise ValueError("Could not locate Vizion iframe preview.")
    preview_begin = source.index(preview_start)
    preview_finish = source.index(preview_end, preview_begin) + len(preview_end)
    preview = """l(`div`,{className:`flex min-h-[65dvh] flex-1 justify-center overflow-hidden rounded-2xl border border-border/70 bg-background/40 p-3 sm:p-6 xl:min-h-0`,children:l(`div`,{className:`origin-top transition-[width,transform] duration-300 ${b===`mobile`?`w-[420px]`:`w-full`}`,style:{transform:`scale(.65)`,transformOrigin:`top center`},children:u(`div`,{className:`min-h-[820px] overflow-hidden rounded-xl border border-border bg-background text-foreground shadow-2xl`,children:[
        u(`header`,{className:`flex items-center justify-between border-b border-border px-5 py-4`,children:[u(`div`,{className:`flex items-center gap-3`,children:[l(`div`,{className:`size-8 rounded-lg bg-primary/20`}),l(`strong`,{children:`Vizion Dashboard`})]}),l(`span`,{className:`text-xs text-muted-foreground`,children:`Preview`})]}),
        t.announcementEnabled&&u(`div`,{className:`flex items-center justify-between gap-3 px-5 py-3 text-sm`,style:{backgroundColor:t.announcementColor,color:`white`},children:[l(`span`,{children:[t.announcementIcon!==`none`?`${t.announcementIcon}  `:``,t.announcement||`Your announcement appears here.`]}),t.ctaTitle&&l(`span`,{className:`rounded-md px-3 py-1.5 text-xs font-semibold`,style:{backgroundColor:t.ctaColor},children:t.ctaTitle})]}),
        u(`div`,{className:`grid min-h-[700px] grid-cols-[150px_1fr]`,children:[u(`aside`,{className:`space-y-3 border-r border-border p-4 text-xs text-muted-foreground`,children:[l(`div`,{className:`mb-5 h-7 rounded bg-muted`}),l(`p`,{children:`Dashboard`}),l(`p`,{children:`Account`}),l(`p`,{children:`Servers`})]}),u(`main`,{className:`space-y-5 p-6`,children:[u(`div`,{children:[l(`p`,{className:`text-xs text-muted-foreground`,children:`Welcome back`}),l(`h2`,{className:`mt-1 text-2xl font-semibold`,children:`Your servers`})]}),u(`div`,{className:`grid gap-3 sm:grid-cols-2`,children:[1,2,3,4].map(e=>u(`div`,{className:`rounded-xl border border-border bg-card p-4`,children:[l(`div`,{className:`mb-3 size-8 rounded-lg bg-primary/20`}),l(`div`,{className:`h-3 w-2/3 rounded bg-muted`}),l(`div`,{className:`mt-3 h-2 w-1/2 rounded bg-muted/60`})]},e))}),l(`div`,{className:`h-28 rounded-xl border border-border bg-card`})]})]})
    ]})})})]})]})}"""
    preview = (
        "l(`div`,{className:`flex min-h-[65dvh] flex-1 justify-center overflow-hidden rounded-2xl "
        "border border-border/70 bg-background/40 p-3 sm:p-6 xl:min-h-0`,style:{overflow:`auto`},"
        "children:l(`div`,{className:`origin-top-left transition-[transform,width] duration-300`,"
        "style:{width:b===`mobile`?`646px`:`153.8462%`,transform:`scale(.65)`,"
        "transformOrigin:`top left`,pointerEvents:`none`},'aria-hidden':!0,"
        "children:l(PreviewView,{name:`layout.app`,children:l(PreviewView,{name:`page.dashboard`})})})})"
        "]})]})}"
    )
    source = source[:preview_begin] + preview + source[preview_finish:]

    if "l(`iframe`" in source or "u(`iframe`" in source:
        raise ValueError("The Vizion live preview must not contain an iframe.")

    return source.encode()


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
        manifest["version"] = "1.1.9"
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
        provider = patch_vizion_provider(provider)
        boot = "$this->registerHeadTags(fn (): array => $this->headTags());"
        if boot not in provider.decode():
            raise ValueError("Could not locate Vizion provider boot method.")
        provider = provider.decode().replace(
            boot,
            "$this->registerApiRoutes();\n        "
            "$this->app['router']->pushMiddlewareToGroup('admin-api', "
            "\\Vizion\\Http\\Middleware\\FilterBundledExtensionsFromAdminList::class);\n        "
            "$this->app['router']->pushMiddlewareToGroup('api', "
            "\\Vizion\\Http\\Middleware\\VizionThemeGate::class);\n        "
            "$this->app['router']->pushMiddlewareToGroup('web', "
            "\\Vizion\\Http\\Middleware\\VizionThemeGate::class);\n        "
            "$this->app->make('view')->composer('templates.wrapper', function ($view): void {\n"
            "            if ($this->app->make(ThemeResolver::class)->document($this->app['request'])->id === $this->id()) {\n"
            "                return;\n"
            "            }\n"
            "            $data = $view->getData();\n"
            "            $configuration = $data['siteConfiguration'] ?? null;\n"
            "            if (! is_array($configuration) || ! is_array($configuration['extensions'] ?? null)) {\n"
            "                return;\n"
            "            }\n"
            "            $manifest = $this->app->make(\\Pterodactyl\\Services\\Extensions\\ExtensionRepository::class)->discovered()->get($this->id());\n"
            "            if ($manifest === null || ! is_file($index = $manifest->path('resources', 'packages', 'index.json'))) {\n"
            "                throw new \\UnexpectedValueException('The Vizion bundle index is unavailable.');\n"
            "            }\n"
            "            $packages = json_decode((string) file_get_contents($index), true, 512, JSON_THROW_ON_ERROR);\n"
            "            if (! is_array($packages)) {\n"
            "                throw new \\UnexpectedValueException('The Vizion bundle index is invalid.');\n"
            "            }\n"
            "            $ids = array_column($packages, 'id');\n"
            "            $configuration['extensions'] = array_values(array_filter($configuration['extensions'], static fn (mixed $extension): bool => ! is_array($extension) || ! in_array($extension['id'] ?? null, $ids, true)));\n"
            "            $view->with('siteConfiguration', $configuration);\n"
            "        });\n        "
            + boot,
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

        store_info, store = entries["dist/chunk.settings.sdDqqCB1.js"]
        entries["dist/chunk.settings.sdDqqCB1.js"] = (store_info, patch_theme_settings_store(store))
        for stylesheet_path in ("dist/theme.css", "dist/theme-source.css"):
            stylesheet_info, stylesheet = entries[stylesheet_path]
            entries[stylesheet_path] = (stylesheet_info, patch_theme_stylesheet(stylesheet))

        settings_info, settings = entries["dist/chunk.VizionSettingsPage.CBuhquzc.js"]
        if b"noindex" in settings:
            raise ValueError("Vizion settings chunk unexpectedly contains SEO robots text.")
        entries["dist/chunk.VizionSettingsPage.CBuhquzc.js"] = (settings_info, settings)

        shell_info, shell = entries["dist/chunk.AppShell.BcbKZzqm.js"]
        shell = shell.decode()
        settings_import = 'import{n as r}from"./chunk.settings.sdDqqCB1.js";'
        if shell.count(settings_import) != 1:
            raise ValueError("Could not locate Vizion dashboard settings import.")
        shell = shell.replace(
            settings_import,
            'import{n as r}from"./chunk.settings.sdDqqCB1.js";import{useEffect as Ve,useState as Se}from"react";',
            1,
        )
        overlay_marker = "function C({model:e,children:t})"
        overlay = """function E(e){try{let t=new URL(e);return[`https:`,`http:`].includes(t.protocol)?t.href:null}catch{return null}}
function socialIcon(e){let t={globe:`M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18ZM3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18`,discord:`M8 7.5a11 11 0 0 1 8 0l1 1.5 1.5 8a12 12 0 0 1-4 2l-1-1.5M8 7.5 7 9l-1.5 8a12 12 0 0 0 4 2l1-1.5M9 13h.01M15 13h.01`,github:`M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22`,youtube:`M22.54 6.42a2.78 2.78 0 0 0-1.95-1.97C18.88 4 12 4 12 4s-6.88 0-8.59.45a2.78 2.78 0 0 0-1.95 1.97A29 29 0 0 0 1 12a29 29 0 0 0 .46 5.58 2.78 2.78 0 0 0 1.95 1.97C5.12 20 12 20 12 20s6.88 0 8.59-.45a2.78 2.78 0 0 0 1.95-1.97A29 29 0 0 0 23 12a29 29 0 0 0-.46-5.58ZM10 15.5v-7l6 3.5-6 3.5Z`,instagram:`M7 2h10a5 5 0 0 1 5 5v10a5 5 0 0 1-5 5H7a5 5 0 0 1-5-5V7a5 5 0 0 1 5-5Zm5 5a5 5 0 1 0 0 10 5 5 0 0 0 0-10Zm5.5-.5h.01`}[e]||`M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18`;return s(`svg`,{viewBox:`0 0 24 24`,fill:`none`,stroke:`currentColor`,strokeWidth:`1.8`,className:`size-4`,children:s(`path`,{d:t,strokeLinecap:`round`,strokeLinejoin:`round`})})}
function A(){let{pathname:e}=f(),t=r(),[n,i]=Se(!1);Ve(()=>{i(!1)},[e,t.announcement]);if(e!==`/`&&e!==`/panel`)return null;let a=E(t.ctaUrl),invite=E(t.discordInvite),s=Array.isArray(t.socialLinks)?t.socialLinks.flatMap(e=>{if(typeof e!==`string`)return[];try{let t=JSON.parse(e);return t&&typeof t.title===`string`&&typeof t.url===`string`&&E(t.url)?[{...t,url:E(t.url)}]:[]}catch{return[]}}):[],discordReady=t.discordEnabled&&/^\\d{1,24}$/.test(t.discordGuildId);return c(o,{children:[
t.announcementEnabled&&!n&&c(`div`,{className:`fixed ${t.announcementPosition===`header`?`top-16`:`top-3`} left-1/2 z-[80] flex w-[min(94vw,900px)] -translate-x-1/2 items-center gap-3 rounded-xl border border-white/10 px-4 py-3 text-sm text-white shadow-2xl`,style:{backgroundColor:t.announcementColor},children:[
l(`span`,{className:`shrink-0`,children:t.announcementIcon===`megaphone`?`📣`:t.announcementIcon===`sparkles`?`✦`:t.announcementIcon===`info`?`ⓘ`:``}),
l(`p`,{className:`min-w-0 flex-1`,children:t.announcement}),
a&&t.ctaTitle&&l(`a`,{href:a,target:`_blank`,rel:`noopener noreferrer`,className:`shrink-0 rounded-lg px-3 py-2 text-xs font-semibold text-white`,style:{backgroundColor:t.ctaColor},children:t.ctaTitle}),
t.announcementDismissible&&l(`button`,{type:`button`,"aria-label":`Dismiss announcement`,onClick:()=>i(!0),className:`shrink-0 rounded-md px-2 py-1 text-white/80 hover:bg-white/10`,children:`×`})
]}),
s.length>0&&c(`nav`,{"aria-label":`Social links`,className:`fixed bottom-5 left-5 z-[70] flex flex-wrap gap-2`,children:s.map((e,t)=>l(`a`,{href:e.url,target:`_blank`,rel:`noopener noreferrer`,title:e.description||e.title,className:`glass inline-flex items-center gap-2 rounded-full px-3 py-2 text-xs font-medium text-foreground shadow-lg`,children:[socialIcon(e.icon),e.title]},`${e.url}-${t}`))}),
discordReady&&c(`section`,{className:`fixed bottom-5 right-5 z-[70] w-[min(92vw,320px)] overflow-hidden rounded-xl border border-border bg-card shadow-2xl`,children:[
c(`header`,{className:`flex items-center justify-between gap-3 border-b border-border p-3`,children:[l(`span`,{className:`font-semibold`,children:`Discord Community`}),invite&&l(`a`,{href:invite,target:`_blank`,rel:`noopener noreferrer`,className:`rounded-md bg-primary px-3 py-1.5 text-xs font-semibold text-primary-foreground`,children:`Join`})]}),
l(`iframe`,{title:`Live Discord server members`,src:`https://discord.com/widget?id=${encodeURIComponent(t.discordGuildId)}&theme=dark`,width:`320`,height:`360`,allowTransparency:!0,sandbox:`allow-popups allow-popups-to-escape-sandbox allow-same-origin allow-scripts`,className:`block w-full border-0`}),
l(`p`,{className:`px-3 py-2 text-xs text-muted-foreground`,children:`Live members online are provided by Discord.`})
]})
]})}
"""
        if shell.count(overlay_marker) != 1:
            raise ValueError("Could not locate Vizion dashboard shell component.")
        shell = shell.replace(overlay_marker, overlay + overlay_marker, 1)
        header_actions = "!l&&s(S,{model:e,showActions:o}),"
        sidebar_visibility = "!o&&!l&&c(`aside`"
        if shell.count(header_actions) != 1 or shell.count(sidebar_visibility) != 1:
            raise ValueError("Could not locate the Vizion admin header and sidebar controls.")
        shell = shell.replace(header_actions, "!l&&s(S,{model:e,showActions:!1}),", 1)
        shell = shell.replace(sidebar_visibility, "!l&&c(`aside`", 1)
        main_mount = "s(i,{className:`min-w-0 flex-1`,children:t})"
        if shell.count(main_mount) != 1:
            raise ValueError("Could not locate Vizion dashboard content mount.")
        shell = shell.replace(
            main_mount,
            "c(o,{children:[s(i,{className:`min-w-0 flex-1`,children:t}),s(A,{})]})",
            1,
        )
        entries["dist/chunk.AppShell.BcbKZzqm.js"] = (shell_info, shell.encode())

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
