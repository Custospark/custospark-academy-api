# Custospark Academy — Deployment Runbook & Safety Guardrails

**OWNERSHIP:** Every agent that touches production (`academy.custospark.com`,
`academy-api.custospark.com`) or staging (`academy-staging.custospark.com`,
`academy-staging-api.custospark.com`) **MUST read this file in full before
writing any deployment plan or executing any deploy.**

**Golden rule: There is no room for error on the shared `custospark.com`
docroot.** If anything in this file is unclear — stop and ask Oscar. Never
guess, never assume, never "just try it" on a live environment.

---

## 1. Environments & topology

| Environment | Frontend (web) | Backend API | Purpose |
|---|---|---|---|
| Local dev | `localhost:5173` | `localhost:8000/api/v1` | Development, tests |
| **Staging** | `https://academy-staging.custospark.com` | `https://academy-staging-api.custospark.com/api/v1` | Pre-prod validation |
| **Production** | `https://academy.custospark.com` | `https://academy-api.custospark.com/api/v1` | Live learners |

**Hosting (Hostinger shared hosting, account `u214605677`):**
- Host: `147.79.103.136`, SSH port `65002`, user `u214605677`
- Backend Laravel app dirs (git clones of `github.com/Custospark/custospark-academy-api`, branch `master`):
  - Staging: `/home/u214605677/domains/academy-staging-api.custospark.com`
  - Production: `/home/u214605677/domains/academy-api.custospark.com`
- Web docroot folders (under the shared `custospark.com` domain):
  - Staging: `/home/u214605677/domains/custospark.com/public_html/academy-staging`
  - Production: `/home/u214605677/domains/custospark.com/public_html/academy`
- API is exposed through **symlinks** in the shared docroot (mirrors the
  `custosell-api`, `ssms-api`, `custocareai-api` pattern):
  - `.../public_html/academy-api` → `/home/u214605677/domains/academy-api.custospark.com/public`
  - `.../public_html/academy-staging-api` → `/home/u214605677/domains/academy-staging-api.custospark.com/public`
- The frontend web build is **shipped through the backend repo** at
  `Backend/public/production` and `Backend/public/staging`, then copied into
  the web docroot on the server. The backend `public/` folder is the single
  source of truth for deployed web builds.
- Credentials for the server live in `Backend/.env` under `SSH_DEPLOY_*` and are
  read by `Backend/scripts/ssh_run.py` (never typed, never printed).

**Separate databases per environment:**
- Staging DB and Production DB — the real credentials are **already present,
  commented out, in `Backend/.env`** (one block marked production, one marked
  `#Staging`). Uncomment the matching block in the **server** `.env`; never
  share databases between environments.

---

## 2. Server access & the SSH helper

```bash
cd Backend
python scripts/ssh_run.py "<one shell command>"
```

- `ssh_run.py` reads `SSH_DEPLOY_HOST/PORT/USER/PASSWORD` from `Backend/.env`.
  These values are **never printed** and never typed into chat.
- If creds are missing:
  ```
  SSH_DEPLOY_HOST=147.79.103.136
  SSH_DEPLOY_PORT=65002
  SSH_DEPLOY_USER=u214605677
  SSH_DEPLOY_PASSWORD=<value>
  ```
  must be present in `Backend/.env` (the same account is used by the Custosell
  project, so the values already exist there).

---

## 3. The shared `custospark.com` docroot is SHARED — safety map (P0)

`/home/u214605677/domains/custospark.com/public_html` hosts **many products**.
Never touch anything that is not an Academy path. Verified inventory
(2026-09-06):

| Entry | Type | Allowed for Academy deploys? |
|---|---|---|
| `academy/` | dir — **Academy prod web build** (placeholder `default.php` today) | ✅ **replace** (wipe + `cp -rT`) |
| `academy-staging/` | dir — **Academy staging web build** (placeholder `default.php`) | ✅ **replace** |
| `academy-api/` | dir — placeholder `default.php` today | ✅ **remove + replace with symlink** → `~/domains/academy-api.custospark.com/public` |
| `academy-staging-api/` | dir — placeholder `default.php` today | ✅ **remove + replace with symlink** → `~/domains/academy-staging-api.custospark.com/public` |
| `assets/`, `custocare/`, `psycho-ai/`, `profiles/`, `ssms/` | dirs — other products | ❌ **NEVER touch** |
| `custocareai-api`, `custosell-api`, `ssms-api` | symlinks — other products | ❌ **NEVER touch** |
| `index.html`, `.htaccess`, `icons.svg`, `custospark-logo-footer.png`, `favicon.png` | files — custospark.com landing | ❌ **NEVER touch** |

