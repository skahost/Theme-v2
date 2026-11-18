import { http } from '@pterodactyl/sdk';
import type { SsoProviderKey } from './sso';

const publicUrl = (path: string) => `/extensions/drift-register${path}`;
const adminUrl = (path: string) => `/api/admin/extensions/drift-register${path}`;
const clientUrl = (path: string) => `/api/client/extensions/drift-register${path}`;

export type FieldMode = 'required' | 'optional' | 'hidden';

/** Sign-up form fields an admin can switch between required, optional and hidden. */
export type FieldKey =
    | 'first_name'
    | 'last_name'
    | 'company'
    | 'phone'
    | 'username'
    | 'password'
    | 'password_confirm'
    | 'discord'
    | 'google';

export interface RegistrationStatus {
    enabled: boolean;
    /** The admin hid the Join our Discord button on the login and register pages. */
    hide_discord?: boolean;
    /** Demo login to auto-fill on the login page; null unless demo mode and auto-fill are on. */
    demo?: { email: string; password: string } | null;
    /** What visitors see: a provider that is not configured is always hidden. */
    fields?: Partial<Record<FieldKey, FieldMode>>;
    /** Providers that are switched on and fully configured. */
    sso?: Partial<Record<SsoProviderKey, boolean>>;
}

export interface SsoAdminProvider {
    enabled: boolean;
    client_id: string;
    has_secret: boolean;
    /** Switched on and has both credentials. */
    ready: boolean;
    redirect_uri: string;
}

export interface RegisterConfig {
    registration_enabled: boolean;
    hide_discord_button?: boolean;
    demo_mode?: boolean;
    demo_autofill?: boolean;
    demo_email?: string;
    demo_password?: string;
    /** What the admin saved for each field. */
    fields: Record<FieldKey, FieldMode>;
    verification_ttl_minutes: number;
    rate_limit_per_minute: number;
    mail_driver: string;
    pending_count: number;
    sso?: Record<SsoProviderKey, SsoAdminProvider>;
}

export interface SsoProviderInput {
    enabled: boolean;
    client_id: string;
    /** Leave out to keep the saved secret. */
    client_secret?: string;
}

export type RegisterConfigInput = Pick<
    RegisterConfig,
    'registration_enabled' | 'hide_discord_button' | 'demo_mode' | 'demo_autofill' | 'demo_email' | 'demo_password' | 'fields' | 'verification_ttl_minutes' | 'rate_limit_per_minute'
> & {
    sso?: Record<SsoProviderKey, SsoProviderInput>;
};

export interface SsoConnection {
    provider: SsoProviderKey;
    label: string;
    /** The provider is switched on and configured, so it can be linked. */
    available: boolean;
    linked: boolean;
    email: string | null;
}

export async function getStatus(): Promise<RegistrationStatus> {
    const { data } = await http.get<RegistrationStatus>(publicUrl('/status'));

    return data;
}

export async function getConfig(): Promise<RegisterConfig> {
    const { data } = await http.get<RegisterConfig>(adminUrl('/config'));

    return data;
}

export async function saveConfig(input: RegisterConfigInput): Promise<RegisterConfig> {
    const { data } = await http.put<RegisterConfig>(adminUrl('/config'), input);

    return data;
}

export async function getSsoConnections(): Promise<SsoConnection[]> {
    const { data } = await http.get<{ providers: SsoConnection[] }>(clientUrl('/sso'));

    return data.providers;
}

export async function unlinkSso(provider: SsoProviderKey): Promise<void> {
    await http.delete(clientUrl(`/sso/${provider}`));
}

/** A Google or Discord account linked to a user. */
export interface UserSso {
    provider: SsoProviderKey;
    label: string;
    email: string | null;
    /** The Discord username; null for Google and for Discord accounts linked before 1.5. */
    username: string | null;
    display_name: string | null;
    avatar_url: string | null;
    linked_at: string | null;
    last_login_at: string | null;
}

/** How and when the account was made here: password, email, google or discord. */
export interface UserSignup {
    method: string;
    ip_address: string | null;
    user_agent: string | null;
    at: string | null;
}

/** What this extension knows about one Panel user (admin only). */
export interface UserDetails {
    id: number;
    uuid: string;
    username: string;
    email: string;
    first_name: string;
    last_name: string;
    language: string;
    root_admin: boolean;
    two_factor: boolean;
    created_at: string | null;
    company: string | null;
    phone: string | null;
    notes: string | null;
    signup: UserSignup | null;
    /** The linked Discord account; null when there is none. The username is null for old links. */
    discord: { username: string | null; email: string | null } | null;
    sso: UserSso[];
}

export async function getUserDetails(id: number): Promise<UserDetails> {
    const { data } = await http.get<{ data: UserDetails }>(adminUrl(`/users/${id}`));

    return data.data;
}

export async function saveUserDetails(id: number, input: { company: string; phone: string; notes: string }): Promise<UserDetails> {
    const { data } = await http.put<{ data: UserDetails }>(adminUrl(`/users/${id}`), input);

    return data.data;
}
