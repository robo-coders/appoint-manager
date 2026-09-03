/**
 * Turning configuration into copy.
 *
 * The Vertical model holds the words a vertical uses for its own nouns —
 * `subject_singular: 'dog'`, `subject_plural: 'dogs'`, `customer_singular:
 * 'client'`. They are lower case there **on purpose**: most of their uses are
 * mid-sentence ("Who is the appointment for?", "full groom for Bramble"), and a
 * config that stored "Dogs" would need lowercasing at every one of those.
 *
 * The cases that go wrong are the few where one of those words becomes a label
 * on its own — a column header, a field label — and lands next to labels that
 * are sentence case. The Customers table shipped a `dogs` header between `Name`
 * and `Bookings`, and it read exactly like the bug it was.
 *
 * So the fix is here rather than in the config: every vertical has the same
 * problem, a config edit would fix one word for one vertical and break its
 * sentences, and the place that knows a string is about to become a label is
 * the place rendering the label.
 */

/**
 * Sentence case: first letter up, everything else untouched.
 *
 * Deliberately **not** title case and deliberately not `toLowerCase()` on the
 * remainder. DESIGN.md is sentence case throughout, and a vertical's word may
 * legitimately carry capitals of its own — a future vertical's "MOT" must not
 * come out as "Mot".
 *
 * Idempotent, so it is safe on a string that is already a label.
 */
export const sentenceCase = (value: string): string => value.charAt(0).toUpperCase() + value.slice(1);
