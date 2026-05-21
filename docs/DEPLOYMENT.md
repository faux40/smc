# Deployment & infra checklist

Production-readiness reference for SMC Compliance (Phase 16.1). Keep this in
sync with `.env.example` and the Forge config.

## Required environment

Beyond the stock Laravel keys, these app-specific groups **must** be set or
the corresponding feature silently fails. All are templated in `.env.example`.

- **Database** — `DB_CONNECTION=pgsql` + host/db/user/pass. Postgres, not
  SQLite (UUID columns reject malformed input — see the stale-cookie note
  below).
- **Reverb (realtime)** — `REVERB_APP_ID` / `REVERB_APP_KEY` /
  `REVERB_APP_SECRET` / `REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME`, and
  `BROADCAST_CONNECTION=reverb`. The browser also needs the `VITE_REVERB_*`
  mirror keys (see the build gotcha below).
- **Linode Object Storage (attachments)** — `LINODE_ACCESS_KEY` /
  `LINODE_SECRET_KEY` / `LINODE_REGION` / `LINODE_BUCKET` / `LINODE_URL` /
  `LINODE_ENDPOINT` / `LINODE_PATH_STYLE`. Backs the `linode` disk.
- **Queue** — production uses `QUEUE_CONNECTION=redis` (local default is
  `database`). Broadcasts and queued notifications need a worker (below).
- **Mail** — set a real `MAIL_MAILER` and `MAIL_NOTIFICATIONS_ENABLED=true`
  to turn on the email notification channel (off by default).

## Observability

- **Error tracking** — native: unhandled exceptions are reported to the log
  channel by Laravel's handler. No external SaaS — error data stays in your
  infra. (See the logging config below; a self-hosted dashboard like Laravel
  Pulse can layer on top later.)
- **Logging** — production should run `LOG_CHANNEL=daily` (rotating) at
  `LOG_LEVEL=info` or `warning`. `debug` is noisy and can leak request detail.
- **Liveness** — `/up` only confirms the app boots. `/health/detailed` (public,
  JSON, 200/503) reports the scheduler + queue-worker heartbeats (each stamped
  every minute); point an uptime monitor at it to catch a dead daemon. Reverb
  has no HTTP health route — monitor its ws port directly.

## Daemons (Forge)

Three long-running processes. Missing any one degrades a whole feature area:

1. **`php artisan queue:work`** — delivers queued broadcasts + notifications.
   Without it, realtime/email never fire (and the queue backs up).
2. **`php artisan reverb:start`** — the WebSocket server. Without it, clients
   can't receive broadcasts (the app still works — `echo.ts` degrades to "no
   realtime" — but there are no live updates).
3. **`schedule:run` cron** (`* * * * * php artisan schedule:run`) — drives the
   daily due-soon/overdue watchdog (15.3) and the weekly manager digest
   (15.6). **Without the cron these notifications never send.**

## Deploy script (order matters)

```sh
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
npm ci && npm run build                # rebuilds the JS bundle (see VITE note)
php artisan wayfinder:generate --with-form   # --with-form is required
php artisan optimize                   # config/route/view cache
php artisan queue:restart              # pick up new code in the worker
# restart the reverb:start daemon too (Forge: restart the daemon)
```

## Lessons / gotchas

- **`VITE_*` are build-time, not runtime.** The `VITE_REVERB_*` keys are
  compiled into the JS bundle by `npm run build`. Changing them in `.env` and
  running `php artisan config:cache` is **not** enough — you must rebuild the
  frontend, or the browser keeps connecting to the old Reverb host/key.
- **Stale recaller cookie → 500 (fixed, 16.2).** A "remember me" cookie with a
  malformed UUID used to hit Postgres' UUID cast and 500 an anonymous request.
  `SafeEloquentUserProvider` now short-circuits non-UUID identifiers → login
  page. (Don't reintroduce the default eloquent provider.)
- **Reverb multi-host.** A single Reverb instance needs nothing special. Across
  multiple app servers, set `REVERB_SCALING_ENABLED=true` with a shared redis
  channel so a broadcast on one host fans out to clients connected to another.
- **`migrate:fresh` in dev must include `--seed`.** A bare `migrate:fresh`
  wipes the spatie roles; registration then 500s with `RoleDoesNotExist`.
