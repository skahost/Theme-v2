import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, ContentBox, httpErrorToHuman, toast } from '@pterodactyl/sdk';
import { getSsoConnections, unlinkSso } from './api';
import { SSO_LABELS, readSsoQuery, ssoErrorMessage, ssoLinkUrl, type SsoProviderKey } from './sso';

const queryKey = ['drift-register', 'sso'] as const;

/**
 * Rendered on the account overview (slot account.overview.after). Lets a signed-in user
 * link Google or Discord to this account, so they can sign in with it, or unlink it again.
 */
export default function AccountSso() {
    const queryClient = useQueryClient();
    const connections = useQuery({ queryKey, queryFn: getSsoConnections });
    const [confirming, setConfirming] = useState<SsoProviderKey | null>(null);
    const result = useMemo(() => readSsoQuery(window.location.search), []);

    const unlink = useMutation({
        mutationFn: (provider: SsoProviderKey) => unlinkSso(provider),
        onSuccess: async (_data, provider) => {
            setConfirming(null);
            await queryClient.invalidateQueries({ queryKey });
            toast.success(`${SSO_LABELS[provider]} unlinked.`);
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    // Linked providers always show, so they can be unlinked; unlinked ones only while enabled.
    const rows = (connections.data ?? []).filter((row) => row.linked || row.available);
    const hasResult = result.linked || result.error !== null;
    if (rows.length === 0 && !hasResult) {
        return null;
    }

    return (
        <div style={{ marginTop: '1rem' }}>
            <ContentBox title='Single sign-on'>
                <div style={{ display: 'grid', gap: '0.75rem' }}>
                    {result.linked && result.provider && (
                        <Alert type='success'>{SSO_LABELS[result.provider]} is now linked to your account.</Alert>
                    )}
                    {result.error && <Alert type='danger'>{ssoErrorMessage(result.error, result.provider)}</Alert>}
                    <p style={{ margin: 0, fontSize: '0.875rem', color: 'var(--muted-foreground)' }}>
                        Link an account to sign in with it instead of your password.
                    </p>
                    {rows.map((row) => (
                        <div
                            key={row.provider}
                            style={{
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'space-between',
                                gap: '1rem',
                                flexWrap: 'wrap',
                                paddingTop: '0.75rem',
                                borderTop: '1px solid var(--border)',
                            }}
                        >
                            <div>
                                <div style={{ fontWeight: 600 }}>{row.label}</div>
                                <div style={{ fontSize: '0.8125rem', color: 'var(--muted-foreground)' }}>
                                    {row.linked ? `Linked${row.email ? ` · ${row.email}` : ''}` : 'Not linked'}
                                </div>
                            </div>
                            <div style={{ display: 'flex', gap: '0.5rem' }}>
                                {!row.linked && (
                                    <Button onClick={() => window.location.assign(ssoLinkUrl(row.provider))}>
                                        Link {row.label}
                                    </Button>
                                )}
                                {row.linked && confirming !== row.provider && (
                                    <Button.Danger onClick={() => setConfirming(row.provider)}>Unlink</Button.Danger>
                                )}
                                {row.linked && confirming === row.provider && (
                                    <>
                                        <Button.Danger
                                            isLoading={unlink.isPending}
                                            onClick={() => unlink.mutate(row.provider)}
                                        >
                                            Confirm unlink
                                        </Button.Danger>
                                        <Button.Text onClick={() => setConfirming(null)}>Cancel</Button.Text>
                                    </>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            </ContentBox>
        </div>
    );
}
