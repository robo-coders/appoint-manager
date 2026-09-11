import { route as ziggyRoute } from '../../../vendor/tightenco/ziggy';

export type RouteFn = typeof ziggyRoute;

export function pinZiggyToCurrentHost(): void {
    const ziggy = (window as unknown as { Ziggy?: { url: string; port: number | null } }).Ziggy;

    if (!ziggy) {
        return;
    }

    ziggy.url = window.location.origin;
    ziggy.port = window.location.port ? Number(window.location.port) : null;
}

/* eslint-disable @typescript-eslint/no-explicit-any */
export const sameHostRoute = ((name?: any, params?: any, absolute?: boolean, config?: any) =>
    ziggyRoute(name, params, absolute ?? false, config)) as RouteFn;