> The four `default.php` files inside the Academy folders are Hostinger
> placeholders. **They are removed as part of the first-time deploy** (approved
> by Oscar). No other existing entry is ever deleted.

---

## 4. Non-negotiables (violating ANY of these is a P0 incident)

1. **NEVER run `migrate:fresh`, `migrate:refresh`, `db:wipe`, or `drop database`
   on any environment.** We never wipe. We only ever **add** (migrations are
   additive and forward-only).
2. **NEVER run `key:generate` on staging/production AFTER the first-time setup**
   (the DB already has users/sessions). It invalidates every session, token, and
   encrypted cookie. First-time setup is the ONLY exception (fresh, empty DB).
3. **NEVER delete or edit an existing migration.** Migrations are historical.
   To change schema, add a **new** forward migration.
4. **NEVER `git add -A` on the shared backend repo** during a deploy — only the
   exact paths that belong to the deploy may be committed (§7, §8).
5. **NEVER run `php artisan migrate` without `--force`** in production/staging —
   non-interactive environments hang or abort without it.
6. **NEVER expose or print secrets** (passwords, tokens, private keys, `.env`
   contents). Read them from env files via scripts; mask them in output.
7. **NEVER deploy from a dirty working tree** locally or on the server. Verify
   `git status --short` is clean before/after.
8. **NEVER wipe anything on the server before creating a rollback backup** (§9).
9. **NEVER touch non-Academy entries in the shared `custospark.com` docroot**
   (§3). Only the four `academy*` paths may change.
10. **After first-time seeding, the seeded demo passwords MUST be changed
    immediately** — `UserSeeder` creates learner/instructor/admin accounts with
    a known password. Change them (or delete the demo accounts) right after
    seeding on staging and production.

---

## 5. Mandatory deployment plan (the approval gate)

> **Every deploy — no matter how small — requires a written deployment plan that
> is approved by Oscar BEFORE any command runs on a shared environment.**

The plan MUST be produced as a todo list (via the todo tool) and contain:
**Scope** (exact commits backend + frontend, one line per change), **files
touched**, **migrations** (list + `--force` + "no destructive migration"),
**backup step** (timestamped path), **rollback plan** (exact commands),
**verification plan** (HTTP status, MIME types, asset counts, no error logs),
and **owner approval**.

**Never "deploy and see". Always "plan → approve → execute → verify → report".**

---

## 6. First-time deploy (this deployment — once only)

Do this for **staging first**, then production (stage → validate → prod).

### 6.1 Prerequisites (Oscar, in hPanel)
- [ ] Databases exist: production + staging (names/usernames match the commented
      blocks already in `Backend/.env`).
- [ ] Subdomains exist and resolve (verified 2026-09-06: all four `academy*`
      subdomains resolve and answer).
- [ ] Confirm each subdomain's docroot is the shared folder
      `~/domains/custospark.com/public_html/<name>` (this is where the
      placeholders live today).

### 6.2 Local: gates + push
```bash
cd Backend && composer vera:fast && php artisan test && git status --short
cd Frontend && npm run veras:fast  # typo guard: npm run vera:fast
```
Push both repos to GitHub (`master`).

### 6.3 Clone the app dirs on the server
```bash
python scripts/ssh_run.py "git clone https://github.com/Custospark/custospark-academy-api.git /home/u214605677/domains/academy-api.custospark.com"
python scripts/ssh_run.py "git clone https://github.com/Custospark/custospark-academy-api.git /home/u214605677/domains/academy-staging-api.custospark.com"
```

### 6.4 Create the server `.env` (per environment) + APP_KEY
The commented DB blocks in `Backend/.env` hold the real DB credentials (host,
port, database, username, password). On the server:
```bash
cd /home/u214605677/domains/academy-api.custospark.com          # per env
cp .env.example .env
# edit .env: APP_ENV=production|staging, APP_DEBUG=false,
#   APP_URL=https://academy-api.custospark.com        (or academy-staging-api)
#   FRONTEND_URL=https://academy.custospark.com       (or academy-staging)
#   CERTIFICATE_VERIFY_URL=https://academy-api.custospark.com
#   uncomment the matching DB block (production / #Staging)
#   SESSION_DOMAIN/MAIL_* per Hostinger
php artisan key:generate          # FIRST-TIME ONLY on an empty DB
```

