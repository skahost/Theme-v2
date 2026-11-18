import { useCallback, useEffect, useState } from 'react';
import { jsx, jsxs } from 'react/jsx-runtime';
import { Button, http, httpErrorToHuman } from '@pterodactyl/sdk';

const bundledIds = [
    'drift-register',
    'demo',
    'drift-mod',
    'drift-plugins',
    'drift-properties',
    'egg-changer',
    'mc-console',
    'mc-players',
    'mc-version',
    'mc-world',
    'mcp',
    'monitoring',
    'node-resources',
    'palworld-tools',
    'server-creator',
    'server-expiry',
    'server-groups',
    'server-splitter',
    'sso',
    'subdomains',
    'terraria-tools',
    'valheim-tools',
    'votifier-tester',
];

const adminExtensionsUrl = () => {
    const basePath = window.location.pathname.replace(/\/panel(?:\/.*)?$/, '');

    return `${basePath}/panel/extensions`;
};

export default function VizionExtensionHub() {
    const [extensions, setExtensions] = useState([]);
    const [master, setMaster] = useState(null);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    useEffect(() => {
        let mounted = true;
        Promise.all([
            http.get('/api/admin/extensions'),
            http.get('/api/admin/extensions/vizion/master'),
        ])
            .then(([response, masterResponse]) => {
                if (mounted) {
                    setExtensions(
                        (response.data?.data ?? []).filter((extension) => bundledIds.includes(extension.id))
                    );
                    setMaster(masterResponse.data?.data ?? null);
                }
            })
            .catch((cause) => {
                if (mounted) {
                    setError(httpErrorToHuman(cause));
                }
            })
            .finally(() => {
                if (mounted) {
                    setLoading(false);
                }
            });

        return () => {
            mounted = false;
        };
    }, []);

    const isMasterOn =
        master?.active === true ||
        extensions.some((extension) => bundledIds.includes(extension.id) && extension.enabled === true);

    const install = useCallback(
        async (extensionId) => {
            setBusy(true);
            setError(null);
            try {
                await http.post('/api/admin/extensions/vizion/install-bundled', extensionId ? { extension: extensionId } : {});
                window.location.reload();
            } catch (cause) {
                setError(httpErrorToHuman(cause));
                setBusy(false);
            }
        },
        []
    );

    const toggleMaster = useCallback(async () => {
        setBusy(true);
        setError(null);
        try {
            await http.post('/api/admin/extensions/vizion/master', { enabled: !isMasterOn });
            window.location.reload();
        } catch (cause) {
            setError(httpErrorToHuman(cause));
            setBusy(false);
        }
    }, [isMasterOn]);

    const toggle = useCallback(async (id, enabled) => {
        setBusy(true);
        setError(null);
        try {
            await http.post(
                `/api/admin/extensions/${encodeURIComponent(id)}/${enabled ? 'disable' : 'enable'}`
            );
            window.location.reload();
        } catch (cause) {
            setError(httpErrorToHuman(cause));
            setBusy(false);
        }
    }, []);

    const remove = useCallback(async (id) => {
        if (!window.confirm(`Remove ${id}? Its settings and installed files will be deleted.`)) {
            return;
        }

        setBusy(true);
        setError(null);
        try {
            await http.delete(`/api/admin/extensions/${encodeURIComponent(id)}`);
            window.location.reload();
        } catch (cause) {
            setError(httpErrorToHuman(cause));
            setBusy(false);
        }
    }, []);

    const items = bundledIds.map(
        (id) => extensions.find((extension) => extension.id === id) ?? { id, name: id, missing: true }
    );

    return jsxs('main', {
        className: 'mx-auto w-full max-w-6xl space-y-6 p-6',
        children: [
            jsxs('header', {
                className: 'flex flex-wrap items-end justify-between gap-4',
                children: [
                    jsxs('div', {
                        children: [
                            jsx('h1', {
                                className: 'text-2xl font-semibold text-foreground',
                                children: 'Vizion Extension',
                            }),
                            jsx('p', {
                                className: 'mt-2 max-w-3xl text-sm text-muted-foreground',
                                children:
                                    'Install, enable, configure, or remove bundled extensions. Removing an extension will not reinstall it automatically.',
                            }),
                        ],
                    }),
                    jsx(Button, {
                        type: 'button',
                        disabled: busy || loading || master === null,
                        isSecondary: true,
                        isLoading: busy,
                        onClick: () => void toggleMaster(),
                        children: isMasterOn ? 'Turn Vizion + extensions off' : 'Enable Vizion + all extensions',
                    }),
                    jsx(Button, {
                        type: 'button',
                        disabled: busy || loading,
                        isLoading: busy,
                        onClick: () => void install(),
                        children: busy ? 'Working…' : 'Install / update all',
                    }),
                ],
            }),
            error &&
                jsx('div', {
                    role: 'alert',
                    className: 'whitespace-pre-line rounded-md border border-destructive/40 bg-destructive/10 p-4 text-sm text-destructive',
                    children: error,
                }),
            loading
                ? jsx('p', {
                      className: 'text-sm text-muted-foreground',
                      children: 'Loading bundled extensions…',
                  })
                : jsxs('section', {
                      className: 'grid gap-3',
                      children: items.map((extension) => {
                          const enabled = extension.enabled === true;
                          const displayName = extension.id.startsWith('drift-')
                              ? extension.id.replace('drift-', '').replace(/^\w/, (letter) => letter.toUpperCase())
                              : extension.name || extension.id;
                          const status = extension.missing
                              ? 'Not installed'
                              : extension.state === 'error' || extension.state === 'invalid'
                                ? 'Needs attention'
                                : enabled
                                  ? 'Enabled'
                                  : 'Installed · disabled';

                          return jsxs(
                              'article',
                              {
                                  className:
                                      'flex flex-col gap-4 rounded-lg border border-border bg-card p-5 sm:flex-row sm:items-center sm:justify-between',
                                  children: [
                                      jsxs('div', {
                                          className: 'min-w-0',
                                          children: [
                                              jsx('h2', {
                                                  className: 'font-semibold text-foreground',
                                                  children: displayName,
                                              }),
                                              jsx('p', {
                                                  className: 'mt-1 text-sm text-muted-foreground',
                                                  children: extension.description || extension.id,
                                              }),
                                              jsx('p', {
                                                  className: 'mt-2 text-xs text-muted-foreground',
                                                  children: status,
                                              }),
                                          ],
                                      }),
                                      jsxs('div', {
                                          className: 'flex shrink-0 flex-wrap gap-2',
                                          children: [
                                              extension.missing
                                                  ? jsx(Button, {
                                                        type: 'button',
                                                        isSecondary: true,
                                                        disabled: busy,
                                                        onClick: () => void install(extension.id),
                                                        children: 'Install',
                                                    })
                                                  : jsxs('div', {
                                                        className: 'flex flex-wrap gap-2',
                                                        children: [
                                                            jsx(Button, {
                                                                type: 'button',
                                                                isSecondary: true,
                                                                disabled:
                                                                    busy || extension.state === 'invalid',
                                                                onClick: () =>
                                                                    void toggle(extension.id, enabled),
                                                                children: enabled ? 'Disable' : 'Enable',
                                                            }),
                                                            jsx(Button, {
                                                                type: 'button',
                                                                isSecondary: true,
                                                                disabled: busy,
                                                                onClick: () =>
                                                                    window.location.assign(adminExtensionsUrl()),
                                                                children: 'Configure',
                                                            }),
                                                            jsx(Button, {
                                                                type: 'button',
                                                                color: 'red',
                                                                isSecondary: true,
                                                                disabled: busy,
                                                                onClick: () => void remove(extension.id),
                                                                children: 'Remove',
                                                            }),
                                                        ],
                                                    }),
                                          ],
                                      }),
                                  ],
                              },
                              extension.id
                          );
                      }),
                  }),
            jsx('p', {
                className: 'text-xs text-muted-foreground',
                children:
                    "Configure opens Pterodactyl's native Extensions manager, which provides each extension's settings. Extension identifiers remain unchanged for compatibility.",
            }),
        ],
    });
}
