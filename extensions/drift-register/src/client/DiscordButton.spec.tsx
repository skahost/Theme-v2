import { afterEach, expect, test } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { createExtensionTestHost } from '@pterodactyl/sdk/testing';
import { AdminDiscord, DISCORD_URL } from './DiscordButton';

afterEach(cleanup);

test('the admin overview button links to the Discord invite in a new tab', async () => {
    const host = createExtensionTestHost({ extensionId: 'drift-register', prefix: 'drift' });
    render(<AdminDiscord />, { wrapper: host.Wrapper });
    const link = (await screen.findByText('Join our Discord')).closest('a');
    expect(link?.getAttribute('href')).toBe(DISCORD_URL);
    expect(link?.getAttribute('target')).toBe('_blank');
    expect(DISCORD_URL).toBe('https://discord.gg/eFQZayYSGN');
    host.dispose();
});
