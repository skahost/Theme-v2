import { afterEach, expect, test, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createExtensionTestHost } from '@pterodactyl/sdk/testing';
import ConfigScreen from './ConfigScreen';
import { getConfig, saveConfig } from '../api';

vi.mock('../api', async (importOriginal) => ({
    ...(await importOriginal<typeof import('../api')>()),
    getConfig: vi.fn(),
    saveConfig: vi.fn(),
}));

const fields = {
    username: 'required',
    first_name: 'required',
    last_name: 'required',
    company: 'hidden',
    phone: 'hidden',
    password: 'required',
    password_confirm: 'required',
    discord: 'optional',
    google: 'optional',
} as const;

const base = {
    registration_enabled: false,
    fields,
    verification_ttl_minutes: 60,
    rate_limit_per_minute: 5,
    mail_driver: 'smtp',
    pending_count: 0,
};

let host: ReturnType<typeof createExtensionTestHost> | undefined;
afterEach(() => {
    cleanup();
    host?.dispose();
    host = undefined;
    vi.resetAllMocks();
});

test('saves the registration toggle', async () => {
    vi.mocked(getConfig).mockResolvedValue(base);
    vi.mocked(saveConfig).mockResolvedValue({ ...base, registration_enabled: true });

    host = createExtensionTestHost({ extensionId: 'drift-register', prefix: 'drift' });
    render(<ConfigScreen />, { wrapper: host.Wrapper });

    fireEvent.click(await screen.findByRole('switch', { name: /enable registration/i }));
    fireEvent.click(screen.getByRole('button', { name: /save/i }));

    await waitFor(() =>
        expect(saveConfig).toHaveBeenCalledWith(
            expect.objectContaining({
                registration_enabled: true,
                fields: expect.objectContaining({ password: 'required' }),
                verification_ttl_minutes: 60,
                rate_limit_per_minute: 5,
            })
        )
    );
});

test('saves the hide Join our Discord option', async () => {
    vi.mocked(getConfig).mockResolvedValue(base);
    vi.mocked(saveConfig).mockResolvedValue({ ...base, hide_discord_button: true });

    host = createExtensionTestHost({ extensionId: 'drift-register', prefix: 'drift' });
    render(<ConfigScreen />, { wrapper: host.Wrapper });

    fireEvent.click(await screen.findByRole('switch', { name: /hide the "join our discord" button/i }));
    fireEvent.click(screen.getByRole('button', { name: /save/i }));

    await waitFor(() => expect(saveConfig).toHaveBeenCalledWith(expect.objectContaining({ hide_discord_button: true })));
});

test('Save sits in the floating section bar', async () => {
    vi.mocked(getConfig).mockResolvedValue(base);

    host = createExtensionTestHost({ extensionId: 'drift-register', prefix: 'drift' });
    const view = render(<ConfigScreen />, { wrapper: host.Wrapper });

    await screen.findByLabelText(/sign-up rate limit/i);
    expect(screen.getAllByRole('button', { name: /^save$/i })).toHaveLength(1);
    expect(view.container.querySelector('.drift-nav .drift-nav-save')).not.toBeNull();
});

test('saves the demo mode options', async () => {
    vi.mocked(getConfig).mockResolvedValue(base);
    vi.mocked(saveConfig).mockResolvedValue(base);

    host = createExtensionTestHost({ extensionId: 'drift-register', prefix: 'drift' });
    render(<ConfigScreen />, { wrapper: host.Wrapper });

    expect(await screen.findByRole('switch', { name: /^demo mode$/i })).toBeTruthy();
    expect(screen.queryByLabelText('Demo email')).toBeNull();
    fireEvent.click(screen.getByRole('switch', { name: /^demo mode$/i }));
    fireEvent.click(screen.getByRole('switch', { name: /auto-fill the demo login/i }));
    fireEvent.change(screen.getByLabelText('Demo email'), { target: { value: ' demo@example.com ' } });
    fireEvent.change(screen.getByLabelText('Demo password'), { target: { value: 'demo-pass' } });
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }));

    await waitFor(() =>
        expect(saveConfig).toHaveBeenCalledWith(
            expect.objectContaining({
                demo_mode: true,
                demo_autofill: true,
                demo_email: 'demo@example.com',
                demo_password: 'demo-pass',
            })
        )
    );
});

test('saves a new rate limit', async () => {
    vi.mocked(getConfig).mockResolvedValue(base);
    vi.mocked(saveConfig).mockResolvedValue({ ...base, rate_limit_per_minute: 12 });

    host = createExtensionTestHost({ extensionId: 'drift-register', prefix: 'drift' });
    render(<ConfigScreen />, { wrapper: host.Wrapper });

    fireEvent.change(await screen.findByLabelText(/sign-up rate limit/i), { target: { value: '12' } });
    fireEvent.click(screen.getByRole('button', { name: /save/i }));

    await waitFor(() =>
        expect(saveConfig).toHaveBeenCalledWith(
            expect.objectContaining({
                registration_enabled: false,
                fields: expect.objectContaining({ password: 'required' }),
                verification_ttl_minutes: 60,
                rate_limit_per_minute: 12,
            })
        )
    );
});

test('warns when passwordless mode cannot deliver mail', async () => {
    vi.mocked(getConfig).mockResolvedValue({ ...base, fields: { ...fields, password: 'hidden' }, mail_driver: 'log' });

    host = createExtensionTestHost({ extensionId: 'drift-register', prefix: 'drift' });
    render(<ConfigScreen />, { wrapper: host.Wrapper });

    expect(await screen.findByText(/mail is not configured/i)).toBeTruthy();
});

