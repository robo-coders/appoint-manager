import Building2 from 'lucide-vue-next/dist/esm/icons/building-2';
import CalendarDays from 'lucide-vue-next/dist/esm/icons/calendar-days';
import Clock from 'lucide-vue-next/dist/esm/icons/clock';
import Hourglass from 'lucide-vue-next/dist/esm/icons/hourglass';
import IdCard from 'lucide-vue-next/dist/esm/icons/id-card';
import LayoutDashboard from 'lucide-vue-next/dist/esm/icons/layout-dashboard';
import LayoutGrid from 'lucide-vue-next/dist/esm/icons/layout-grid';
import List from 'lucide-vue-next/dist/esm/icons/list';
import Menu from 'lucide-vue-next/dist/esm/icons/menu';
import Plane from 'lucide-vue-next/dist/esm/icons/plane';
import Repeat from 'lucide-vue-next/dist/esm/icons/repeat';
import Scissors from 'lucide-vue-next/dist/esm/icons/scissors';
import Send from 'lucide-vue-next/dist/esm/icons/send';
import Settings from 'lucide-vue-next/dist/esm/icons/settings';
import TriangleAlert from 'lucide-vue-next/dist/esm/icons/triangle-alert';
import Upload from 'lucide-vue-next/dist/esm/icons/upload';
import Users from 'lucide-vue-next/dist/esm/icons/users';
import type { Component } from 'vue';

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

    more: Menu,

    tenants: Building2,
    'send-log': Send,
    failures: TriangleAlert,
    verticals: LayoutGrid,
};

export const iconKeyFor = (label: string): string => label.trim().toLowerCase().replace(/\s+/g, '-');

export const navIconFor = (label: string): Component | null => NAV_ICONS[iconKeyFor(label)] ?? null;
