#!/usr/bin/env bash
#
# Playwright starts `php artisan serve` only. Laravel's `@vite()` prefers
# `public/hot` over the manifest, so a leftover `npm run dev` — often bound
# to `[::1]:5173` and unreachable from `127.0.0.1` — leaves the e2e browser
# on a blank page waiting for a module that never arrives. The suite uses
# the built manifest. `public/hot` is moved aside for the run and put back.
#
set -euo pipefail
cd "$(dirname "$0")/.."

ASIDE=""
if [ -f public/hot ]; then
    echo "e2e: setting public/hot aside so the suite uses public/build"
    mv public/hot public/hot.aside
    ASIDE=1
fi

restore() {
    if [ -n "$ASIDE" ] && [ -f public/hot.aside ]; then
        mv public/hot.aside public/hot
        echo "e2e: restored public/hot"
    fi
}
trap restore EXIT

if [ ! -f public/build/manifest.json ]; then
    echo "e2e: no Vite manifest; running npm run build"
    npm run build
fi

npx playwright test "$@"
