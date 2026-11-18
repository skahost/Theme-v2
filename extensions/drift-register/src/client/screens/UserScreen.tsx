import { useEffect, useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Button,
    Input,
    Label,
    Spinner,
    TextArea,
    TitledGreyBox,
    httpErrorToHuman,
    toast,
    type ScreenComponentProps,
} from '@pterodactyl/sdk';
import { getUserDetails, saveUserDetails, type UserDetails, type UserSso } from '../api';

const muted = { color: 'var(--muted-foreground)' } as const;
const grid = (min: string) =>
    ({ display: 'grid', gap: '1rem', gridTemplateColumns: `repeat(auto-fit, minmax(${min}, 1fr))`, margin: 0 }) as const;

function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '-';
    }
    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '-' : date.toLocaleString();
}

const SIGNUP_METHODS: Record<string, string> = {
    password: 'Email and password',
    email: 'Email verification link',
    google: 'Google',
    discord: 'Discord',
};

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div style={{ minWidth: 0 }}>
            <dt style={{ fontSize: '0.75rem', ...muted }}>{label}</dt>
            <dd style={{ margin: 0, overflowWrap: 'anywhere' }}>{children}</dd>
        </div>
    );
}

/** The provider's picture, or the first letter of the name when it is missing or will not load. */
function Avatar({ url, name }: { url: string | null; name: string }) {
    const [failed, setFailed] = useState(false);
    const size = '3rem';

    return url && !failed ? (
        <img
            src={url}
            alt=''
            referrerPolicy='no-referrer'
            onError={() => setFailed(true)}
            style={{ width: size, height: size, borderRadius: '9999px', objectFit: 'cover', flexShrink: 0 }}
        />
    ) : (
        <div
            aria-hidden
            style={{
                width: size,
                height: size,
                borderRadius: '9999px',
                flexShrink: 0,
                display: 'grid',
                placeItems: 'center',
                fontWeight: 600,
                background: 'var(--muted)',
                ...muted,
            }}
        >
            {(name.trim()[0] ?? '?').toUpperCase()}
        </div>
    );
}

/** One linked provider account, or a note that there is none. */
function ProviderCard({ title, account }: { title: string; account: UserSso | undefined }) {
    const provider = title.toLowerCase();
    const name = account?.display_name || account?.username || account?.email || title;

    return (
        <TitledGreyBox title={title}>
            {!account ? (
                <p style={{ margin: 0, ...muted }}>No {title} account connected.</p>
            ) : (
                <div style={{ display: 'grid', gap: '1rem' }}>
                    <div style={{ display: 'flex', gap: '0.75rem', alignItems: 'center' }}>
                        <Avatar url={account.avatar_url} name={name} />
                        <div style={{ minWidth: 0 }}>
                            <div style={{ fontWeight: 600, overflowWrap: 'anywhere' }}>{name}</div>
                            <div style={{ fontSize: '0.75rem', ...muted }}>Connected {title} account</div>
                        </div>
                    </div>
                    <dl style={grid('11rem')}>
                        {provider === 'discord' ? (
                            <Fact label='Discord username'>
                                {account.username ?? <span style={muted}>Not recorded yet, fills in at their next Discord sign-in</span>}
                            </Fact>
                        ) : (
                            <Fact label='Google email'>{account.email ?? '-'}</Fact>
                        )}
                        {provider === 'discord' && <Fact label='Discord email'>{account.email ?? '-'}</Fact>}
                        <Fact label='Connected on'>{formatDate(account.linked_at)}</Fact>
                        <Fact label='Last sign-in with it'>{formatDate(account.last_login_at)}</Fact>
                    </dl>
                </div>
            )}
        </TitledGreyBox>
    );
}

