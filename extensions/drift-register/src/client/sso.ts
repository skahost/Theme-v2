export type SsoProviderKey = 'google' | 'discord';

export const SSO_PROVIDERS: SsoProviderKey[] = ['google', 'discord'];

export const SSO_LABELS: Record<SsoProviderKey, string> = {
    google: 'Google',
    discord: 'Discord',
};

const base = '/extensions/drift-register';

/** Start signing in or registering with a provider. A real navigation, not an API call. */
export const ssoRedirectUrl = (provider: SsoProviderKey, mode: 'login' | 'register') =>
    `${base}/sso/${provider}/redirect?mode=${mode}`;

/** Start linking a provider to the signed-in account. */
export const ssoLinkUrl = (provider: SsoProviderKey) => `${base}/sso/${provider}/link`;

export const isSsoProvider = (value: string | null): value is SsoProviderKey =>
    value !== null && (SSO_PROVIDERS as string[]).includes(value);

export interface SsoQuery {
    error: string | null;
    provider: SsoProviderKey | null;
    linked: boolean;
}

/** Reads the result the SSO callback left in the query string (`?sso_error=` or `?sso=linked`). */
export function readSsoQuery(search: string): SsoQuery {
    const params = new URLSearchParams(search);
    const provider = params.get('sso_provider');

    return {
        error: params.get('sso_error'),
        provider: isSsoProvider(provider) ? provider : null,
        linked: params.get('sso') === 'linked',
    };
}

export function ssoErrorMessage(reason: string, provider: SsoProviderKey | null): string {
    const label = provider ? SSO_LABELS[provider] : 'that provider';

    switch (reason) {
        case 'not_registered':
            return `No account is linked to this ${label} account yet. Register with ${label} to create one.`;
        case 'totp_required':
            return `This account uses two-factor authentication, so it cannot sign in with ${label}. Sign in with your password instead.`;
        case 'email_taken':
            return `An account with this email already exists. Sign in with your password, then link ${label} from your account page.`;
        case 'email_unverified':
        case 'no_email':
            return `${label} did not give us a verified email address, so we cannot create an account from it.`;
        case 'registration_closed':
            return 'New sign-ups are currently disabled.';
        case 'denied':
            return 'Sign-in was cancelled.';
        case 'invalid_state':
            return 'That sign-in attempt expired. Please try again.';
        case 'identity_taken':
            return `That ${label} account is already linked to a different account.`;
        case 'already_linked':
            return `A ${label} account is already linked to your account.`;
        case 'session_mismatch':
            return 'You were signed out while linking. Sign in and try again.';
        case 'provider_unavailable':
            return `${label} sign-in is not available right now.`;
        case 'already_registered':
            return `That ${label} account already has an account here. Sign in with it instead.`;
        default:
            return 'Something went wrong while signing in. Please try again.';
    }
}
