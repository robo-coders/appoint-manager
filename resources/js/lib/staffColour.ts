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

/**
 * The staff palette.
 *
 * Six colours, chosen rather than picked: the old form was `<input
 * type="color">`, which is an operating-system colour wheel bolted onto a
 * monochrome product and guarantees that some salon ends up with a neon-yellow
 * groomer. The same argument as the tenant brand presets in DESIGN.md, for the
 * same reason.
 *
 * These are the six tenant brand values reused as staff colours. They are all
 * dark enough to carry white text and muted enough to sit beside ink without
 * shouting — which is what makes them usable as 6px squares beside a name.
 * They are *data*, not tokens: they are stored on `users.colour` and they are
 * the one legitimate colour outside the design system.
 */
export const STAFF_COLOURS: Array<{ value: string; label: string }> = [
    { value: '#2F5D4A', label: 'Forest' }, // design-tokens-ignore: per-user data written to users.colour, not a design token
    { value: '#7B3448', label: 'Plum' }, // design-tokens-ignore: per-user data written to users.colour, not a design token
    { value: '#24415F', label: 'Navy' }, // design-tokens-ignore: per-user data written to users.colour, not a design token
    { value: '#8A5A1E', label: 'Ochre' }, // design-tokens-ignore: per-user data written to users.colour, not a design token
    { value: '#414A52', label: 'Slate' }, // design-tokens-ignore: per-user data written to users.colour, not a design token
    { value: '#8C4A32', label: 'Clay' }, // design-tokens-ignore: per-user data written to users.colour, not a design token
];
