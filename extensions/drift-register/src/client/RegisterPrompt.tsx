import { useEffect, useMemo, useRef, useState } from 'react';
import DiscordButton from './DiscordButton';
import SsoButtons from './SsoButtons';
import { getStatus, type RegistrationStatus } from './api';
import { SSO_LABELS, SSO_PROVIDERS, readSsoQuery, ssoErrorMessage, ssoRedirectUrl } from './sso';

/** Where the standalone sign-up page lives (`routes.root` in extension.json). */
export const REGISTER_PATH = '/register';

function Divider() {
    return (
        <div
            className='drift:flex drift:items-center drift:gap-3 drift:text-xs drift:uppercase drift:tracking-wider drift:text-muted-foreground'
            aria-hidden='true'
        >
            <span className='drift:h-px drift:flex-1 drift:bg-border' />
            or
            <span className='drift:h-px drift:flex-1 drift:bg-border' />
        </div>
    );
}

/** Sets an input's value the way a person typing would, so the Panel's form notices it. */
function fillInput(input: HTMLInputElement, value: string): void {
    const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set;
    setter?.call(input, value);
    input.dispatchEvent(new Event('input', { bubbles: true }));
}

/**
 * Rendered below the Login button (slot auth.login.form.after). It offers the enabled
 * single sign-on providers, explains a failed or unregistered SSO attempt, links to the
 * standalone sign-up page while registration is enabled, and offers Join Discord unless an admin hid it.
 */
export default function RegisterPrompt() {
    const [status, setStatus] = useState<RegistrationStatus | null | undefined>(undefined);
    const result = useMemo(() => readSsoQuery(window.location.search), []);
    const rootRef = useRef<HTMLDivElement>(null);
    const filled = useRef(false);

    useEffect(() => {
        let active = true;
        getStatus()
            .then((next) => active && setStatus(next))
            .catch(() => active && setStatus(null));

        return () => {
            active = false;
        };
    }, []);

    // Wait for the status so a hidden button never flashes up first; if it cannot be loaded, show it.
    const showDiscord = status !== undefined && !status?.hide_discord;
    // Demo mode: fill the Panel's login form once. The slot sits inside that form, so its
    // fields are found from here; a field the visitor already typed in is left alone.
    const demo = status?.demo ?? null;
    useEffect(() => {
        if (!demo || filled.current) {
            return;
        }
        const form = rootRef.current?.closest('form');
        const username = form?.querySelector<HTMLInputElement>('input[type="text"]');
        const password = form?.querySelector<HTMLInputElement>('input[type="password"]');
        if (!username || !password) {
            return;
        }
        filled.current = true;
        if (username.value === '') {
            fillInput(username, demo.email);
        }
        if (password.value === '') {
            fillInput(password, demo.password);
        }
    }, [demo]);

    const providers = SSO_PROVIDERS.filter((key) => status?.sso?.[key]);
    // "No account is linked yet" is answered with a way to register with that provider.
    const offerRegister =
        result.error === 'not_registered' && result.provider !== null && !!status?.enabled &&
        !!status.sso?.[result.provider] &&
        status.fields?.[result.provider] !== 'hidden';

    return (
        <div ref={rootRef} className='drift:mt-6 drift:grid drift:gap-3'>
            {result.error && (
                <div
                    role='alert'
                    style={{
                        padding: '0.75rem 0.875rem',
                        fontSize: '0.875rem',
                        lineHeight: 1.5,
                        borderRadius: 'var(--radius, 0.5rem)',
                        border: '1px solid var(--destructive)',
                        color: 'var(--destructive)',
                    }}
                >
                    {ssoErrorMessage(result.error, result.provider)}
                </div>
            )}
            {offerRegister && result.provider && (
                <a
                    href={ssoRedirectUrl(result.provider, 'register')}
                    className={`drift-sso-btn drift-sso-btn-${result.provider}`}
                >
                    <span>Register with {SSO_LABELS[result.provider]}</span>
                </a>
            )}
            {providers.length > 0 && (
                <>
                    <SsoButtons providers={providers} mode='login' />
                    <Divider />
                </>
            )}
            {status?.enabled && (
                <>
                    <a
                        href={REGISTER_PATH}
                        className='drift:flex drift:h-12 drift:w-full drift:items-center drift:justify-center drift:rounded-lg drift:border drift:border-border drift:bg-secondary drift:text-sm drift:font-medium drift:uppercase drift:tracking-wider drift:text-secondary-foreground drift:no-underline drift:transition-colors drift:hover:border-ring drift:hover:text-secondary-foreground drift:focus-visible:outline-2 drift:focus-visible:outline-offset-2 drift:focus-visible:outline-ring'
                    >
                        Create an account
                    </a>
                    <Divider />
                </>
            )}
            {showDiscord && <DiscordButton />}
        </div>
    );
}
