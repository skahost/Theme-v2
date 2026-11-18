import './styles.css';
import { definePterodactylExtension } from '@pterodactyl/sdk';
import AccountSso from './AccountSso';
import { AdminDiscord } from './DiscordButton';
import RegisterPrompt from './RegisterPrompt';

export default definePterodactylExtension({
    setup({ screens, slots }) {
        // Admin page at /panel/drift-register (declared in extension.json ui.screens).
        screens.register('config', () => import('./screens/ConfigScreen'));

        // Login page, below the Login button. The login page gets an empty `config`,
        // so the component asks /extensions/drift-register/status instead.
        slots.register('auth.login.form.after', RegisterPrompt);

        // Account overview: link or unlink Google / Discord.
        slots.register('account.overview.after', AccountSso);

        // "Drift Register" tab on a user's page (/panel/users/{id}/drift-register), after About
        // (declared in extension.json ui.screens with parent admin.user).
        screens.register('user', () => import('./screens/UserScreen'));

        // Admin overview, below the Panel's own content.
        slots.register('panel.overview.after', AdminDiscord);
    },
});
