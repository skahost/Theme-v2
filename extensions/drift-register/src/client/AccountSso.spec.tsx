import { afterEach, expect, test, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createExtensionTestHost } from '@pterodactyl/sdk/testing';
import AccountSso from './AccountSso';
import { getSsoConnections, unlinkSso } from './api';

vi.mock('./api', async (importOriginal) => ({
    ...(await importOriginal<typeof import('./api')>()),
    getSsoConnections: vi.fn(),
    unlinkSso: vi.fn(),
}));

let host: ReturnType<typeof createExtensionTestHost> | undefined;
const mount = () => {
    host = createExtensionTestHost({ extensionId: 'drift-register', prefix: 'drift' });
    render(<AccountSso />, { wrapper: host.Wrapper });
};

afterEach(() => {
    cleanup();
    host?.dispose();
    host = undefined;
    vi.resetAllMocks();
});

test('offers to link a provider that is enabled and not linked yet', async () => {
    vi.mocked(getSsoConnections).mockResolvedValue([
        { provider: 'google', label: 'Google', available: true, linked: false, email: null },
        { provider: 'discord', label: 'Discord', available: false, linked: false, email: null },
    ]);
    mount();
    expect(await screen.findByRole('button', { name: 'Link Google' })).toBeTruthy();
    expect(screen.queryByText('Discord')).toBeNull();
});

test('unlinks a linked provider after a second click', async () => {
    vi.mocked(getSsoConnections).mockResolvedValue([
        { provider: 'discord', label: 'Discord', available: false, linked: true, email: 'me@example.com' },
    ]);
    vi.mocked(unlinkSso).mockResolvedValue(undefined);
    mount();

    expect(await screen.findByText(/me@example.com/)).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Unlink' }));
    fireEvent.click(screen.getByRole('button', { name: 'Confirm unlink' }));

    await waitFor(() => expect(unlinkSso).toHaveBeenCalledWith('discord'));
});

test('renders nothing when no provider is enabled or linked', async () => {
    vi.mocked(getSsoConnections).mockResolvedValue([
        { provider: 'google', label: 'Google', available: false, linked: false, email: null },
    ]);
    mount();
    await waitFor(() => expect(getSsoConnections).toHaveBeenCalled());
    expect(screen.queryByText('Single sign-on')).toBeNull();
});