### 6.5 Dependencies + storage link
```bash
cd /home/u214605677/domains/<env>.custospark.com
/usr/bin/php -d memory_limit=512M /usr/local/bin/composer install --no-dev --prefer-dist --no-interaction
/usr/bin/php artisan storage:link
```

### 6.6 Migrate + seed (first-time only)
```bash
/usr/bin/php artisan migrate --force
/usr/bin/php artisan db:seed --force
/usr/bin/php artisan optimize:clear && /usr/bin/php artisan config:clear
```
> `CourseSeeder` is env-guarded and no-ops outside local/dev/testing. `UserSeeder`
> creates `learner@` / `instructor@` / `admin@custospark.com` (known password) —
> **change those passwords immediately after seeding** (or delete the demo
> accounts) before going live.

### 6.7 Wire the API symlinks (removes the placeholder `default.php` dirs)
```bash
cd /home/u214605677/domains/custospark.com/public_html
rm -rf academy-api
ln -s /home/u214605677/domains/academy-api.custospark.com/public academy-api
rm -rf academy-staging-api
ln -s /home/u214605677/domains/academy-staging-api.custospark.com/public academy-staging-api
```

### 6.8 Ship the web build (§8) — staging first, then production.

### 6.9 Set up the cron in hPanel (one per environment)
```
/usr/bin/php /home/u214605677/domains/academy-api.custospark.com/artisan schedule:run
/usr/bin/php /home/u214605677/domains/academy-staging-api.custospark.com/artisan schedule:run
```
All five fields `*`. `crontab` is NOT installed on the host — hPanel only.

### 6.10 Verify (§8.4) and report.

---

## 7. Standard backend deploy (staging or production — every later deploy)

### 7.1 Pre-flight (local)
```bash
cd Backend
composer vera:fast
php artisan test --filter=<changed area>   # targeted tests, never the full suite in a loop
git status --short                          # clean
```

### 7.2 Push (local)
```bash
git add <exact paths>            # NOT git add -A
git commit -m "<descriptive message>"
git push origin master
```

### 7.3 Deploy to server (one environment at a time)
```bash
python scripts/ssh_run.py "
cd /home/u214605677/domains/<academy-api|academy-staging-api>.custospark.com
git fetch origin
git reset --hard origin/master             # bulletproof mirror; .env is gitignored + preserved
/usr/bin/php artisan migrate --force       # ONLY if migrations changed, with --force
/usr/bin/php artisan optimize:clear
/usr/bin/php artisan config:clear
/usr/bin/php artisan route:clear
"
```

### 7.4 Post-deploy backend verification
```bash
python scripts/ssh_run.py "
cd /home/u214605677/domains/<env>.custospark.com
/usr/bin/php artisan about | grep -iE 'Environment|PHP'
/usr/bin/php artisan schedule:list
curl -s -o /dev/null -w '%{http_code}\n' https://<env>.custospark.com/
curl -s https://<env>.custospark.com/api/v1/courses | head -c 200   # public catalog
"
```

---

## 8. Frontend (web) deploy

The frontend web build is shipped via the **backend repo**, then copied to the
web docroot.

### 8.1 Build & push (local, frontend repo)
```bash
cd Frontend
npm run deploy:web:staging       # OR npm run deploy:web:production
```
`scripts/deploy-to-backend.mjs`:
1. typechecks (`tsc -b`) and builds with `--mode staging|production` and a
   relative asset base (`./`), output `dist/web`.
2. copies `dist/web` → `Backend/public/staging` (or `production`).
3. copies `deploy/htaccess.staging` (or `.production`) in as `.htaccess`.
4. commits **only** `public/<target>` in the backend repo and pushes.
   The commit message IS the release record
   (`deploy(web): <target> build v<version> under public/<target>`).

