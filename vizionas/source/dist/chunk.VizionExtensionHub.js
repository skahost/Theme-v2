import { useCallback, useEffect, useState } from 'react';
import { jsx, jsxs } from 'react/jsx-runtime';
import {
    Alert,
    Button,
    Dialog,
    FileInput,
    http,
    httpErrorToHuman,
    Input,
    NumberInput,
    Select,
    Spinner,
    SpinnerOverlay,
    Switch,
    TextArea,
} from '@pterodactyl/sdk';

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

const extensionErrorToHuman = (cause) => httpErrorToHuman(cause).replace(/\bdrift[-\s]+/gi, '');

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
    const [configSection, setConfigSection] = useState(null);

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
                    setError(extensionErrorToHuman(cause));
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
            setConfigSection(null);
            setConfigLoading(true);
            try {
                await loadConfig(id);
            } catch (cause) {
                setConfigError(extensionErrorToHuman(cause));
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
            setConfigError(extensionErrorToHuman(cause));
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
                setConfigError(extensionErrorToHuman(cause));
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
                setConfigError(extensionErrorToHuman(cause));
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

        if (field.field === 'toggle') {
            return jsx(Switch, {
                checked: value === true,
                disabled: configSaving,
                onChange: (next) => updateConfigValue(field, next),
                label: field.label || field.input,
                description: field.help,
            });
        }
        if (field.field === 'select' || field.field === 'multiselect') {
            const multiple = field.field === 'multiselect';
            return jsx(Select, {
                multiple,
                disabled: configSaving,
                value: multiple
                    ? Array.isArray(value)
                        ? value
                        : []
                    : value ?? null,
                options: (field.options ?? []).map((option) => ({
                    value: option.value,
                    label: option.label,
                })),
                onChange: (selected) => {
                    if (!multiple) {
                        updateConfigValue(
                            field,
                            field.options?.find((option) => option.value === selected)?.value ?? selected
                        );
                    } else {
                        updateConfigValue(field, selected);
                    }
                },
            });
        }
        if (field.field === 'textarea') {
            return jsx(TextArea, {
                className: 'w-full',
                'aria-label': field.label || field.input,
                disabled: configSaving,
                rows: 5,
                maxLength: field.constraints?.max_length,
                value: value ?? '',
                onChange: (event) => updateConfigValue(field, event.currentTarget.value),
            });
        }
        if (field.field === 'list') {
            const items = Array.isArray(value) ? value : [];
            const maxItems = field.constraints?.max_items;
            return jsxs('div', {
                className: 'space-y-2',
                children: [
                    ...items.map((item, index) =>
                        jsxs(
                            'div',
                            {
                                className: 'flex items-center gap-2',
                                children: [
                                    jsx(Input, {
                                        'aria-label': `${field.label || field.input} item ${index + 1}`,
                                        disabled: configSaving,
                                        value: item,
                                        onChange: (event) =>
                                            updateConfigValue(
                                                field,
                                                items.map((current, position) =>
                                                    position === index ? event.currentTarget.value : current
                                                )
                                            ),
                                    }),
                                    jsx(Button, {
                                        type: 'button',
                                        size: 'xsmall',
                                        color: 'grey',
                                        isSecondary: true,
                                        'aria-label': `Move item ${index + 1} up`,
                                        disabled: configSaving || index === 0,
                                        onClick: () => {
                                            const reordered = [...items];
                                            [reordered[index - 1], reordered[index]] = [
                                                reordered[index],
                                                reordered[index - 1],
                                            ];
                                            updateConfigValue(field, reordered);
                                        },
                                        children: 'Up',
                                    }),
                                    jsx(Button, {
                                        type: 'button',
                                        size: 'xsmall',
                                        color: 'grey',
                                        isSecondary: true,
                                        'aria-label': `Move item ${index + 1} down`,
                                        disabled: configSaving || index === items.length - 1,
                                        onClick: () => {
                                            const reordered = [...items];
                                            [reordered[index], reordered[index + 1]] = [
                                                reordered[index + 1],
                                                reordered[index],
                                            ];
                                            updateConfigValue(field, reordered);
                                        },
                                        children: 'Down',
                                    }),
                                    jsx(Button, {
                                        type: 'button',
                                        size: 'xsmall',
                                        color: 'grey',
                                        isSecondary: true,
                                        'aria-label': `Remove item ${index + 1}`,
                                        disabled: configSaving,
                                        onClick: () =>
                                            updateConfigValue(
                                                field,
                                                items.filter((_current, position) => position !== index)
                                            ),
                                        children: 'Remove',
                                    }),
                                ],
                            },
                            `${field.input}-${index}`
                        )
                    ),
                    jsx(Button, {
                        type: 'button',
                        size: 'xsmall',
                        isSecondary: true,
                        disabled: configSaving || (maxItems !== undefined && items.length >= maxItems),
                        onClick: () => updateConfigValue(field, [...items, '']),
                        children: 'Add item',
                    }),
                ],
            });
        }
        if (field.field === 'file') {
            return jsxs('div', {
                className: 'space-y-2',
                children: [
                    typeof field.value === 'string' &&
                        jsx('a', {
                            href: field.value,
                            target: '_blank',
                            rel: 'noreferrer',
                            className: 'block truncate text-xs text-primary underline',
                            children: field.value,
                        }),
                    jsx(FileInput, {
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
                    typeof field.value === 'string' &&
                        field.value.startsWith('/extension-files/') &&
                        jsx(Button, {
                            type: 'button',
                            color: 'red',
                            isSecondary: true,
                            disabled: configSaving,
                            onClick: () => void clearConfigFile(field),
                            children: 'Remove file',
                        }),
                    jsx('p', {
                        className: 'text-xs text-muted-foreground',
                        children: [
                            field.constraints?.accept?.join(', '),
                            field.constraints?.max_kilobytes
                                ? `up to ${field.constraints.max_kilobytes} KB`
                                : null,
                        ]
                            .filter(Boolean)
                            .join(' · '),
                    }),
                ],
            });
        }

        if (field.field === 'number') {
            return jsx(NumberInput, {
                'aria-label': field.label || field.input,
                disabled: configSaving,
                value: value === undefined || value === null || value === '' ? null : Number(value),
                min: field.constraints?.min,
                max: field.constraints?.max,
                onValueChange: (next) => updateConfigValue(field, next ?? ''),
            });
        }
        if (field.field === 'color') {
            const color = typeof value === 'string' ? value : '';
            const digits = /^#([\da-f]{3,4}|[\da-f]{6}|[\da-f]{8})$/i.exec(color)?.[1];
            const pickerValue = digits
                ? `#${digits.length <= 4 ? [...digits.slice(0, 3)].map((digit) => digit + digit).join('') : digits.slice(0, 6)}`
                : '#000000';
            return jsxs('div', {
                className: 'flex items-center gap-2',
                children: [
                    jsx('input', {
                        type: 'color',
                        'aria-label': `${field.label || field.input} picker`,
                        disabled: configSaving,
                        value: pickerValue,
                        onChange: (event) => updateConfigValue(field, event.currentTarget.value),
                    }),
                    jsx(Input, {
                        'aria-label': field.label || field.input,
                        disabled: configSaving,
                        value: color,
                        onChange: (event) => updateConfigValue(field, event.currentTarget.value),
                    }),
                ],
            });
        }

        return jsx(Input, {
            'aria-label': field.label || field.input,
            disabled: configSaving,
            type: field.field === 'password' ? 'password' : 'text',
            value: value ?? '',
            maxLength: field.constraints?.max_length,
            placeholder:
                field.field === 'password' && field.value
                    ? `Stored: ${String(field.value)} - enter a new value to replace it`
                    : undefined,
            onChange: (event) => updateConfigValue(field, event.currentTarget.value),
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
                setError(extensionErrorToHuman(cause));
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
            setError(extensionErrorToHuman(cause));
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
            setError(extensionErrorToHuman(cause));
            setBusy(false);
        }
    }, []);

    const remove = useCallback(async (id) => {
        const name = id.startsWith('drift-')
            ? id.replace('drift-', '').replace(/^\w/, (letter) => letter.toUpperCase())
            : id;
        if (!window.confirm(`Remove ${name}? Its settings and installed files will be deleted.`)) {
            return;
        }

        setBusy(true);
        setError(null);
        try {
            await http.delete(`/api/admin/extensions/${encodeURIComponent(id)}`);
            window.location.reload();
        } catch (cause) {
            setError(extensionErrorToHuman(cause));
            setBusy(false);
        }
    }, []);

    const items = bundledIds.map(
        (id) => extensions.find((extension) => extension.id === id) ?? { id, name: id, missing: true }
    );
    const configuringExtension = items.find((extension) => extension.id === configuring);
    const configurationName = configuringExtension
        ? configuringExtension.id.startsWith('drift-')
            ? configuringExtension.id
                  .replace('drift-', '')
                  .replace(/^\w/, (letter) => letter.toUpperCase())
            : configuringExtension.name || configuringExtension.id
        : '';
    const configSections = configSchema.some((field) => field.tab)
        ? configSchema.reduce((sections, field) => {
              const name = field.tab || 'General';
              const existing = sections.find((section) => section.name === name);
              if (existing) {
                  existing.fields.push(field);
              } else {
                  sections.push({ name, fields: [field] });
              }
              return sections;
          }, configSchema.some((field) => !field.tab) ? [{ name: 'General', fields: [] }] : [])
        : [{ name: 'General', fields: configSchema }];
    const activeConfigSection =
        configSections.find((section) => section.name === configSection) ?? configSections[0];

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
                                  ],
                              },
                              extension.id
                          );
                      }),
                  }),
            configuring &&
                jsxs(Dialog, {
                    open: true,
                    title: `${configurationName} settings`,
                    preventExternalClose: configSaving,
                    hideCloseIcon: configSaving,
                    onClose: () => setConfiguring(null),
                    children: [
                        jsx(SpinnerOverlay, { visible: configSaving }),
                        configError &&
                            jsx(Alert, {
                                type: 'danger',
                                children: jsx('span', { children: configError }),
                            }),
                        configNotice &&
                            jsx('p', {
                                role: 'status',
                                className: 'mb-3 text-sm text-muted-foreground',
                                children: configNotice,
                            }),
                        configLoading
                            ? jsx('div', {
                                  className: 'flex h-[min(30rem,calc(100dvh-14rem))] items-center justify-center',
                                  children: jsx(Spinner, { size: 'large', centered: true }),
                              })
                            : !configRegistered || configSchema.length === 0
                              ? jsx('div', {
                                    className:
                                        'flex h-[min(30rem,calc(100dvh-14rem))] items-center justify-center text-center text-sm text-muted-foreground',
                                    children: !configuringExtension?.enabled
                                        ? 'Enable this extension to load its settings.'
                                        : configRegistered
                                          ? "This extension doesn't register any settings."
                                          : 'This extension has not registered settings.',
                                })
                              : jsxs('div', {
                                    className: 'flex h-[min(30rem,calc(100dvh-14rem))] flex-col',
                                    children: [
                                        configSections.length > 1 &&
                                            jsx('div', {
                                                className: 'mb-4 shrink-0 border-b border-border pb-4',
                                                children: jsxs('label', {
                                                    className: 'block space-y-1 text-sm font-medium',
                                                    children: [
                                                        'Section',
                                                        jsx(Select, {
                                                            value:
                                                                activeConfigSection?.name ??
                                                                configSections[0]?.name ??
                                                                'General',
                                                            options: configSections.map((section) => ({
                                                                value: section.name,
                                                                label: section.name,
                                                            })),
                                                            disabled: configSaving,
                                                            onChange: (value) => setConfigSection(String(value)),
                                                        }),
                                                    ],
                                                }),
                                            }),
                                        jsx('div', {
                                            className: 'min-h-0 flex-1 overflow-y-auto overscroll-contain px-1 pb-1',
                                            children: activeConfigSection?.fields.map((field) =>
                                                field.field === 'toggle'
                                                    ? jsx('div', {
                                                          className: 'mb-5',
                                                          children: renderConfigControl(field),
                                                      }, field.input)
                                                    : jsxs(
                                                          'div',
                                                          {
                                                              className: 'mb-5 text-sm',
                                                              children: [
                                                                  jsx('label', {
                                                                      className: 'mb-1 block font-medium',
                                                                      children: field.label || field.input,
                                                                  }),
                                                                  renderConfigControl(field),
                                                                  field.help &&
                                                                      jsx('p', {
                                                                          className: 'mt-1 text-xs text-muted-foreground',
                                                                          children: field.help,
                                                                      }),
                                                              ],
                                                          },
                                                          field.input
                                                      )
                                            ),
                                        }),
                                    ],
                                }),
                        jsxs('div', {
                            className: 'mt-6 flex flex-wrap justify-end gap-2',
                            children: [
                                jsx(Button, {
                                    type: 'button',
                                    isSecondary: true,
                                    disabled: configSaving,
                                    onClick: () => setConfiguring(null),
                                    children: 'Cancel',
                                }),
                                jsx(Button, {
                                    type: 'button',
                                    disabled: configSaving || !configRegistered || configSchema.length === 0,
                                    isLoading: configSaving,
                                    onClick: () => void saveConfig(),
                                    children: 'Save Settings',
                                }),
                            ],
                        }),
                    ],
                }),
            jsx('p', {
                className: 'text-xs text-muted-foreground',
                children:
                    'Bundled extension settings use the panel settings dialog. Their internal identifiers stay unchanged for compatibility.',
            }),
        ],
    });
}