function ContactCard({ user }: { user: UserDetails }) {
    const queryClient = useQueryClient();
    const [company, setCompany] = useState(user.company ?? '');
    const [phone, setPhone] = useState(user.phone ?? '');
    const [notes, setNotes] = useState(user.notes ?? '');

    useEffect(() => {
        setCompany(user.company ?? '');
        setPhone(user.phone ?? '');
        setNotes(user.notes ?? '');
    }, [user.company, user.phone, user.notes]);

    const save = useMutation({
        mutationFn: () => saveUserDetails(user.id, { company, phone, notes }),
        onSuccess: (saved) => {
            queryClient.setQueryData(['drift-register', 'user', user.id], saved);
            toast.success('Saved.');
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    return (
        <TitledGreyBox title='Company and contact'>
            <div style={{ display: 'grid', gap: '1.25rem' }}>
                <div style={grid('16rem')}>
                    <div>
                        <Label htmlFor='drift-user-company'>Company Name</Label>
                        <Input
                            id='drift-user-company'
                            type='text'
                            maxLength={191}
                            value={company}
                            onChange={(event) => setCompany(event.target.value)}
                        />
                    </div>
                    <div>
                        <Label htmlFor='drift-user-phone'>Phone Number</Label>
                        <Input
                            id='drift-user-phone'
                            type='text'
                            maxLength={32}
                            value={phone}
                            onChange={(event) => setPhone(event.target.value)}
                        />
                    </div>
                </div>
                <div>
                    <Label htmlFor='drift-user-notes'>Admin notes</Label>
                    <TextArea
                        id='drift-user-notes'
                        rows={3}
                        maxLength={2000}
                        value={notes}
                        onChange={(event) => setNotes(event.target.value)}
                    />
                    <p style={{ margin: '0.25rem 0 0', fontSize: '0.75rem', ...muted }}>
                        Private. Only administrators see this.
                    </p>
                </div>
                <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
                    <Button type='button' isLoading={save.isPending} onClick={() => save.mutate()}>
                        Save Changes
                    </Button>
                </div>
            </div>
        </TitledGreyBox>
    );
}

function AccountCard({ user }: { user: UserDetails }) {
    const signup = user.signup;

    return (
        <TitledGreyBox title='Account'>
            <dl style={grid('13rem')}>
                <Fact label='Signed up with'>
                    {signup ? (SIGNUP_METHODS[signup.method] ?? signup.method) : <span style={muted}>Not recorded</span>}
                </Fact>
                <Fact label='Joined'>{formatDate(user.created_at)}</Fact>
                <Fact label='Sign-up IP address'>{signup?.ip_address ?? '-'}</Fact>
                <Fact label='Sign-up browser'>{signup?.user_agent ?? '-'}</Fact>
                <Fact label='Two-factor'>{user.two_factor ? 'On' : 'Off'}</Fact>
                <Fact label='Role'>{user.root_admin ? 'Administrator' : 'User'}</Fact>
                <Fact label='Language'>{user.language || '-'}</Fact>
                <Fact label='UUID'>{user.uuid}</Fact>
            </dl>
        </TitledGreyBox>
    );
}

/**
 * The "Drift Register" tab on a user's page (/panel/users/{id}/drift-register), after the
 * Panel's About tab: company, phone and admin notes (editable), the Discord and Google
 * profiles the user signed in with, and how and when they signed up.
 */
export default function UserScreen({ data }: ScreenComponentProps) {
    const id = Number(data.params.id);
    const details = useQuery({
        queryKey: ['drift-register', 'user', id],
        queryFn: () => getUserDetails(id),
        enabled: id > 0,
    });

    if (details.isError) {
        return <Alert type='danger'>{httpErrorToHuman(details.error)}</Alert>;
    }
    if (!details.data) {
        return <Spinner centered />;
    }

    const user = details.data;

    return (
        <div style={{ display: 'grid', gap: '1rem' }}>
            <ContactCard user={user} />
            <div style={grid('20rem')}>
                <ProviderCard title='Discord' account={user.sso.find((item) => item.provider === 'discord')} />
                <ProviderCard title='Google' account={user.sso.find((item) => item.provider === 'google')} />
            </div>
            <AccountCard user={user} />
        </div>
    );
}