### 8.2 Server: pull + copy into the web docroot
```bash
python scripts/ssh_run.py "
cd /home/u214605677/domains/<academy-api|academy-staging-api>.custospark.com
git fetch origin
git reset --hard origin/master
git status --short | grep -v '^??'       # expect empty

cd /home/u214605677/domains/custospark.com/public_html
rm -rf <academy|academy-staging> && mkdir -p <academy|academy-staging>
cp -rT /home/u214605677/domains/<env>.custospark.com/public/<production|staging> <academy|academy-staging>
"
```
> **MUST use `cp -rT`** (bash `*` silently skips dotfiles like `.htaccess` — the
> #1 cause of broken SPA deploys). `cp -rT SRC DST` copies the **contents** of
> `SRC`, including dotfiles.

### 8.3 Web post-deploy verification (critical)
```bash
python scripts/ssh_run.py "
cd /home/u214605677/domains/custospark.com/public_html/<academy|academy-staging>
echo \"disk assets: \$(ls assets | wc -l)\"
echo \"index refs:  \$(grep -oE 'assets/[a-zA-Z0-9_/-]+\.js' index.html | sort -u | wc -l)\"
grep -oE 'assets/[a-zA-Z0-9_/-]+\.js' index.html | sort -u | while read f; do [ -f \"\$f\" ] || echo \"MISSING: \$f\"; done
JS=\$(grep -oE 'assets/[a-zA-Z0-9_/-]+\.js' index.html | sort -u | head -1)
curl -s -o /dev/null -w 'JS: %{http_code} %{content_type}\n' https://<academy|academy-staging>.custospark.com/\$JS
curl -s -o /dev/null -w 'root: %{http_code}\n' https://<academy|academy-staging>.custospark.com/
curl -s -o /dev/null -w 'deep: %{http_code}\n' https://<academy|academy-staging>.custospark.com/catalog
curl -s -o /dev/null -w 'api:  %{http_code}\n' https://<academy-api|academy-staging-api>.custospark.com/api/v1/courses
"
```

### 8.4 Every verification must pass
- [ ] Web root HTTP 200 and deep SPA route 200
- [ ] Served JS is a JS MIME (`application/javascript`), NEVER `text/html`
      (`text/html` = SPA rewrite serving index.html for a missing file)
- [ ] 0 assets referenced by `index.html` are missing on disk
- [ ] Disk asset count == index.html reference count
- [ ] API returns sane JSON on a public endpoint (`/api/v1/courses`)
- [ ] Built API base points at the right env (staging build never leaks the
      production API URL)
- [ ] No new error/exception lines in `storage/logs/laravel.log`
- [ ] The non-Academy entries in the shared docroot survived (§3)

---

## 9. Backup & rollback (mandatory before ANY wipe)

### 9.1 Pre-deploy backup (every time)
```bash
python scripts/ssh_run.py "
cd /home/u214605677/domains/custospark.com/public_html
TS=\$(date +%Y%m%d-%H%M%S); cp -rT <academy|academy-staging> academy<|-staging>-backup-\$TS; echo BACKUP=academy<|-staging>-backup-\$TS
"
```
For database-affecting deploys, take a DB dump **before** migrating (from the
server `.env` DB block):
```bash
python scripts/ssh_run.py "
cd /home/u214605677/domains/<env>.custospark.com
tr -d '\r' < .env > /tmp/env.sh && set -a && . /tmp/env.sh && set +a && rm -f /tmp/env.sh
TS=\$(date +%Y%m%d-%H%M%S); BK=/home/u214605677/domains/backups/academy-<env>-\$TS.sql
mkdir -p /home/u214605677/domains/backups
/usr/bin/mysqldump -h \${DB_HOST:-localhost} -P \${DB_PORT:-3306} -u \"\$DB_USERNAME\" -p\"\$DB_PASSWORD\" \"\$DB_DATABASE\" > \"\$BK\" 2>/dev/null
echo BACKUP=\$BK; ls -la \"\$BK\"        # MUST be non-zero, else STOP
"
```

### 9.2 Rollback
- **Frontend:** `rm -rf <academy|academy-staging>` then
  `cp -rT <backup-folder> <academy|academy-staging>`, re-run §8.3.
- **Backend code:** `git fetch origin && git checkout <previous-knowngood>` in
  the app dir, then forward-only migrations if needed.
- **DB:** restore the pre-deploy dump via hPanel or `mysql` client.

---

## 10. Scheduler / cron guardrails

- The Laravel scheduler runs via one Hostinger cron firing
  `artisan schedule:run` **every minute** — one cron **per environment**, all
  five fields `*`. Do NOT create per-task crons.
- `crontab` is **not installed** on this shared host — cron is managed in hPanel.
- Correct paths always include `domains/` (a missing `domains/` makes the cron
  fail with `Could not open input file`).
- After a deploy that touches `routes/console.php`, run `schedule:list` and
  confirm the expected commands are registered.
- If two crons ever target the same environment, delete one — duplicate
  `schedule:run` causes double firing.

---

## 11. Known server facts & gotchas (do not rediscover)

- Hostinger shared account `u214605677`; SSH `147.79.103.136` port `65002`
  (via `ssh_run.py`, creds in `Backend/.env`).
- PHP 8.2.33 (`/usr/bin/php`), Composer 2.9.8 (`/usr/local/bin/composer`),
  `mysqldump` from MariaDB 11.8.8, git 2.52.0.
- `~/domains/custospark.com/public_html` is SHARED by many products (§3).
  Only `academy`, `academy-staging`, `academy-api`, `academy-staging-api` belong
  to Academy. A blanket `rm -rf` there can destroy other products in one command.
- The four `default.php` placeholders under the Academy folders are removed at
  first-time deploy (§6.7) — that is the ONLY approved deletion.
- Server `.env` is gitignored and preserved across `git reset --hard`.
- `.env` on the server may have CRLF line endings — if sourcing it, strip CR
  first (`tr -d '\r'`), and always verify a `mysqldump` is non-empty.
- `git push` of large builds can 408 — if that happens, raise
  `git config http.postBuffer 524288000` then push again.
- Never commit/push the `academy-backup-*` folders — they are rollback
  artifacts, not part of any repo.
- The Backend default git branch is `master` (not `main`) — all server
  `git reset --hard origin/master` commands use it.

---

## 12. Deployment plan template (copy into the todo list)

```
DEPLOYMENT PLAN — <staging|production> — <YYYY-MM-DD>

SCOPE
- Backend: commit(s)  [what changed]
- Frontend: commit(s) [what changed]
- Repos touched: Custospark/custospark-academy-api, Custospark/custospark-academy-frontend

MIGRATIONS
- New migrations to run (with --force): <list>
- Confirmation: NO destructive migration (no drop/refresh/fresh).

BACKUP
- Web docroot backup: <path> (created + verified before wipe)
- DB backup (if migrations affect data): <dump path>

ROLLBACK
- Frontend: <exact restore command>
- Backend: <exact restore command>

STEPS
1. <pre-flight checks>
2. <build + commit + push>
3. <server pull / reset>
4. <migrate --force if needed>
5. <cache clears>
6. <frontend backup>
7. <wipe + cp -rT>
8. <verification per §8.4>

VERIFY (all must pass)
- [ ] HTTP 200 on web root + deep route
- [ ] JS MIME = application/javascript (not text/html)
- [ ] 0 missing assets referenced by index.html
- [ ] API smoke OK
- [ ] no new errors in storage/logs/laravel.log
- [ ] non-Academy shared-docroot entries untouched

APPROVAL
- Presented to Oscar: [ ] APPROVED   Date/time: ______
```

---

## 13. Checklists (quick reference)

### Pre-deploy
- [ ] Read this runbook fully
- [ ] Deployment plan written as todos + presented for approval
- [ ] Local: vera:fast pass, targeted tests pass, `tsc -b` clean (frontend)
- [ ] Local working tree clean
- [ ] No destructive migration in the diff
- [ ] Backup plan defined (web docroot + DB if applicable)

### During
- [ ] Only exact paths staged (never `git add -A` on the backend repo)
- [ ] `git fetch origin && git reset --hard origin/master` (server mirror)
- [ ] `migrate --force` only when migrations changed
- [ ] `cp -rT` for the frontend copy (never `*`)
- [ ] Only `academy*` entries touched in the shared `custospark.com` docroot
- [ ] No secret printed in any output

### Post-deploy
- [ ] §8.4 web verification (asset count, missing refs, MIME, deep route)
- [ ] API smoke test (public endpoint returns sane JSON)
- [ ] scheduler `schedule:list` confirms commands
- [ ] error-log sweep clean
- [ ] seeded demo passwords changed/deleted (first-time only)
- [ ] Report to Oscar: what shipped, verified results, backup path, rollback cmd