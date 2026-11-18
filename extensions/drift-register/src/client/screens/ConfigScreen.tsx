import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    ContentBox,
    Input,
    Label,
    PageContentBlock,
    Spinner,
    Switch,
    httpErrorToHuman,
    toast,
} from '@pterodactyl/sdk';
import DiscordButton from '../DiscordButton';
import {
    getConfig,
    saveConfig,
    type FieldKey,
    type FieldMode,
    type RegisterConfig,
    type RegisterConfigInput,
    type SsoProviderInput,
} from '../api';
import { SSO_LABELS, SSO_PROVIDERS, type SsoProviderKey } from '../sso';

const queryKey = ['drift-register', 'config'] as const;
const undeliverable = ['log', 'array'];

/** Sections of the page, in order, for the floating navigation bar. */
const SECTIONS = [
    { id: 'drift-section-help', label: 'Help', title: 'Need help?' },
    { id: 'drift-section-registration', label: 'Registration', title: 'Registration' },
    { id: 'drift-section-demo', label: 'Demo mode', title: 'Demo mode' },
    { id: 'drift-section-form', label: 'Sign-up form', title: 'Sign-up form' },
    { id: 'drift-section-sso', label: 'Single sign-on', title: 'Single sign-on' },
] as const;

const MODES: { value: FieldMode; label: string }[] = [
    { value: 'required', label: 'Required' },
    { value: 'optional', label: 'Optional' },
    { value: 'hidden', label: 'Hidden' },
];

interface FieldRow {
    key: FieldKey;
    label: string;
    description: string;
}

const FORM_FIELDS: FieldRow[] = [
    { key: 'username', label: 'Username', description: 'Hidden or left blank: one is made from the email address.' },
    {
        key: 'first_name',
        label: 'First name',
        description: 'Hidden or left blank: the account is created with the name \"User\".',
    },
    {
        key: 'last_name',
        label: 'Last name',
        description: 'Hidden or left blank: the account is created with the name \"User\".',
    },
    { key: 'company', label: 'Company name', description: 'Saved by this extension; the Panel has no company field.' },
    { key: 'phone', label: 'Phone number', description: 'Saved by this extension; the Panel has no phone field.' },
    {
        key: 'password',
        label: 'Password',
        description:
            'Required: the account is created at once. Hidden, or optional and left blank: the visitor verifies their email first, then sets a password from the emailed link.',
    },
    {
        key: 'password_confirm',
        label: 'Retype password',
        description: 'Only applies while the password field is shown.',
    },
];

const SSO_FIELDS: Record<SsoProviderKey, string> = {
    google: 'Required: the visitor must connect a Google account to register. Optional: a \"Register with Google\" button is shown.',
    discord:
        'Required: the visitor must connect a Discord account to register. Optional: a \"Register with Discord\" button is shown.',
};

const SETUP_HINT: Record<SsoProviderKey, string> = {
    google: 'Create an OAuth client ID of type "Web application" in Google Cloud Console (APIs & Services, Credentials).',
    discord: 'Create an application in the Discord Developer Portal and copy its client ID and secret from the OAuth2 page.',
};

interface SsoForm {
    enabled: boolean;
    client_id: string;
    client_secret: string;
}

interface FormState {
    registration_enabled: boolean;
    hide_discord_button: boolean;
    demo_mode: boolean;
    demo_autofill: boolean;
    demo_email: string;
    demo_password: string;
    fields: Record<FieldKey, FieldMode>;
    verification_ttl_minutes: number;
    rate_limit_per_minute: number;
    sso: Record<SsoProviderKey, SsoForm>;
}

function toForm(config: RegisterConfig): FormState {
    const sso = {} as Record<SsoProviderKey, SsoForm>;
    for (const key of SSO_PROVIDERS) {
        sso[key] = {
            enabled: config.sso?.[key]?.enabled ?? false,
            client_id: config.sso?.[key]?.client_id ?? '',
            client_secret: '',
        };
    }

    return {
        registration_enabled: config.registration_enabled,
        hide_discord_button: config.hide_discord_button ?? false,
        demo_mode: config.demo_mode ?? false,
        demo_autofill: config.demo_autofill ?? false,
        demo_email: config.demo_email ?? '',
        demo_password: config.demo_password ?? '',
        fields: { ...config.fields },
        verification_ttl_minutes: config.verification_ttl_minutes,
        rate_limit_per_minute: config.rate_limit_per_minute,
        sso,
    };
}

