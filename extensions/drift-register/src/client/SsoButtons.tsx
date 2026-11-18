import { DISCORD_LOGO } from './discordLogo';
import { SSO_LABELS, ssoRedirectUrl, type SsoProviderKey } from './sso';

const GOOGLE_PATHS = [
    ['#4285F4', 'M23.49 12.27c0-.79-.07-1.54-.19-2.27H12v4.51h6.47c-.29 1.48-1.14 2.73-2.4 3.58v3h3.86c2.26-2.09 3.56-5.17 3.56-8.82z'],
    ['#34A853', 'M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.86-3c-1.08.72-2.45 1.16-4.07 1.16-3.13 0-5.78-2.11-6.73-4.96h-3.98v3.09C3.15 21.3 7.26 24 12 24z'],
    ['#FBBC05', 'M5.27 14.29c-.25-.72-.38-1.49-.38-2.29s.14-1.57.38-2.29V6.62h-3.98a11.86 11.86 0 0 0 0 10.76l3.98-3.09z'],
    ['#EA4335', 'M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.26 0 3.15 2.7 1.29 6.62l3.98 3.09C6.22 6.86 8.87 4.75 12 4.75z'],
];

function ProviderIcon({ provider }: { provider: SsoProviderKey }) {
    if (provider === 'google') {
        return (
            <svg viewBox='0 0 24 24' width='20' height='20' aria-hidden='true'>
                {GOOGLE_PATHS.map(([fill, d]) => (
                    <path key={fill} fill={fill} d={d} />
                ))}
            </svg>
        );
    }

    return (
        <img src={DISCORD_LOGO} alt='' width='20' height='15' aria-hidden='true' style={{ width: '1.25rem', height: 'auto', display: 'block' }} />
    );
}

/**
 * "Sign in with Google / Discord" links. They are real links: the browser leaves the Panel,
 * signs in at the provider, and comes back through the extension's callback route.
 */
export default function SsoButtons({
    providers,
    mode,
}: {
    providers: SsoProviderKey[];
    mode: 'login' | 'register';
}) {
    return (
        <>
            {providers.map((provider) => (
                <a
                    key={provider}
                    href={ssoRedirectUrl(provider, mode)}
                    className={`drift-sso-btn drift-sso-btn-${provider}`}
                >
                    <ProviderIcon provider={provider} />
                    <span>
                        {mode === 'login' ? 'Sign in with' : 'Register with'} {SSO_LABELS[provider]}
                    </span>
                </a>
            ))}
        </>
    );
}
