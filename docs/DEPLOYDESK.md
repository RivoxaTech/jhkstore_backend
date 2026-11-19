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

## F. What happened on the first deploy (2026-11-17)

* **Two projects per repository.** A first import that failed (the GitHub token could not write) left half-made projects, and the second import made the real ones. Every name was in the list twice: the real one has the `v1` release and a workflow; open it and check that the address bar has the id that is in the workflow file (`/api/v1/ci/projects/<id>/...`). Do **not** press *Update workflow* ("Outdated" is expected: the backend workflow carries one extra step by hand, section A3) and do not delete the empty duplicates before you know what the *Danger zone* button removes.
* **Targets** were added to the real projects: *Environments and targets → Production → Add target*: server `rivoxate (cpanel)`, remote path, strategy *In place*, *Preserved paths* left **empty** (the framework's safe defaults), *Delete files that are not in the release* off, *Redirect the site root to the public folder* off, no pre- or post-deploy commands yet (they need SSH). *Require approval before deploying* was switched off on Production (one person works on the shop). The health check URL stays empty until the API domain answers, or the first deploy rolls itself back.
* **Dry run first** (Options step of the deploy dialog): 8,412 files to add, nothing written. Then the real deploy: **8,412 files, 32.8 MB, 13 seconds**, steps lock, preflight, back up, upload, health check, finish. Later deploys send only the changed files.
* **The server's folder after the deploy**: `app`, `bootstrap`, `config`, `database`, `public`, `resources`, `routes`, `storage`, `vendor`, `artisan`, `composer.json`, `.env.example`, `.gitignore`, plus three files of the tool: `.deploydesk-state.json` (its record of what is on the server, 1.3 MB, outside `public/`), `.env.production` (one empty line, from the workflow's "build variables" step: harmless, and never put secrets into the panel's build variables) and **`.env`**. No `.git`, `tests`, `docs` or `scripts` (the cleanup step works).
* **`.env` is made by DeployDesk on the first deploy** with three lines: `APP_KEY=base64:...` (a new key), `APP_ENV=production`, `APP_DEBUG=false`. It is a *preserved* file: later deploys do not touch it. Fill in the rest (section A4, template below). If that key was ever shown to somebody (a screenshot, a chat), make a new one **before** the first login or the first saved setting: `php artisan key:generate --force`. Never do it later: the mail password and the hook secret are stored encrypted with it and would be lost.

### The production `.env` (fill in the angle brackets; write it with the cPanel File Manager editor, never in a repository or a chat)
```
APP_NAME="JHK Store"
APP_ENV=production
APP_KEY=<keep the line DeployDesk made, or the new one>
APP_DEBUG=false
APP_URL=https://<api domain>
APP_TIMEZONE=Indian/Mauritius
LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=<cpanel prefix_database>
DB_USERNAME=<cpanel prefix_user>
DB_PASSWORD=<the database password>

SESSION_DRIVER=file
CACHE_STORE=file
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database

MAIL_MAILER=log
# set the real mail account in the panel: Settings > E-mail (then send the test e-mail)

FRONTEND_URLS=https://<shop domain>
SANCTUM_STATEFUL_DOMAINS=<api domain, without https://>

SEED_DEVELOPER_USERNAME=developer
SEED_DEVELOPER_PASSWORD=<12 or more characters, not the user name>
SEED_ADMIN_USERNAME=admin
SEED_ADMIN_PASSWORD=<12 or more characters, not the user name>

PRODUCT_VIDEO_MAX_KB=51200
ADMIN_TOKEN_MINUTES=720
```
`SESSION_DRIVER=file` and `CACHE_STORE=file` save database queries on every request (the host limits CPU and processes). After the file is saved: `cd /home/rivoxate/jhkstore/backend`, `php artisan migrate --force --seed`, `php artisan storage:link`, `php artisan config:cache && php artisan route:cache && php artisan event:cache && php artisan view:cache`, and the cron lines of A4 step 5.

### G. The first migration on PKWebHost (2026-11-18)
* **`SQLSTATE 1045 Access denied`** was the database password in `.env` (the host `localhost` was right; MySQL names the host it found). Give the database user a password of letters and digits, test it alone with `mysql -u USER -p DATABASE -e "select 1"`, then write it in `.env` as `DB_PASSWORD="..."`.
* **`SQLSTATE 1071 Specified key was too long; max key length is 1000 bytes`**: that MySQL defaults to MyISAM. The code now asks for InnoDB (`DB_ENGINE`, release after `v1`). If a migration died half way, drop what it made and start again: `php artisan db:wipe --force` (only on a database that holds nothing yet), then `php artisan migrate --force --seed`.
* The Terminal's `php` is 7.4: use `alias php=/opt/alt/php83/usr/bin/php` in the Terminal window, and the full path `/opt/alt/php83/usr/bin/php` in cron lines and DeployDesk post-deploy commands. `curl` and `exif` are not loaded in that PHP: ask the host to install `ea-php83-php-curl` and `ea-php83-php-exif` (needed for push notifications and picture uploads).
* The admin panel is **not** a site of its own: it is deployed into `backend/public/admin-assets` and opens at `https://<api domain>/admin`. A copy at the root of another sub-domain does not work (the build looks for `/admin-assets/...`).
* The storefront needs `NUXT_PUBLIC_API_BASE=https://<api domain>/api/v1/store` in the host's build settings, otherwise it is built with the local default `http://127.0.0.1:8002/...`; and that storefront address must be in the server's `FRONTEND_URLS`.
* **"Refusing to seed a weak developer password in production"** although `.env` has a long one: `php artisan config:cache` had been run before the seeder, and the old code read `.env` with the env helper (defaults only after caching). Fixed in the code (release after `v2`); until you have it, run `php artisan config:clear`, seed, then `php artisan config:cache` again. Always run `config:cache` **last**, and again after every change of `.env`.

### H. A new install looks like the default shop (blue, "Store"): give it the client's look
The theme, the contact details, the currency and the delivery fee are **settings in the database**, not in the code; a new server has a new database with neutral defaults (blue `#2563EB`, Inter, no logo, no phone), and the storefront and the admin panel both take their colours from it. Nothing is broken: write the settings once.

* **By hand**: sign in to `https://<api domain>/admin` as the developer → Settings → Theme, Branding (logo, favicon), General, Currency & Tax, Delivery, Banners.
* **Or with one command** for the plain values (colours, fonts, contact, currency, delivery), in the cPanel Terminal, one line (it uses `Settings::set()`: unknown keys are ignored, values are checked, the cache is cleared):
```
cd /home/rivoxate/jhkstore/backend
/opt/alt/php83/usr/bin/php artisan tinker --execute='app(App\Domain\Support\Settings::class)->set(["theme_primary"=>"#3BBFCC","theme_primary_contrast"=>"#FFFFFF","theme_accent"=>"#3BBFCC","theme_bg"=>"#F5FBFC","theme_surface"=>"#FFFFFF","theme_text"=>"#16242A","theme_muted"=>"#4F6469","theme_border"=>"#D9EDF0","theme_font_heading"=>"Poppins","theme_font_body"=>"Poppins","theme_radius"=>"16px","theme_shadow"=>"soft","store_tagline"=>"Play. Learn. Grow.","store_email"=>"jhkconceptltd@gmail.com","store_phone"=>"+230 5260 4550","whatsapp_number"=>"+230 5260 4550","currency_code"=>"MUR","currency_symbol"=>"RS","currency_decimals"=>2,"delivery_method"=>"flat","delivery_fee"=>150]); echo "settings written";'
```
* **Pictures cannot be written like this** (logo, favicon, banners, product pictures are uploads): do them in the admin panel (the host must have the PHP `exif` extension first).
* **Then rebuild the storefront** (Cloudflare → Deployments → retry the build): the colours and texts the search engines read are baked into the pages at build time. The admin panel takes the new colour at its next page load.
* After every deploy, and after every change of `.env`: `php artisan config:cache` (a cached configuration from an older release can lack a new setting).

### I. Push notifications on the live server (2026-11-19)
The admin panel says **"Push keys (VAPID): Missing"** until the server has its own keys. In the cPanel Terminal: `cd /home/rivoxate/jhkstore/backend`, then `/opt/alt/php83/usr/bin/php artisan shop:vapid` prints three lines. Put them at the end of `.env` (File Manager), with `VAPID_SUBJECT=mailto:<the shop's e-mail>`, run `php artisan config:cache`, then in the panel: Settings → Notifications → **Admin push notifications** on → **Save changes** (nothing is saved before that button) → press the **bell** and **Allow** in the browser → **Send test notification**. Keep the private key secret. Changing the keys later makes every admin press the bell again.
* **Needs the cron jobs** (cPanel → Cron Jobs, both "once per minute", with the full PHP path): `cd /home/rivoxate/jhkstore/backend && /opt/alt/php83/usr/bin/php artisan schedule:run >> /dev/null 2>&1` and the same with `artisan queue:work --stop-when-empty --max-time=55`. Without them the panel says "Queue worker: no activity seen yet". Both exit by themselves when there is nothing to do (about one second a minute).
* **Without push the alert comes late**: the panel only polls the server every 5 seconds, and a browser slows a tab that is in the background to about once a minute, so a new order may ring 30 to 60 seconds late. A push message reaches the device at once, even with the tab closed.
* Push also needs the PHP **curl** extension for PHP 8.3 (on this host the website and the command line must both use CloudLinux `alt-php83`, see LIVE_STATE.md section 2); the test notification shows the error if it is missing.

### J. The "Rebuild website now" button is grey: it needs a hook
The button stays off while **Rebuild hook: Not set**. On Cloudflare: the Worker → Settings → Build → **Deploy Hooks** → create one for the branch `main` and copy its address. In the admin panel, signed in as the **developer** (the hook address needs `settings.advanced`): Settings → **Website** → paste it as the rebuild hook address → Save, then press the button once. The storefront does **not** need a rebuild for a new product, a price, stock, a category or the wholesale list (they are read when the page opens); the rebuild is only for what Google reads, and for a change of the theme, logo or texts in the baked pages (see GO_LIVE.md, "How often must the storefront be rebuilt?").


### K. Remove the old copy of the admin panel (sub-domain `admin.jhkconcept.com`)
The panel is deployed into `backend/public/admin-assets` and opens at `https://api.jhkconcept.com/admin`. A copy of the build at the root of the sub-domain `admin.jhkconcept.com` can never work (the build looks for `/admin-assets/...` and for the API on its own address). After checking that the real panel works: (1) in DeployDesk remove any target that points at `/home/rivoxate/admin.jhkconcept.com`; (2) cPanel, Domains: remove the sub-domain; (3) File Manager: delete its folder. (If a shorter address is wanted: give that sub-domain the document root `jhkstore/backend/public`, add it to `SANCTUM_STATEFUL_DOMAINS`, run `config:cache` and AutoSSL.)
