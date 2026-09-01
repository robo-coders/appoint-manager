import { route as ziggyRoute } from '../../../vendor/tightenco/ziggy';

/**
 * Same-surface URLs stay on the host the browser is already on.
 *
 * Ziggy defaults to absolute URLs built from whatever was dumped into the
 * page. That dump follows `url('/')`, which in a queued job or a mail is
 * APP_URL — and locally APP_URL is one of `127.0.0.1`, `localhost`, or a
 * `.test` name, never all three. Opening the login form on the other host
 * posted cross-origin: the session cookie did not ride, `onFinish` cleared
 * the password, and nothing on the form explained why.
 *
 * Cross-surface links still go through the PHP helpers (`urls.marketing`,
 * `urls.app`, `urls.admin`) which are absolute on purpose.
 */
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
