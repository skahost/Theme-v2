import { afterEach, expect, test, vi } from 'vitest';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import { createExtensionTestHost } from '@pterodactyl/sdk/testing';
import RegisterPrompt, { REGISTER_PATH } from './RegisterPrompt';
import { getStatus } from './api';

vi.mock('./api', async (importOriginal) => ({
    ...(await importOriginal<typeof import('./api')>()),
    getStatus: vi.fn(),
}));

let host: ReturnType<typeof createExtensionTestHost> | undefined;
const mount = () => {
    host = createExtensionTestHost({ extensionId: 'drift-register', prefix: 'drift' });
    render(<RegisterPrompt />, { wrapper: host.Wrapper });
};

afterEach(() => {
    cleanup();
    host?.dispose();
    host = undefined;
    vi.resetAllMocks();
});

test('shows only the Discord button while registration is off', async () => {
    vi.mocked(getStatus).mockResolvedValue({ enabled: false });
    mount();
    expect(await screen.findByText('Join our Discord')).toBeTruthy();
    expect(screen.queryByText('Create an account')).toBeNull();
});

test('the Register button links to the standalone /register page', async () => {
    vi.mocked(getStatus).mockResolvedValue({ enabled: true });
    mount();
    const link = await screen.findByRole('link', { name: 'Create an account' });
    expect(link.getAttribute('href')).toBe(REGISTER_PATH);
    expect(REGISTER_PATH).toBe('/register');
});

test('Join our Discord is a link that opens the invite in a new tab', async () => {
    vi.mocked(getStatus).mockResolvedValue({ enabled: true });
    mount();
    const link = (await screen.findByText('Join our Discord')).closest('a');
    expect(link?.getAttribute('href')).toBe('https://discord.gg/eFQZayYSGN');
    expect(link?.getAttribute('target')).toBe('_blank');
    expect(link?.getAttribute('rel')).toContain('noopener');
});

test('falls back to the Discord button when the status request fails', async () => {
    vi.mocked(getStatus).mockRejectedValue(new Error('network'));
    mount();
    expect(await screen.findByText('Join our Discord')).toBeTruthy();
    expect(screen.queryByText('Create an account')).toBeNull();
});

test('hides the Discord button when an admin turned it off', async () => {
    vi.mocked(getStatus).mockResolvedValue({ enabled: true, hide_discord: true });
    mount();
    expect(await screen.findByRole('link', { name: 'Create an account' })).toBeTruthy();
    expect(screen.queryByText('Join our Discord')).toBeNull();
});

test('offers only the enabled single sign-on providers', async () => {
    vi.mocked(getStatus).mockResolvedValue({
        enabled: true,
        sso: { google: true, discord: false },
    });
    mount();
    const link = await screen.findByRole('link', { name: /sign in with google/i });
    expect(link.getAttribute('href')).toBe('/extensions/drift-register/sso/google/redirect?mode=login');
    expect(screen.queryByRole('link', { name: /sign in with discord/i })).toBeNull();
});

test('tells a visitor with no linked account to register with that provider', async () => {
    window.history.pushState({}, '', '/auth/login?sso_error=not_registered&sso_provider=google');
    try {
        vi.mocked(getStatus).mockResolvedValue({
            enabled: true,
                sso: { google: true },
        });
        mount();
        expect((await screen.findByRole('alert')).textContent).toMatch(/no account is linked/i);
        const link = await screen.findByRole('link', { name: /register with google/i });
        expect(link.getAttribute('href')).toBe('/extensions/drift-register/sso/google/redirect?mode=register');
    } finally {
        window.history.pushState({}, '', '/');
    }
});

test('demo mode fills the login form with the demo email and password', async () => {
    vi.mocked(getStatus).mockResolvedValue({ enabled: false, demo: { email: 'demo@example.com', password: 'demo-pass' } });
    host = createExtensionTestHost({ extensionId: 'drift-register', prefix: 'drift' });
    const Form = () => (
        <form>
            <input type='text' aria-label='user' />
            <input type='password' aria-label='pass' />
            <RegisterPrompt />
        </form>
    );
    render(<Form />, { wrapper: host.Wrapper });

    await waitFor(() => expect((screen.getByLabelText('user') as HTMLInputElement).value).toBe('demo@example.com'));
    expect((screen.getByLabelText('pass') as HTMLInputElement).value).toBe('demo-pass');
});

test('without demo credentials the login form is left empty', async () => {
    vi.mocked(getStatus).mockResolvedValue({ enabled: false, demo: null });
    host = createExtensionTestHost({ extensionId: 'drift-register', prefix: 'drift' });
    const Form = () => (
        <form>
            <input type='text' aria-label='user' />
            <input type='password' aria-label='pass' />
            <RegisterPrompt />
        </form>
    );
    render(<Form />, { wrapper: host.Wrapper });

    await screen.findByText('Join our Discord');
    expect((screen.getByLabelText('user') as HTMLInputElement).value).toBe('');
});