test('always shows the verification lifetime and rate limit options', async () => {
    vi.mocked(getConfig).mockResolvedValue(base);

    host = createExtensionTestHost({ extensionId: 'drift-register', prefix: 'drift' });
    render(<ConfigScreen />, { wrapper: host.Wrapper });

    expect(await screen.findByLabelText('Verification link lifetime (minutes)')).toBeTruthy();
    expect(screen.getByText('Used when the visitor verifies their email first (password hidden, or optional and left blank).')).toBeTruthy();
    expect(screen.getByLabelText('Sign-up rate limit (per IP, per minute)')).toBeTruthy();
});

const sso = {
    google: { enabled: false, client_id: '', has_secret: false, ready: false, redirect_uri: 'https://panel.test/extensions/drift-register/sso/google/callback' },
    discord: { enabled: false, client_id: '', has_secret: true, ready: false, redirect_uri: 'https://panel.test/extensions/drift-register/sso/discord/callback' },
};

test('shows the redirect URIs and never asks to re-enter a saved secret', async () => {
    vi.mocked(getConfig).mockResolvedValue({ ...base, sso });

    host = createExtensionTestHost({ extensionId: 'drift-register', prefix: 'drift' });
    render(<ConfigScreen />, { wrapper: host.Wrapper });

    expect(await screen.findByDisplayValue(sso.google.redirect_uri)).toBeTruthy();
    expect(screen.getByDisplayValue(sso.discord.redirect_uri)).toBeTruthy();
    expect((screen.getByLabelText('Discord client secret') as HTMLInputElement).placeholder).toMatch(/saved/i);
});

test('saves Google credentials, and leaves the secret out when it is blank', async () => {
    vi.mocked(getConfig).mockResolvedValue({ ...base, sso });
    vi.mocked(saveConfig).mockResolvedValue({ ...base, sso });

    host = createExtensionTestHost({ extensionId: 'drift-register', prefix: 'drift' });
    render(<ConfigScreen />, { wrapper: host.Wrapper });

    fireEvent.change(await screen.findByLabelText('Google client ID'), { target: { value: 'abc.apps.googleusercontent.com' } });
    fireEvent.change(screen.getByLabelText('Google client secret'), { target: { value: 's3cret' } });
    fireEvent.click(screen.getByRole('switch', { name: /enable google sign-in/i }));
    fireEvent.click(screen.getByRole('button', { name: /save/i }));

    await waitFor(() => expect(saveConfig).toHaveBeenCalled());
    const sent = vi.mocked(saveConfig).mock.calls[0][0];
    expect(sent.sso?.google).toEqual({ enabled: true, client_id: 'abc.apps.googleusercontent.com', client_secret: 's3cret' });
    expect(sent.sso?.discord).toEqual({ enabled: false, client_id: '' });
});

test('company name can be shown as required', async () => {
    vi.mocked(getConfig).mockResolvedValue(base);
    vi.mocked(saveConfig).mockResolvedValue(base);

    host = createExtensionTestHost({ extensionId: 'drift-register', prefix: 'drift' });
    const view = render(<ConfigScreen />, { wrapper: host.Wrapper });

    await screen.findByText('Company name');
    fireEvent.click(view.container.querySelector('#drift-field-company-required') as HTMLElement);
    fireEvent.click(screen.getByRole('button', { name: /save/i }));

    await waitFor(() => expect(saveConfig).toHaveBeenCalled());
    expect(vi.mocked(saveConfig).mock.calls[0][0].fields.company).toBe('required');
});

test('a sign-in provider that is not set up cannot be shown or required, and is saved as hidden', async () => {
    vi.mocked(getConfig).mockResolvedValue({ ...base, sso });
    vi.mocked(saveConfig).mockResolvedValue({ ...base, sso });

    host = createExtensionTestHost({ extensionId: 'drift-register', prefix: 'drift' });
    const view = render(<ConfigScreen />, { wrapper: host.Wrapper });

    await screen.findByText('Google sign-up');
    expect((view.container.querySelector('#drift-field-google-required') as HTMLButtonElement).disabled).toBe(true);
    expect((view.container.querySelector('#drift-field-discord-optional') as HTMLButtonElement).disabled).toBe(true);

    fireEvent.click(screen.getByRole('button', { name: /save/i }));
    await waitFor(() => expect(saveConfig).toHaveBeenCalled());
    const sent = vi.mocked(saveConfig).mock.calls[0][0];
    expect(sent.fields.google).toBe('hidden');
    expect(sent.fields.discord).toBe('hidden');
});

test('offers a Join our Discord link for help', async () => {
    vi.mocked(getConfig).mockResolvedValue(base);

    host = createExtensionTestHost({ extensionId: 'drift-register', prefix: 'drift' });
    render(<ConfigScreen />, { wrapper: host.Wrapper });

    const link = (await screen.findByText('Join our Discord')).closest('a');
    expect(link?.getAttribute('href')).toBe('https://discord.gg/eFQZayYSGN');
});

test('the floating navigation bar lists the page sections and jumps to one', async () => {
    vi.mocked(getConfig).mockResolvedValue(base);
    const scrollIntoView = vi.fn();
    Element.prototype.scrollIntoView = scrollIntoView;

    host = createExtensionTestHost({ extensionId: 'drift-register', prefix: 'drift' });
    render(<ConfigScreen />, { wrapper: host.Wrapper });

    for (const name of ['Help', 'Registration', 'Sign-up form', 'Single sign-on']) {
        expect(await screen.findByRole('button', { name })).toBeTruthy();
    }

    fireEvent.click(screen.getByRole('button', { name: 'Sign-up form' }));
    expect(scrollIntoView).toHaveBeenCalled();
    expect(screen.getByRole('button', { name: 'Sign-up form' }).getAttribute('aria-current')).toBe('true');
});
