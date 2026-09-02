# Deploying Zippi School

CI/CD for `zippintella-dev/zippi-school`. Modelled on `zippintella-dev/zippi-backend`,
which already deploys to the same host with the same release layout — the two are
kept the same shape deliberately, so debugging one teaches you the other.

---

## Branches

```
feature/*  ──PR──▶  develop  ──automatic──▶  staging
                       │
                       └──PR──▶  main  ──manual button──▶  production
```

| Branch | Protected | Deploys to | How |
|---|---|---|---|
| `develop` | yes — `CI / required` | staging | automatic on push |
| `main` | yes — `CI / required` | production | `workflow_dispatch`, type `deploy` |

Production is **manual on purpose**. This application decides whether a child is
released to whoever is standing at a kerb. An automatic deploy triggered by a
merge can land in the middle of an afternoon dismissal, and the person who merged
is not necessarily watching. Requiring a button means somebody chose the moment.

Avoid deploying during **07:00–09:30** and **14:00–16:30 IST** — the morning and
afternoon runs. `deploy.sh` is zero-downtime, but a failed health check triggers a
rollback, and a rollback during dismissal is the worst time to find a bad release.

---

## What CI checks

`.github/workflows/ci.yml`, on every push and PR to `main`/`develop`.

| Job | Gate | What it protects |
|---|---|---|
| Lint and static checks | composer validate + audit block; **Pint report-only** | dependency CVEs, lockfile drift |
| Secret scan | blocking | `.env`, `*.sqlite`, sweep photos, APKs |
| PHPUnit (SQLite) | **blocking** | 172 tests, the documented engine |
| PHPUnit (MySQL) | schema blocking, tests report-only *(see below)* | the engine the server runs |
| Safety invariants | **blocking, never softened** | the four invariants + the no-sync-seam rule |
| Flutter (parent, fleet) | **blocking** | 17 + 59 tests, analyze, lockfile drift |
| Repository hygiene | **blocking** | PART L1 date casts, bare `auth`, `token.holder` |
| required | aggregate | the single status check for branch protection |

Point branch protection at **`CI / required`** only. New jobs get picked up
automatically without a settings change.

### Two report-only steps, and when to flip them

Both are marked `continue-on-error` with a comment saying so:

1. **Pint** — flags ~80 files; style has never been enforced here. Flip it after a
   one-off `vendor/bin/pint` run committed *on its own*. Do not bundle that diff
   with anything, and check it has not rewritten the ⚠ comment blocks that carry
   the safety reasoning.
2. **PHPUnit on MySQL** — non-blocking only because the job had never run when it
   was introduced. **Flip it as soon as one green run is observed on `develop`.**
   Staging runs MySQL; an engine difference that only CI knows about is worth
   nothing.

`migrate:fresh` on MySQL is blocking in both cases. That is the step that stops a
bad migration reaching the staging database.

### The PART L1 guard

`hygiene` greps for a `'date'` cast on `service_date`, `school_calendars.date` and
any `effective_from` / `effective_to`. Adding one shifts every IST date back a day,
**silently** — nothing throws, rows just stop matching, and it surfaces days later
as *"I marked her absent and the bus still waited."*

No test catches a cast that has not been added yet, which is why this is a grep.
It strips comment lines first, because seven models carry warning comments that
quote the forbidden line verbatim.

---

## First-time server setup

The app needs its **own** slot. `staging.zippi.in` and `/var/www/html/staging`
belong to the ride backend — deploying here would replace it.

```bash
# on the server, as a sudoer
./deploy/bootstrap-server.sh staging            # DRY RUN, prints the plan
./deploy/bootstrap-server.sh staging --apply
```

It creates `…-releases/`, `…-shared/.env`, the storage skeleton and the
`schedule:run` cron. It refuses to run twice, and refuses to target the ride
backend's directories.

It deliberately does **not** create the database, the app key, the nginx block or
the TLS certificate — it prints those as commands to run and check. Two that matter:

- **nginx** must use `fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;`
  `$realpath_root` resolves the symlink per request. Without it php-fpm keeps
  serving the old release from its path cache and deploys appear to do nothing.
- **The cron** is what fires PART M2 trip generation at 02:30 IST. There is no
  just-in-time fallback yet (PART M11 is not built), so a missing cron means an
  empty live board at 07:00. `deploy.sh` warns on every deploy if it is absent.

### Repository secrets

Four, same names as `zippi-backend`. If deploying to the same EC2 host, they are
the same values.

