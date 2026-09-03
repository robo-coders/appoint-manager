# Launch checklist

Tick or defer with a reason. First real salon does not go live until every open item is closed or deferred here.

## Product
- [x] 30-day trial, no card, £29/month or £290/year Checkout on the platform Stripe account
- [x] Admin read-only on unpaid/expired trial; public booking still works
- [x] Dunning emails day 0 / 3 / 7 (`billing:dunning`)
- [x] Cancel asks why and offers pause
- [x] Marketing: home, pricing, `/dog-grooming`, about, contact, privacy, terms, sitemap, robots
- [x] Empty testimonials slot (no fake quotes). No blog.
- [x] Super admin `/admin`: tenants, impersonate + audit, send log, failed jobs/webhooks, trial/comp/flags/clone
- [x] CSV customer + booking import with dry-run
- [x] Pre-launch preview link
- [x] GDPR export and hard delete on the customer screen

## Production
- [x] Nightly `db:backup` + `scripts/restore-db.sh` / `db:restore` (tested against sqlite in CI; MySQL restore must be run once on staging before first salon)
- [x] `/health` checks database; Redis when Redis is the cache/queue; `/up` liveness
- [x] Security headers (CSP, HSTS on HTTPS, X-Frame-Options, referrer)
- [x] Rate limits: public booking, availability, login
- [x] JSON log channel with `tenant_id`; 30-day daily files
- [x] `.env.example` covers billing, Plausible, Sentry, AWS
- [x] `DEPLOY.md` Forge + rollback

## Deferred
- [ ] **Laravel Cashier package** — deferred: Connect already owns `/stripe/webhook` and the Stripe client. Platform billing is Checkout + a dedicated webhook and `billing_events`. Revisit Cashier if we add metered add-ons.
- [ ] **Horizon package** — deferred until Redis is provisioned on Forge. Workers are specified in `DEPLOY.md`. Failed jobs are visible in `/admin/failures`.
- [ ] **Sentry SDK** — deferred until `SENTRY_LARAVEL_DSN` is set on Forge. The exception reporter already tags `tenant_id` when the SDK is present.
- [ ] **External uptime monitor** — deferred: wire Better Stack (or equivalent) to `GET /health` the day the first domain is live.
- [ ] **S3 backup in production** — command is ready; needs AWS credentials and one successful restore on a staging MySQL before go-live.
- [ ] **Real product screenshots** on marketing — current frames are the live diary chrome in HTML, not stock illustrations. Replace with captured PNGs after the first staging deploy.
- [ ] **Plausible** — script only loads when `PLAUSIBLE_DOMAIN` is set.
