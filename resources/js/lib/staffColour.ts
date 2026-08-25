/**
 * Staff colours are per-user *data*, stored on `users.colour`, not UI tokens.
 * They exist to tell people apart in the diary, which is a different job from
 * the single rationed accent.
 *
 * The default is unchanged from before the design pass so existing staff keep
 * the colour they were given. A proper muted, distinguishable palette lands
 * with the diary rebuild.
 */
export const DEFAULT_STAFF_COLOUR = '#0F766E'; // design-tokens-ignore: stored per-user data, not a design token