/** A provider can be shown or required once it is switched on and has both credentials. */
function ssoReady(form: FormState, config: RegisterConfig | undefined, key: SsoProviderKey): boolean {
    const entry = form.sso[key];

    return entry.enabled && entry.client_id.trim() !== '' && (!!config?.sso?.[key]?.has_secret || entry.client_secret.trim() !== '');
}

function toInput(form: FormState, config: RegisterConfig | undefined): RegisterConfigInput {
    // A provider that is not set up cannot be shown or required, so it is saved as hidden.
    const fields = { ...form.fields };
    for (const key of SSO_PROVIDERS) {
        if (!ssoReady(form, config, key)) {
            fields[key] = 'hidden';
        }
    }

    const sso = {} as Record<SsoProviderKey, SsoProviderInput>;
    for (const key of SSO_PROVIDERS) {
        const { enabled, client_id, client_secret } = form.sso[key];
        // A blank secret is left out so the saved one is kept.
        sso[key] = client_secret.trim() === '' ? { enabled, client_id } : { enabled, client_id, client_secret };
    }

    return {
        registration_enabled: form.registration_enabled,
        hide_discord_button: form.hide_discord_button,
        demo_mode: form.demo_mode,
        demo_autofill: form.demo_autofill,
        demo_email: form.demo_email.trim(),
        demo_password: form.demo_password,
        fields,
        verification_ttl_minutes: form.verification_ttl_minutes,
        rate_limit_per_minute: form.rate_limit_per_minute,
        sso,
    };
}

/**
 * A small floating, frosted-glass bar that jumps between the sections of this page,
 * highlights the one being read, and holds the Save button. It sticks below the Panel's own top bar while scrolling.
 */
function SectionNav({
    active,
    onSelect,
    saving,
    onSave,
}: {
    active: string;
    onSelect: (id: string) => void;
    saving: boolean;
    onSave: () => void;
}) {
    return (
        <nav className='drift-nav' aria-label='Drift Register sections'>
            {SECTIONS.map((section) => (
                <button
                    key={section.id}
                    type='button'
                    className='drift-nav-link'
                    aria-current={active === section.id ? 'true' : undefined}
                    onClick={() => onSelect(section.id)}
                >
                    {section.label}
                </button>
            ))}
            <span className='drift-nav-sep' aria-hidden='true' />
            <button type='button' className='drift-nav-save' disabled={saving} aria-busy={saving} onClick={onSave}>
                Save
            </button>
        </nav>
    );
}

/** One form field with Required / Optional / Hidden buttons. */
function ModeRow({
    id,
    label,
    description,
    value,
    onChange,
    disabledModes,
    note,
}: {
    id: string;
    label: string;
    description: string;
    value: FieldMode;
    onChange: (mode: FieldMode) => void;
    disabledModes?: FieldMode[];
    note?: string;
}) {
    return (
        <div className='drift-mode-row'>
            <div style={{ minWidth: 0, flex: '1 1 14rem' }}>
                <div style={{ fontSize: '0.875rem', fontWeight: 500 }}>{label}</div>
                <p style={{ margin: '0.125rem 0 0', fontSize: '0.75rem', color: 'var(--muted-foreground)' }}>
                    {description}
                </p>
                {note && (
                    <p style={{ margin: '0.25rem 0 0', fontSize: '0.75rem', color: 'var(--destructive)' }}>{note}</p>
                )}
            </div>
            <div className='drift-mode-group' role='group' aria-label={`${label} on the sign-up form`}>
                {MODES.map((mode) => (
                    <button
                        key={mode.value}
                        id={`${id}-${mode.value}`}
                        type='button'
                        className='drift-mode-btn'
                        data-mode={mode.value}
                        aria-pressed={value === mode.value}
                        disabled={disabledModes?.includes(mode.value)}
                        onClick={() => onChange(mode.value)}
                    >
                        {mode.label}
                    </button>
                ))}
            </div>
        </div>
    );
}

