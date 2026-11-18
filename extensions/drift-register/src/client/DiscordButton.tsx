import { DISCORD_LOGO } from './discordLogo';

export const DISCORD_URL = 'https://discord.gg/eFQZayYSGN';

/**
 * A "Join our Discord" call to action in Discord's blurple. It is a real link, so it can be
 * opened in a new tab, copied or middle-clicked. Styled with this extension's own `drift:`
 * Tailwind prefix and the Panel's tokens for focus rings.
 */
export default function DiscordButton() {
    return (
        <a
            href={DISCORD_URL}
            target='_blank'
            rel='noopener noreferrer'
            className='drift:group drift:flex drift:w-full drift:items-center drift:gap-3 drift:rounded-lg drift:bg-[#5865f2] drift:px-4 drift:py-3 drift:text-[#ffffff] drift:no-underline drift:shadow-lg drift:shadow-[#5865f2]/30 drift:ring-1 drift:ring-inset drift:ring-[#ffffff26] drift:transition drift:duration-150 drift:hover:-translate-y-px drift:hover:bg-[#4752c4] drift:hover:text-[#ffffff] drift:hover:shadow-xl drift:hover:shadow-[#5865f2]/40 drift:focus-visible:outline-2 drift:focus-visible:outline-offset-2 drift:focus-visible:outline-ring'
        >
            <span className='drift:flex drift:size-9 drift:flex-none drift:items-center drift:justify-center drift:rounded-md drift:bg-[#ffffff26]'>
                <img src={DISCORD_LOGO} alt='' width='22' height='17' className='drift:block drift:h-auto drift:w-[22px]' aria-hidden='true' />
            </span>
            <span className='drift:flex drift:min-w-0 drift:flex-1 drift:flex-col drift:text-left'>
                <span className='drift:text-sm drift:font-semibold drift:leading-tight'>Join our Discord</span>
                <span className='drift:text-xs drift:leading-tight drift:text-[#ffffffbf]'>
                    Get help and chat with the community
                </span>
            </span>
            <svg
                viewBox='0 0 24 24'
                width='18'
                height='18'
                fill='none'
                stroke='currentColor'
                strokeWidth='2'
                strokeLinecap='round'
                strokeLinejoin='round'
                className='drift:flex-none drift:opacity-80 drift:transition-transform drift:duration-150 drift:group-hover:translate-x-0.5'
                aria-hidden='true'
            >
                <path d='M5 12h14M13 6l6 6-6 6' />
            </svg>
        </a>
    );
}

/** Rendered on the admin overview (slot panel.overview.after). */
export function AdminDiscord() {
    return (
        <div className='drift:mt-4 drift:max-w-sm'>
            <DiscordButton />
        </div>
    );
}
