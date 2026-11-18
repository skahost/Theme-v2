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
    const [master, setMaster] = useState(null);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [configuring, setConfiguring] = useState(null);
    const [configRegistered, setConfigRegistered] = useState(false);
    const [configSchema, setConfigSchema] = useState([]);
    const [configValues, setConfigValues] = useState({});
    const [configLoading, setConfigLoading] = useState(false);
    const [configSaving, setConfigSaving] = useState(false);
    const [configError, setConfigError] = useState(null);
    const [configNotice, setConfigNotice] = useState(null);

    useEffect(() => {
        let mounted = true;
        Promise.all([
            http.get('/api/admin/extensions?vizion_bundle=1'),
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

    const loadConfig = useCallback(async (id) => {
        const response = await http.get(`/api/admin/extensions/${encodeURIComponent(id)}/settings`);
        const schema = response.data?.data?.schema ?? [];
        setConfigRegistered(response.data?.data?.registered === true);
        setConfigSchema(schema);
        setConfigValues(
            Object.fromEntries(
                schema.map((field) => [field.input, field.field === 'password' ? '' : field.value])
            )
        );
    }, []);

    const openConfig = useCallback(
        async (id) => {
            if (configuring === id) {
                setConfiguring(null);
                return;
            }

            setConfiguring(id);
            setConfigRegistered(false);
            setConfigSchema([]);
            setConfigValues({});
            setConfigError(null);
            setConfigNotice(null);
            setConfigLoading(true);
            try {
                await loadConfig(id);
            } catch (cause) {
                setConfigError(httpErrorToHuman(cause));
            } finally {
                setConfigLoading(false);
            }
        },
        [configuring, loadConfig]
    );

    const updateConfigValue = (field, value) =>
        setConfigValues((current) => ({ ...current, [field.input]: value }));

    const saveConfig = useCallback(async () => {
        setConfigSaving(true);
        setConfigError(null);
        setConfigNotice(null);
        try {
            const settings = {};
            for (const field of configSchema) {
                if (field.field === 'file') {
                    continue;
                }

                const value = configValues[field.input];
                if (field.field === 'password' && (!value || value === '********')) {
                    continue;
                }
                if (value !== undefined) {
                    settings[field.input] =
                        field.field === 'list' && Array.isArray(value)
                            ? value.map((item) => String(item).trim()).filter(Boolean)
                            : value;
                }
            }

            await http.patch(`/api/admin/extensions/${encodeURIComponent(configuring)}/settings`, { settings });
            await loadConfig(configuring);
            setConfigNotice('Settings saved.');
        } catch (cause) {
            setConfigError(httpErrorToHuman(cause));
        } finally {
            setConfigSaving(false);
        }
    }, [configSchema, configValues, configuring, loadConfig]);

    const uploadConfigFile = useCallback(
        async (field, file) => {
            const form = new FormData();
            form.append('file', file);
            setConfigSaving(true);
            setConfigError(null);
            try {
                await http.post(
                    `/api/admin/extensions/${encodeURIComponent(configuring)}/settings/${encodeURIComponent(field.input)}/file`,
                    form
                );
                await loadConfig(configuring);
            } catch (cause) {
                setConfigError(httpErrorToHuman(cause));
            } finally {
                setConfigSaving(false);
            }
        },
        [configuring, loadConfig]
    );

    const clearConfigFile = useCallback(
        async (field) => {
            setConfigSaving(true);
            setConfigError(null);
            try {
                await http.delete(
                    `/api/admin/extensions/${encodeURIComponent(configuring)}/settings/${encodeURIComponent(field.input)}/file`
                );
                await loadConfig(configuring);
            } catch (cause) {
                setConfigError(httpErrorToHuman(cause));
            } finally {
                setConfigSaving(false);
            }
        },
        [configuring, loadConfig]
    );

    const renderConfigControl = (field) => {
        const value = Object.prototype.hasOwnProperty.call(configValues, field.input)
            ? configValues[field.input]
            : field.value;
        const common = {
            className: 'mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm text-foreground',
            'aria-label': field.label || field.input,
            disabled: configSaving,
            onChange: (event) => updateConfigValue(field, event.currentTarget.value),
        };

        if (field.field === 'toggle') {
            return jsx('input', {
                type: 'checkbox',
                checked: value === true,
                disabled: configSaving,
                onChange: (event) => updateConfigValue(field, event.currentTarget.checked),
            });
        }
        if (field.field === 'select' || field.field === 'multiselect') {
            const multiple = field.field === 'multiselect';
            return jsx('select', {
                ...common,
                multiple,
                value: multiple ? (Array.isArray(value) ? value.map(String) : []) : value ?? '',
                onChange: (event) => {
                    if (!multiple) {
                        const selected = field.options?.find(
                            (option) => String(option.value) === event.currentTarget.value
                        );
                        updateConfigValue(field, selected?.value ?? event.currentTarget.value);
                        return;
                    }

                    updateConfigValue(
                        field,
                        Array.from(event.currentTarget.selectedOptions, (selectedOption) =>
                            field.options?.find((option) => String(option.value) === selectedOption.value)?.value ??
                            selectedOption.value
                        )
                    );
                },
                children: field.options?.map((option) =>
                    jsx('option', { value: String(option.value), children: option.label }, String(option.value))
                ),
            });
        }
        if (field.field === 'textarea' || field.field === 'list') {
            return jsx('textarea', {
                ...common,
                rows: 4,
                maxLength: field.constraints?.max_length,
                value: field.field === 'list' ? (Array.isArray(value) ? value.join('\n') : '') : value ?? '',
                onChange: (event) =>
                    updateConfigValue(
                        field,
                        field.field === 'list' ? event.currentTarget.value.split('\n') : event.currentTarget.value
                    ),
            });
        }
        if (field.field === 'file') {
            return jsxs('div', {
                className: 'mt-1 space-y-2',
                children: [
                    typeof field.value === 'string' &&
                        jsx('a', {
                            href: field.value,
                            target: '_blank',
                            rel: 'noreferrer',
                            className: 'block truncate text-xs text-primary underline',
                            children: field.value,
                        }),
                    jsx('input', {
                        type: 'file',
                        'aria-label': field.label || field.input,
                        accept: field.constraints?.accept?.join(','),
                        disabled: configSaving,
                        onChange: (event) => {
                            const file = event.currentTarget.files?.[0];
                            event.currentTarget.value = '';
                            if (file) {
                                void uploadConfigFile(field, file);
                            }
                        },
                    }),
                    field.value &&
                        jsx(Button, {
                            type: 'button',
                            color: 'red',
                            isSecondary: true,
                            disabled: configSaving,
                            onClick: () => void clearConfigFile(field),
                            children: 'Remove file',
                        }),
                ],
            });
        }

        const type =
            field.field === 'password'
                ? 'password'
                : field.field === 'number'
                  ? 'number'
                  : 'text';
        return jsx('input', {
            ...common,
            type,
            value: value ?? '',
            placeholder:
                field.field === 'password' && field.value
                    ? `Stored: ${String(field.value)} - enter a new value to replace it`
                    : undefined,
            maxLength: field.constraints?.max_length,
            min: field.constraints?.min,
            max: field.constraints?.max,
            onChange: (event) =>
                updateConfigValue(
                    field,
                    field.field === 'number'
                        ? event.currentTarget.value === ''
                            ? null
                            : event.currentTarget.valueAsNumber
                        : event.currentTarget.value
                ),
        });
    };

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
                          const displayDescription = (extension.description || extension.id)
                              .replace(/\bdrift[-\s]+/gi, '')
                              .replace(/\s+/g, ' ')
                              .trim();
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
                                                  children: displayDescription,
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
                                                                disabled: busy || configLoading || configSaving,
                                                                onClick: () => void openConfig(extension.id),
                                                                children:
                                                                    configuring === extension.id
                                                                        ? 'Close settings'
                                                                        : 'Configure',
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
                                      configuring === extension.id &&
                                          jsxs('section', {
                                              className: 'w-full basis-full border-t border-border pt-4',
                                              children: [
                                                  jsx('h3', {
                                                      className: 'mb-3 font-semibold text-foreground',
                                                      children: `${displayName} settings`,
                                                  }),
                                                  configError &&
                                                      jsx('p', {
                                                          role: 'alert',
                                                          className: 'mb-3 text-sm text-destructive',
                                                          children: configError,
                                                      }),
                                                  configNotice &&
                                                      jsx('p', {
                                                          role: 'status',
                                                          className: 'mb-3 text-sm text-muted-foreground',
                                                          children: configNotice,
                                                      }),
                                                  configLoading
                                                      ? jsx('p', {
                                                            className: 'text-sm text-muted-foreground',
                                                            children: 'Loading settings…',
                                                        })
                                                      : configSchema.length === 0
                                                        ? jsx('p', {
                                                              className: 'text-sm text-muted-foreground',
                                                              children: !extension.enabled
                                                                  ? 'Enable this extension to load its settings.'
                                                                  : configRegistered
                                                                    ? 'This extension has no configurable settings.'
                                                                    : 'This extension has not registered settings.',
                                                          })
                                                        : jsxs('div', {
                                                              className: 'grid gap-4 sm:grid-cols-2',
                                                              children: [
                                                                  ...configSchema.map((field) =>
                                                                      jsxs(
                                                                          'div',
                                                                          {
                                                                              className:
                                                                                  'block text-sm text-foreground',
                                                                              children: [
                                                                                  jsx('p', {
                                                                                      className: 'mb-1',
                                                                                      children: field.label || field.input,
                                                                                  }),
                                                                                  renderConfigControl(field),
                                                                                  field.help &&
                                                                                      jsx('span', {
                                                                                          className:
                                                                                              'mt-1 block text-xs text-muted-foreground',
                                                                                          children: field.help,
                                                                                      }),
                                                                              ],
                                                                          },
                                                                          field.input
                                                                      )
                                                                  ),
                                                                  jsx('div', {
                                                                      className: 'sm:col-span-2',
                                                                      children: jsx(Button, {
                                                                          type: 'button',
                                                                          disabled: configSaving,
                                                                          isLoading: configSaving,
                                                                          onClick: () => void saveConfig(),
                                                                          children: 'Save settings',
                                                                      }),
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
                    'Bundled extension settings open inline here. Their internal identifiers stay unchanged for compatibility.',
            }),
        ],
    });
}