export default function ConfigScreen() {
    const queryClient = useQueryClient();
    const config = useQuery({ queryKey, queryFn: getConfig });
    const [form, setForm] = useState<FormState | null>(null);
    const [activeSection, setActiveSection] = useState<string>(SECTIONS[0].id);
    const ready = form !== null;

    useEffect(() => {
        if (config.data) {
            setForm(toForm(config.data));
        }
    }, [config.data]);

    // Highlight the section being read. A thin band near the top of the screen decides which one;
    // it works whichever element scrolls, and is skipped where IntersectionObserver is missing.
    useEffect(() => {
        if (!ready || typeof IntersectionObserver === 'undefined') {
            return;
        }

        const visible = new Set<string>();
        const observer = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    if (entry.isIntersecting) {
                        visible.add(entry.target.id);
                    } else {
                        visible.delete(entry.target.id);
                    }
                }
                const current = SECTIONS.find((section) => visible.has(section.id));
                if (current) {
                    setActiveSection(current.id);
                }
            },
            { rootMargin: '-20% 0px -65% 0px' }
        );
        for (const section of SECTIONS) {
            const element = document.getElementById(section.id);
            if (element) {
                observer.observe(element);
            }
        }

        return () => observer.disconnect();
    }, [ready]);

    const goTo = (id: string) => {
        setActiveSection(id);
        const reduce = typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        document.getElementById(id)?.scrollIntoView?.({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
    };

    const save = useMutation({
        mutationFn: (input: RegisterConfigInput) => saveConfig(input),
        onSuccess: (saved: RegisterConfig) => {
            queryClient.setQueryData(queryKey, saved);
            toast.success('Settings saved.');
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    const setField = (key: FieldKey, mode: FieldMode) =>
        setForm((current) => (current ? { ...current, fields: { ...current.fields, [key]: mode } } : current));

    const setSso = (key: SsoProviderKey, patch: Partial<SsoForm>) =>
        setForm((current) => (current ? { ...current, sso: { ...current.sso, [key]: { ...current.sso[key], ...patch } } } : current));

    if (config.isLoading || !form) {
        return (
            <PageContentBlock title='Drift Register'>
                <ContentBox title='Registration'>
                    {config.isError ? <Alert type='danger'>{httpErrorToHuman(config.error)}</Alert> : <Spinner centered />}
                </ContentBox>
            </PageContentBlock>
        );
    }

    return (
        <PageContentBlock title='Drift Register'>
            <div style={{ display: 'grid', gap: '1rem' }}>
                <SectionNav
                    active={activeSection}
                    onSelect={goTo}
                    saving={save.isPending}
                    onSave={() => save.mutate(toInput(form, config.data))}
                />

                <div id='drift-section-help' className='drift-section'>
<ContentBox title='Need help?'>
                    <div style={{ display: 'grid', gap: '0.75rem', maxWidth: '24rem' }}>
                        <p style={{ margin: 0, fontSize: '0.875rem', color: 'var(--muted-foreground)' }}>
                            Questions about setting up registration or single sign-on? Join our Discord for help.
                        </p>
                        <DiscordButton />
                    </div>
                </ContentBox>
                </div>

                <div id='drift-section-registration' className='drift-section'>
<ContentBox title='Registration'>
                    <div style={{ display: 'grid', gap: '1.25rem', maxWidth: '36rem' }}>
                        <Switch
                            label='Enable registration'
                            description='Show a "Create an account" form on the login page.'
                            checked={form.registration_enabled}
                            onChange={(checked) => setForm({ ...form, registration_enabled: checked })}
                        />
                        <Switch
                            label='Hide the "Join our Discord" button'
                            description='Hides it on the login and register pages. The admin pages keep it.'
                            checked={form.hide_discord_button}
                            onChange={(checked) => setForm({ ...form, hide_discord_button: checked })}
                        />
                        <div>
                            <Label htmlFor='drift-ttl'>Verification link lifetime (minutes)</Label>
                            <Input
                                id='drift-ttl'
                                type='number'
                                min={5}
                                max={1440}
                                value={form.verification_ttl_minutes}
                                onChange={(event) =>
                                    setForm({ ...form, verification_ttl_minutes: Number(event.target.value) })
                                }
                            />
                            <p className='drift:mt-1 drift:text-xs drift:text-muted-foreground'>
                                Used when the visitor verifies their email first (password hidden, or optional and left blank).
                            </p>
                        </div>
                        <div>
                            <Label htmlFor='drift-rate-limit'>Sign-up rate limit (per IP, per minute)</Label>
                            <Input
                                id='drift-rate-limit'
                                type='number'
                                min={1}
                                max={100}
                                value={form.rate_limit_per_minute}
                                onChange={(event) =>
                                    setForm({ ...form, rate_limit_per_minute: Number(event.target.value) })
                                }
                            />
                            <p className='drift:mt-1 drift:text-xs drift:text-muted-foreground'>
                                How many registration attempts one IP address may make each minute before it gets a "too
                                many attempts" response.
                            </p>
                        </div>
                        {form.fields.password !== 'required' && undeliverable.includes(config.data?.mail_driver ?? '') && (
                            <Alert type='warning' title='Mail is not configured'>
                                The Panel's mail driver is "{config.data?.mail_driver}", so verification emails will not be
                                delivered. Configure mail in the Panel settings first.
                            </Alert>
                        )}
                        {form.fields.password !== 'required' && (config.data?.pending_count ?? 0) > 0 && (
                            <p className='drift:m-0 drift:text-sm'>
                                {config.data?.pending_count} sign-up(s) are waiting for email verification.
                            </p>
                        )}
                    </div>
                </ContentBox>
                </div>

                <div id='drift-section-demo' className='drift-section'>
                    <ContentBox title='Demo mode'>
                        <div style={{ display: 'grid', gap: '1.25rem', maxWidth: '36rem' }}>
                            <Switch
                                label='Demo mode'
                                description='Turn on the demo options below, for a public demo of your Panel.'
                                checked={form.demo_mode}
                                onChange={(checked) => setForm({ ...form, demo_mode: checked })}
                            />
                            {form.demo_mode && (
                                <>
                                    <Switch
                                        label='Auto-fill the demo login'
                                        description='Fills the login form with the demo email and password so visitors only press Login.'
                                        checked={form.demo_autofill}
                                        onChange={(checked) => setForm({ ...form, demo_autofill: checked })}
                                    />
                                    <div>
                                        <Label htmlFor='drift-demo-email'>Demo email</Label>
                                        <Input
                                            id='drift-demo-email'
                                            type='text'
                                            autoComplete='off'
                                            value={form.demo_email}
                                            onChange={(event) => setForm({ ...form, demo_email: event.target.value })}
                                        />
                                        <p className='drift:mt-1 drift:text-xs drift:text-muted-foreground'>
                                            The username or email filled into the login form.
                                        </p>
                                    </div>
                                    <div>
                                        <Label htmlFor='drift-demo-password'>Demo password</Label>
                                        <Input
                                            id='drift-demo-password'
                                            type='text'
                                            autoComplete='off'
                                            value={form.demo_password}
                                            onChange={(event) => setForm({ ...form, demo_password: event.target.value })}
                                        />
                                        <p className='drift:mt-1 drift:text-xs drift:text-muted-foreground'>
                                            Shown as plain text here and sent to every visitor of the login page.
                                        </p>
                                    </div>
                                    {form.demo_autofill && (
                                        <Alert type='warning' title='This login is public'>
                                            Anyone who opens the login page can read the demo email and password. Use a
                                            low-privilege account that owns no real data, never an administrator.
                                        </Alert>
                                    )}
                                </>
                            )}
                        </div>
                    </ContentBox>
                </div>

                <div id='drift-section-form' className='drift-section'>
<ContentBox title='Sign-up form'>
                    <div style={{ maxWidth: '44rem' }}>
                        <p style={{ margin: '0 0 0.75rem', fontSize: '0.875rem', color: 'var(--muted-foreground)' }}>
                            Choose what visitors see on the sign-up page. The email address is always required.
                        </p>
                        {FORM_FIELDS.map((field) => (
                            <ModeRow
                                key={field.key}
                                id={`drift-field-${field.key}`}
                                label={field.label}
                                description={field.description}
                                value={form.fields[field.key]}
                                onChange={(mode) => setField(field.key, mode)}
                                disabledModes={
                                    field.key === 'password_confirm' && form.fields.password === 'hidden'
                                        ? ['required', 'optional']
                                        : undefined
                                }
                                note={
                                    field.key === 'password_confirm' && form.fields.password === 'hidden'
                                        ? 'Not used while the password field is hidden.'
                                        : undefined
                                }
                            />
                        ))}
                        {SSO_PROVIDERS.map((key) => {
                            const ready = ssoReady(form, config.data, key);

                            return (
                                <ModeRow
                                    key={key}
                                    id={`drift-field-${key}`}
                                    label={`${SSO_LABELS[key]} sign-up`}
                                    description={SSO_FIELDS[key]}
                                    value={ready ? form.fields[key] : 'hidden'}
                                    onChange={(mode) => setField(key, mode)}
                                    disabledModes={ready ? undefined : ['required', 'optional']}
                                    note={
                                        ready
                                            ? undefined
                                            : `Set up and enable ${SSO_LABELS[key]} sign-in below to use this.`
                                    }
                                />
                            );
                        })}
                    </div>
                </ContentBox>
                </div>

                <div id='drift-section-sso' className='drift-section'>
<ContentBox title='Single sign-on'>
                    <div style={{ display: 'grid', gap: '1.5rem', maxWidth: '36rem' }}>
                        <p style={{ margin: 0, fontSize: '0.875rem', color: 'var(--muted-foreground)' }}>
                            Let people sign in, register and link their account with Google or Discord. Sign-in only
                            works for accounts that were created with, or linked to, that provider; registering with a
                            provider also needs "Enable registration" above.
                        </p>
                        {SSO_PROVIDERS.map((key) => {
                            const label = SSO_LABELS[key];
                            const saved = config.data?.sso?.[key];

                            return (
                                <div
                                    key={key}
                                    style={{
                                        display: 'grid',
                                        gap: '1rem',
                                        paddingTop: '1.25rem',
                                        borderTop: '1px solid var(--border)',
                                    }}
                                >
                                    <Switch
                                        label={`Enable ${label} sign-in`}
                                        description={`Adds ${label} to the login page, the sign-up page and the account page.`}
                                        checked={form.sso[key].enabled}
                                        onChange={(checked) => setSso(key, { enabled: checked })}
                                    />
                                    <p style={{ margin: 0, fontSize: '0.75rem', color: 'var(--muted-foreground)' }}>
                                        {SETUP_HINT[key]}
                                    </p>
                                    <div>
                                        <Label htmlFor={`drift-${key}-id`}>{label} client ID</Label>
                                        <Input
                                            id={`drift-${key}-id`}
                                            type='text'
                                            autoComplete='off'
                                            value={form.sso[key].client_id}
                                            onChange={(event) => setSso(key, { client_id: event.target.value })}
                                        />
                                    </div>
                                    <div>
                                        <Label htmlFor={`drift-${key}-secret`}>{label} client secret</Label>
                                        <Input
                                            id={`drift-${key}-secret`}
                                            type='password'
                                            autoComplete='new-password'
                                            placeholder={saved?.has_secret ? 'Saved. Leave blank to keep it.' : ''}
                                            value={form.sso[key].client_secret}
                                            onChange={(event) => setSso(key, { client_secret: event.target.value })}
                                        />
                                    </div>
                                    {saved?.redirect_uri && (
                                        <div>
                                            <Label htmlFor={`drift-${key}-redirect`}>
                                                Redirect URI (enter this at {label})
                                            </Label>
                                            <Input
                                                id={`drift-${key}-redirect`}
                                                type='text'
                                                readOnly
                                                value={saved.redirect_uri}
                                                onFocus={(event) => event.currentTarget.select()}
                                            />
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </ContentBox>
                </div>

            </div>
        </PageContentBlock>
    );
}
