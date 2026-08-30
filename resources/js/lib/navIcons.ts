import Building2 from 'lucide-vue-next/dist/esm/icons/building-2';
import CalendarDays from 'lucide-vue-next/dist/esm/icons/calendar-days';
import Clock from 'lucide-vue-next/dist/esm/icons/clock';
import Hourglass from 'lucide-vue-next/dist/esm/icons/hourglass';
import IdCard from 'lucide-vue-next/dist/esm/icons/id-card';
import LayoutDashboard from 'lucide-vue-next/dist/esm/icons/layout-dashboard';
import List from 'lucide-vue-next/dist/esm/icons/list';
import Plane from 'lucide-vue-next/dist/esm/icons/plane';
import Repeat from 'lucide-vue-next/dist/esm/icons/repeat';
import Scissors from 'lucide-vue-next/dist/esm/icons/scissors';
import Send from 'lucide-vue-next/dist/esm/icons/send';
import Settings from 'lucide-vue-next/dist/esm/icons/settings';
import TriangleAlert from 'lucide-vue-next/dist/esm/icons/triangle-alert';
import Upload from 'lucide-vue-next/dist/esm/icons/upload';
import Users from 'lucide-vue-next/dist/esm/icons/users';
import type { Component } from 'vue';

/**
 * The nav rail's icons.
 *
 * **Deep imports, never the barrel.** `lucide-vue-next` exports 5,847 icons
 * from its index. It declares `sideEffects: false`, so Rollup *should* shake the
 * rest away — but "should" is doing a lot of work in a file nobody looks at
 * again, and a single future import from the barrel in any other file would
 * silently undo it for the whole bundle. Naming the file makes the cost visible
 * and fixed: fifteen icons, and adding a sixteenth means adding a line here.
 *
 * The names are chosen, not derived, for the same reason the letter glyphs they
 * replace were: `Services`, `Staff` and `Settings` all begin with S, and three
 * icons that all mean "a person" would be the same collision in a different
 * medium. Read down this list and every entry is a different object.
 *
 * Every icon is decorative. It is drawn `aria-hidden`, the accessible name
 * comes from the label beside it or from an `aria-label` when the rail is
 * collapsed, and nothing on the screen is distinguished by icon alone — the
 * 148px rail still carries the words.
 */
export const NAV_ICONS: Record<string, Component> = {
    diary: CalendarDays,
    bookings: List,
    customers: Users,
    waitlist: Hourglass,
    overdue: Repeat,
    services: Scissors,
    staff: IdCard,
    hours: Clock,
    'time-off': Plane,
    overview: LayoutDashboard,
    import: Upload,
    settings: Settings,

    // The console.
    tenants: Building2,
    'send-log': Send,
    failures: TriangleAlert,
};

/** The key a nav label maps to. `Time off` -> `time-off`. */
export const iconKeyFor = (label: string): string => label.trim().toLowerCase().replace(/\s+/g, '-');

export const navIconFor = (label: string): Component | null => NAV_ICONS[iconKeyFor(label)] ?? null;
