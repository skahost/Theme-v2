import { afterEach, expect, test, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createExtensionTestHost } from '@pterodactyl/sdk/testing';
import UserScreen from './UserScreen';
import { getUserDetails, saveUserDetails, type UserDetails } from '../api';

vi.mock('../api', async (importOriginal) => ({
    ...(await importOriginal<typeof import('../api')>()),
    getUserDetails: vi.fn(),
    saveUserDetails: vi.fn(),
}));

const discord = {
    provider: 'discord' as const,
    label: 'Discord',
    email: 'jane@discord.example',
    username: 'jane#1234',
    display_name: 'Jane D',
    avatar_url: null,
    linked_at: '2026-01-02T03:04:05+00:00',
    last_login_at: '2026-02-03T04:05:06+00:00',
};
const google = {
    provider: 'google' as const,
    label: 'Google',
    email: 'jane@gmail.example',
    username: null,
    display_name: 'Jane Doe',
    avatar_url: null,
    linked_at: '2026-01-02T03:04:05+00:00',
    last_login_at: null,
};

const user: UserDetails = {
    id: 7,
    uuid: '11111111-2222-3333-4444-555555555555',
    username: 'jane',
    email: 'jane@example.com',
    first_name: 'Jane',
    last_name: 'Doe',
    language: 'en',
    root_admin: false,
    two_factor: true,
    created_at: '2026-01-02T03:04:05+00:00',
    company: 'Acme',
    phone: '+1 555 0100',
    notes: 'Pays yearly',
    signup: { method: 'discord', ip_address: '203.0.113.9', user_agent: 'Mozilla/5.0', at: '2026-01-02T03:04:05+00:00' },
    discord: { username: 'jane#1234', email: 'jane@discord.example' },
    sso: [discord, google],
};

let host: ReturnType<typeof createExtensionTestHost> | undefined;

const mount = () => {
    host = createExtensionTestHost({ extensionId: 'drift-register', prefix: 'drift' });
    host.queryClient.setDefaultOptions({ queries: { retry: false } });
    const data = { pathname: '/panel/users/7/drift-register', search: {}, params: { id: '7' } };
    render(<UserScreen data={data as never} />, { wrapper: host.Wrapper });
};

afterEach(() => {
    cleanup();
    host?.dispose();
    host = undefined;
    vi.resetAllMocks();
});

test('shows company, phone and notes, and the Discord and Google profiles', async () => {
    vi.mocked(getUserDetails).mockResolvedValue(user);
    mount();

    expect(((await screen.findByLabelText('Company Name')) as HTMLInputElement).value).toBe('Acme');
    expect((screen.getByLabelText('Phone Number') as HTMLInputElement).value).toBe('+1 555 0100');
    expect((screen.getByLabelText('Admin notes') as HTMLTextAreaElement).value).toBe('Pays yearly');
    expect(screen.getByText('jane#1234')).toBeTruthy();
    expect(screen.getByText('jane@gmail.example')).toBeTruthy();
    expect(screen.getByText('203.0.113.9')).toBeTruthy();
    expect(getUserDetails).toHaveBeenCalledWith(7);
});

test('says so when Discord and Google are not connected, and company and phone are empty', async () => {
    vi.mocked(getUserDetails).mockResolvedValue({
        ...user,
        company: null,
        phone: null,
        notes: null,
        signup: null,
        discord: null,
        sso: [],
    });
    mount();

    expect(((await screen.findByLabelText('Company Name')) as HTMLInputElement).value).toBe('');
    expect((screen.getByLabelText('Phone Number') as HTMLInputElement).value).toBe('');
    expect(screen.getByText('No Discord account connected.')).toBeTruthy();
    expect(screen.getByText('No Google account connected.')).toBeTruthy();
    expect(screen.getByText('Not recorded')).toBeTruthy();
});

test('company, phone and notes can be edited and saved', async () => {
    vi.mocked(getUserDetails).mockResolvedValue(user);
    vi.mocked(saveUserDetails).mockResolvedValue({ ...user, company: 'Globex', phone: '123', notes: 'VIP' });
    mount();

    fireEvent.change(await screen.findByLabelText('Company Name'), { target: { value: 'Globex' } });
    fireEvent.change(screen.getByLabelText('Phone Number'), { target: { value: '123' } });
    fireEvent.change(screen.getByLabelText('Admin notes'), { target: { value: 'VIP' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save Changes' }));

    await waitFor(() =>
        expect(saveUserDetails).toHaveBeenCalledWith(7, { company: 'Globex', phone: '123', notes: 'VIP' }),
    );
});

test('shows the error when the details cannot be loaded', async () => {
    vi.mocked(getUserDetails).mockRejectedValue(new Error('boom'));
    mount();

    expect(await screen.findByText(/boom/)).toBeTruthy();
});