| Secret | Notes |
|---|---|
| `SSH_HOST` | |
| `SSH_USER` | |
| `SSH_PRIVATE_KEY` | the **entire** file including BEGIN/END lines |
| `SSH_KNOWN_HOSTS` | pins the host key — never swap this for `StrictHostKeyChecking=no` |

Also create the `staging` and `production` GitHub **Environments**. Put the
approval gate on `production`; that is what makes the build wait for a human
rather than building first and asking afterwards.

---

## How a deploy runs

`deploy.yml` builds and ships in one job — no Actions artifact between them.
`zippi-backend` learned that on 2026-08-31, when the artifact storage quota filled
and every staging deploy died on upload with a perfectly good tarball already built.

On the server, `deploy.sh`:

1. unpacks beside the live release (nothing live can break yet)
2. links shared `.env` and `storage`
3. warms `config` / `route` / `view` caches **inside** the new release — a bad
   `.env` key or blade error fails here, while the old release still serves
4. runs migrations — the one irreversible step, deliberately after (3)
5. atomically swaps the symlink, reloads php-fpm, restarts workers if any exist
6. health-checks `/up`, **rolling back automatically** if it never returns 200
7. warns if the scheduler cron is missing
8. prunes to the last 5 releases

### Two things `deploy.sh` does that the ride backend's does not

- **It caches config and routes.** `zippi-backend` cannot: it has 23 `env()` calls
  outside `config/`, and caching makes those return `null`. This codebase has
  **zero**, verified across `app/`, `routes/`, `resources/views/` and `bootstrap/`.
  Re-check before assuming it is still true:
  `grep -rn "env(" app/ routes/ resources/views/ bootstrap/ | wc -l`
- **It tolerates a missing queue worker.** `QUEUE_CONNECTION=database` is set, but
  this app's background work is a nightly artisan schedule, not a queue. A missing
  supervisor group warns instead of failing.

### ⚠ Never add `cache:clear` to the deploy

`CACHE_STORE=database`, and the cache is not a performance detail here:

- [`Child.php:88`](../app/Models/Child.php#L88) stores the day's **handover code
  plaintext** until 23:59. The database keeps only a hash. That code is what a
  guardian reads out at the kerb (PART A7) — the receiver verification behind
  **Invariant #1**.
- [`FleetTripService.php:1200`](../app/Services/FleetTripService.php#L1200) keeps
  the keypad lockout counters.
- `OtpService` keeps issued login codes.

A deploy at 14:30 that cleared the cache would delete every child's handover code
mid-afternoon. Every drop stop would then fail its receiver check and fall through
to the escalation ladder, and a bus full of children could not be released.

The `config`/`route`/`view` caches are a different thing — they live in
`bootstrap/cache` inside the release directory and touch no child data.

---

## Rolling back

`deploy.sh` rolls back by itself when its own health check fails. `rollback.sh` is
for the other case: the deploy passed and the breakage appeared later in traffic.

```bash
./deploy/rollback.sh staging                 # list releases, step back one
./deploy/rollback.sh production <release-id> # to a specific release
```

**It does not reverse migrations.** If the bad release changed the schema, moving
the code back leaves the app running against a schema it does not expect — a
forward fix is usually safer.

That is especially true here: a migration that altered a DATE column may have
rewritten stored values, and rolling the *code* back does not roll the *data* back.
A date shifted by one day presents as *"the bus still waited"* days later.

---

## The mobile apps are not deployed by this

`mobile/` is excluded from the release tarball. The two Flutter apps are **clients**
of this server, not part of what it serves, and shipping their source would put the
fleet app's code on a public web root.

Both resolve their API base at runtime (`Config.apiBase`, overridable on the login
screen), so pointing them at staging is a field change, not a rebuild — which is
exactly why that override exists: a DHCP lease moved three times in two days during
the pilot and `String.fromEnvironment` is a compile-time constant.

Build APKs against staging with:

```bash
flutter build apk --dart-define=ZIPPI_API_BASE=https://school-staging.zippi.in
```

---

## Open item

The hostnames `school-staging.zippi.in` / `school.zippi.in` and the app dirs
`school-staging` / `school` are **proposals**, not confirmed. They appear in three
files that must agree:

- `deploy/deploy.sh` (the `case` block)
- `deploy/rollback.sh` (the `case` block)
- `deploy/bootstrap-server.sh` (the `case` block)

plus `health_url` in `deploy-staging.yml` and `deploy-production.yml`. Change all
five together.
