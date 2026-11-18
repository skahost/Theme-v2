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

export default function VizionExtensionHub() {
    const [extensions, setExtensions] = useState([]);
    const [loading, setLoading] = useState(true);
    const [installing, setInstalling] = useState(false);
    const [error, setError] = useState(null);

    const refresh = useCallback(async () => {
        const response = await http.get('/api/admin/extensions');
        setExtensions((response.data?.data ?? []).filter((extension) => bundledIds.includes(extension.id)));
    }, []);

    const installBundle = useCallback(async () => {
        setInstalling(true);
        setError(null);
        let failure = null;

        try {
            await http.post('/api/admin/extensions/vizion/install-bundled');
        } catch (cause) {
            failure = httpErrorToHuman(cause);
        }

        try {
            await refresh();
        } catch (cause) {
            failure = failure
                ? `${failure}\n${httpErrorToHuman(cause)}`
                : httpErrorToHuman(cause);
        } finally {
            setLoading(false);
            setInstalling(false);
        }

        if (failure) {
            setError(failure);
        }
    }, [refresh]);

    const toggle = useCallback(async (id, enabled) => {
        try {
            setError(null);
            await http.post(
                `/api/admin/extensions/${encodeURIComponent(id)}/${enabled ? 'disable' : 'enable'}`
            );
            window.location.reload();
        } catch (cause) {
            setError(httpErrorToHuman(cause));
        }
    }, []);

    useEffect(() => {
        void installBundle();
    }, [installBundle]);

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
                                    'Install and manage the bundled native extensions. Packages stay disabled until you enable them.',
                            }),
                        ],
                    }),
                    jsx(Button, {
                        type: 'button',
                        disabled: installing,
                        isLoading: installing,
                        onClick: () => void installBundle(),
                        children: installing ? 'Installing…' : 'Install / repair packages',
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
                                                  children: extension.name || extension.id,
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
                                              jsx(Button, {
                                                  type: 'button',
                                                  isSecondary: true,
                                                  disabled:
                                                      extension.missing ||
                                                      extension.state === 'invalid' ||
                                                      installing,
                                                  onClick: () => void toggle(extension.id, enabled),
                                                  children: enabled ? 'Disable' : 'Enable',
                                              }),
                                              jsx(Button, {
                                                  type: 'button',
                                                  isSecondary: true,
                                                  disabled: extension.missing,
                                                  onClick: () => {
                                                      window.location.href = '/admin/extensions';
                                                  },
                                                  children: 'Configure',
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
                    "Configure opens Pterodactyl's native Extensions manager, which provides each extension's settings and file controls.",
            }),
        ],
    });
}
