# Deploying with DeployDesk

How the backend (this repository) and the admin panel are put on the cPanel server (PKWebHost) with **DeployDesk**, the deploy tool of Siteffects (https://deploydesk.siteffects.com/, sign in with your own account; never write a password in a file). Written step by step on 2026-11-17 while the first deploy was being set up; the parts marked **(check)** were not seen working yet and have to be confirmed on the first deploy, then this page is corrected.

The idea: you push to GitHub, a GitHub Action (the "CI workflow" DeployDesk puts in the repository) **builds** the project there, not on your host (the host has a limit on processes), DeployDesk keeps the result as a numbered **release** (v1, v2, ...), and **Deploy** uploads that release to a folder on the server. **Rollback** puts an older release back.

| Part | Repository | Becomes on the server |
|---|---|---|
| API (Laravel) | `RivoxaTech/jhkstore_backend` | the folder `/home/rivoxate/jhkstore/backend` (document root of the API domain: its `public/` folder) |
| Admin panel (Vue) | `RivoxaTech/jhkstore_admin-frontend` | the folder `/home/rivoxate/jhkstore/backend/public/admin-assets` (inside the API's `public/`) |
| Storefront (Nuxt) | `RivoxaTech/jhkstore_frontend` | not in DeployDesk yet: Cloudflare Pages, or later a project of its own that deploys the static site to the shop domain's folder |

## A. One-time setup

### A1. Connect GitHub and the server (done)
* **Git accounts**: the GitHub account `UsmanAli-1` is connected (a personal access token; it must see the three repositories and be allowed to write workflow files and repository secrets). Press *Verify* if a deploy complains about GitHub.
* **Servers**: `rivoxate` (cPanel, port 2083, "cPanel API"). Press *Test*: the credentials were last verified over 30 days ago on 2026-11-17.

### A2. Import the two projects
Projects → **New project** → account `UsmanAli-1` → tick `jhkstore_backend` and `jhkstore_admin-frontend` (not the old `RivoxaTech/jhkstore`, not `jhkstore_frontend` for now) and fill:

| | `jhkstore_backend` | `jhkstore_admin-frontend` |
|---|---|---|
| Framework | `laravel` | `vue` |
| Branch | `main` | `main` |
| Sub-directory | `.` | `.` |
| Install command | `echo "no frontend"` (the default for Laravel; **(check)** the workflow runs `composer install --no-dev` itself, else put `composer install --no-dev --optimize-autoloader --no-interaction` here) | `npm ci` |
| Build command | `echo "no frontend build"` | `ADMIN_BUILD_OUT=dist npm run build` |
| Output directory | `.` | `dist` |

`ADMIN_BUILD_OUT=dist` matters: the panel normally writes into `../backend/public/admin-assets`, a folder that does not exist on GitHub's build machine.

Tick **Install the CI workflow now** and **Open a pull request instead of committing to the branch**, then *Import*. DeployDesk opens a pull request in each repository that adds `.github/workflows/deploydesk.yml` and sets two repository secrets.

### A3. Read and merge the pull requests
Read `.github/workflows/deploydesk.yml` in each pull request before you merge it (it runs on GitHub with the repository's secrets): which commands it runs, what it uploads, where it sends the result. Check for the backend that it installs the Composer packages (`vendor/`) and that it **does not** put `.env`, `tests/`, `docs/`, `scripts/` or `storage/` contents into the release. Merge, then on your computer: `git pull` in `backend/` and in `admin-frontend/`.

### A4. Prepare the server by hand, once
Do this in the cPanel *Terminal* (DeployDesk does not make databases, secrets or seed data). Details of every step: [NEW_CLIENT.md](NEW_CLIENT.md) section 3b and [GO_LIVE.md](GO_LIVE.md) section 6.
1. Create the folder `/home/rivoxate/jhkstore/backend` and the API domain `api.yourshop.com` with its **document root** at `/home/rivoxate/jhkstore/backend/public`. PHP 8.3 for the domain (MultiPHP Manager), with `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `gd`, `exif`, `curl`, `intl`; `upload_max_filesize` and `post_max_size` above 50 MB.
2. Create the MySQL database and user in cPanel.
3. Write `/home/rivoxate/jhkstore/backend/.env` on the server (from `.env.example`): `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `DB_*`, `FRONTEND_URLS`, `SANCTUM_STATEFUL_DOMAINS` (the API domain only), `SESSION_SECURE_COOKIE=true`, `SEED_DEVELOPER_PASSWORD` and `SEED_ADMIN_PASSWORD` (12+ characters), mail, VAPID. **The `.env` stays on the server for ever; no deploy may overwrite or delete it.**
4. After the first backend deploy (A6): `php artisan key:generate`, `php artisan migrate --force --seed` (never `DemoSeeder`, never `migrate:fresh`), `php artisan storage:link`, `php artisan config:cache && php artisan route:cache && php artisan event:cache && php artisan view:cache`.
5. Cron Jobs: `* * * * * cd /home/rivoxate/jhkstore/backend && php artisan schedule:run >> /dev/null 2>&1` and `* * * * * cd /home/rivoxate/jhkstore/backend && php artisan queue:work --stop-when-empty --max-time=55 >> /dev/null 2>&1`, plus, once a day at night, `0 3 * * * cd /home/rivoxate/jhkstore/backend && php artisan shop:rebuild >> /dev/null 2>&1` when the storefront is rebuilt by a hook (see GO_LIVE.md, "How often must the storefront be rebuilt?").

### A5. Targets (Project settings, one per project and environment)
Point an environment at a server and a folder. Make **Staging** first (a test folder and a test subdomain), **Production** afterwards.

| Project | Environment | Server | Folder | Strategy |
|---|---|---|---|---|
| `jhkstore_backend` | Production | `rivoxate` | `/home/rivoxate/jhkstore/backend` | **in place** |
| `jhkstore_admin-frontend` | Production | `rivoxate` | `/home/rivoxate/jhkstore/backend/public/admin-assets` | in place (or full) |

**Strategy "in place" for the backend, not "symlink" or "rename"**: those replace the whole folder, and the folder holds the `.env`, the uploaded pictures (`storage/app/public`), the private payment proofs (`storage/app/private`) and the `public/storage` link, which are not part of any release. **(check)** that "in place" does not delete files that are not in the release; if it does, stop and ask your colleague for a "shared files" or "keep" list (`.env`, `storage/`, `public/storage`, `public/admin-assets`).

### A6. The first deploy
Open the project → **Build now** (the release v1 appears under *Releases* when the build is done) → choose the environment → **Deploy** → read the **preview** → **Dry run** first (connects and compares, writes nothing) → confirm the real deploy. Then A4 step 4 for the backend.

## B. The routine: a change goes live

1. Work in the repository on a branch, test, merge into `main` (the CI builds on a push to `main`).
2. DeployDesk: **Releases** → the new release (v2, v3, ...) → **Deploy** to Staging → look at it → **Deploy** to Production.
3. After a **backend** deploy, on the server (cPanel Terminal, or the server's *Commands* button in DeployDesk **(check)**): `cd /home/rivoxate/jhkstore/backend && php artisan migrate --force && php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan queue:restart`. (`migrate` only when the release has a new migration; it never runs `migrate:fresh`.)
4. After an **admin panel** deploy: nothing on the server; open `/admin` and reload with Ctrl+F5 (the panel installed as an app updates by itself the next time it opens).
5. Something wrong: **Rollback** to the release before (backend: a migration of the bad release is not undone, check before rolling back).

The storefront is separate: it is rebuilt by its own host (see [GO_LIVE.md](GO_LIVE.md)); it needs the API to be live when it is built.

## C. Rules
* Never put a password, a token, `.env` or `storage/` files into a repository or into DeployDesk's build settings.
* Never run `migrate:fresh` or `DemoSeeder` on the server.
* Deploy the backend first, then the admin panel (the panel and the API must match).
* A GitHub token expires: if a build or *Verify* fails with a GitHub error, make a new token and update it under *Git accounts*.
* The host limits the number of processes: DeployDesk builds on GitHub, so nothing is built on the host; keep the `queue:work` cron as `--stop-when-empty` (no worker that stays alive).

## D. To confirm on the first deploy (then delete this list)
- [ ] The Laravel workflow runs `composer install --no-dev --optimize-autoloader` and the release contains `vendor/`.
- [ ] The release does not contain `.env`, `tests/`, `docs/`, `scripts/`, or `storage/` files (or they are harmless on the server).
- [ ] "In place" keeps `.env`, `storage/*`, `public/storage` and `public/admin-assets` on the server.
- [ ] Which commands the server's *Commands* button can run (can it run `php artisan migrate --force`?).
- [ ] Whether a project's webhook can be used as the *Rebuild hook* of the admin panel (our button sends an empty `POST` with the header `X-Rebuild-Secret`), for the storefront.
- [ ] How long a deploy of the backend takes and how many processes the server shows during it (cPanel *Resource Usage*, limit 100).

## E. If something fails
| Symptom | Look at |
|---|---|
| The build fails on `composer install` | the build log in DeployDesk / GitHub Actions: PHP version (needs 8.3), a missing extension |
| The admin build is empty or in the wrong place | the build command must be `ADMIN_BUILD_OUT=dist npm run build`, output `dist` |
| `500` after a backend deploy | the server's `storage/logs/laravel.log`; `php artisan config:cache` again; is `.env` there? |
| Pictures give `404` | `php artisan storage:link` on the server |
| `/admin` shows "Admin panel is not built yet" | the admin project was not deployed to `backend/public/admin-assets` |
| Login works on the API but the panel loops | `SANCTUM_STATEFUL_DOMAINS` and `APP_URL` in the server's `.env`, then `config:cache` |
| GitHub error in DeployDesk | *Git accounts → Verify*, a new token |
