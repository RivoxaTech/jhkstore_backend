# The live set-up: where everything is, what was done, what is open (state of 2026-11-19)

This page is the **handover of the live deployment**. A new person (or a new AI chat) reads it after [HANDOVER.md](HANDOVER.md) and knows what runs where today, what is already done, which traps were met, and what is still open. No password, key or token is written here (they are in `.env` on the server, in the password manager of the owner, and in the dashboards).

## 1. The three repositories and the computer layout
| Repository | What | On the computer |
|---|---|---|
| `RivoxaTech/jhkstore_backend` | Laravel 13 API + the guides (`docs/`) + check scripts (`scripts/`); the **repository root is the Laravel app** | `Desktop/jhkstore/backend` |
| `RivoxaTech/jhkstore_admin-frontend` | Vue 3 admin panel (installable app) + `tools/help-videos` | `Desktop/jhkstore/admin-frontend` |
| `RivoxaTech/jhkstore_frontend` | Nuxt 4 **static** storefront + `wrangler.jsonc` / `worker/index.js` (Cloudflare Worker) | `Desktop/jhkstore/frontend` |

`Desktop/jhkstore/` itself is an ordinary folder (not a repository). `.git-old-umbrella-backup` there is the old single repository (safe to delete). A push to `main` of any of them starts a build (GitHub Actions for the backend and the panel, Cloudflare for the storefront). **Never switch branches in a repository whose folder holds another repository's files** (it once deleted tracked files; they were restored).

## 2. What runs where
| Part | Where | Address |
|---|---|---|
| API (Laravel) and the admin panel (`/admin`) | PKWebHost **cPanel** (CloudLinux + LiteSpeed), account `rivoxate`, folder `/home/rivoxate/jhkstore/backend`, document root `.../backend/public` | `https://api.jhkconcept.com` (panel: `/admin`) |
| Database | cPanel MySQL (default engine MyISAM: the code forces **InnoDB**), database and user `rivoxate_jhkstore` | password only in `.env` |
| Storefront (customers) | **Cloudflare Worker** with static assets (`wrangler.jsonc`) | `https://jhkstore-frontend.contact-rivoxa.workers.dev` for now; `jhkconcept.com` / `www` still serve the cPanel default page |
| Domain | registrar **Hostinger**; nameservers `ns1.pkwebhost.com` / `ns2.pkwebhost.com` (since 2026-11-18; the zone lives in cPanel, **server IP `64.20.55.190`**) | `jhkconcept.com` |
| Deploys | **DeployDesk** (https://deploydesk.siteffects.com/, a colleague's tool): GitHub Action builds, DeployDesk uploads to the folder | see [DEPLOYDESK.md](DEPLOYDESK.md) |

* **PHP**: the Terminal's `php` is 7.4. Always `/opt/cpanel/ea-php83/root/usr/bin/php` (in the Terminal: `alias php=...`; in cron lines; in DeployDesk commands). `api.jhkconcept.com` runs PHP 8.3 (MultiPHP Manager). The extensions **`curl` and `exif` are NOT installed** for PHP 8.3: a ticket to the host (`ea-php83-php-curl`, `ea-php83-php-exif`) is the open request; picture uploads and push need them.
* **Process limit**: 100 (about 22 used, mostly the Next.js site `Rivoxa-Tech` of the same account; a second project of the owner, "rs home", is to be added). Node apps (SSR) cost many processes on this host; PHP requests are short; static files cost none.
* **Cron jobs** (cPanel → Cron Jobs, once per minute, full PHP path, see DEPLOYDESK.md section I): `schedule:run` and `queue:work --stop-when-empty --max-time=55`. **To verify that they exist**: the Notifications card must say "Queue worker: running".

## 3. What was done on the live set-up (in order)
1. Repositories split in three, history kept, dead code and the Laravel front-end scaffold removed, docs moved into the backend repository.
2. DeployDesk: GitHub connected, backend and panel imported (first import failed on token rights and left empty duplicate projects: **use the ones with a `v1` release / the ids in the workflow files**), targets set, first deploy (8,412 files in 13 s).
3. Server: `.env` (DeployDesk makes it with `APP_KEY`), database, **`php artisan migrate --force --seed`** (after the InnoDB fix and `config:clear` for the seeder), `storage:link`, `config:cache`.
4. Settings of the client written with one `Settings::set()` command (theme `#3BBFCC`, Poppins, contact, MUR, delivery 150 flat): DEPLOYDESK.md section H. Logo, favicon, banners, categories, products and the MCB bank account were entered in the admin panel.
5. Panel deployed into `backend/public/admin-assets`; it opens at `https://api.jhkconcept.com/admin`. (`admin.jhkconcept.com` holds an old, non-working copy: to remove, section K of DEPLOYDESK.md.)
6. Storefront on a Cloudflare Worker with `NUXT_PUBLIC_API_BASE=https://api.jhkconcept.com/api/v1/store`, `NUXT_PUBLIC_SITE_URL`, `NODE_VERSION=24`.

## 4. Traps met, and the cure (so nobody meets them twice)
| Trap | Cure |
|---|---|
| `1045 Access denied` | the password in `.env` differs from cPanel's; letters+digits, `DB_PASSWORD="..."`, test with `mysql -u USER -p DB -e "select 1"` |
| `1071 key too long (1000 bytes)` | MyISAM default; the code asks for InnoDB (`DB_ENGINE`); a half-made migration: `php artisan db:wipe --force` **once, on an empty database only** |
| Seeder says "weak password" with a long one | `config:cache` had been run: code read `.env` with `env()` outside `config/`; fixed (`config('shop.seed.*')`), test `NoEnvOutsideConfigTest`. `config:cache` goes **last** and again after every `.env` change |
| Local site shows the neutral blue "Store" | the local API (port 8002) is not running; start `serve-fast.cmd` |
| Panel/storefront look blue and empty on the live server | the live database is new: write the settings (section H) |
| A direct page load never showed an edit | fixed in `useFastData` (a refresh during hydration was answered from the baked data) |
| `/order/...` and new products 404 after reload on Cloudflare | a Worker ignores the `_redirects` rewrite; `worker/index.js` serves the shell (`200.html`) with the visitor's address |
| Wholesale list and categories empty | they were baked at build time; now read when the page is shown |
| Orders badge 2 for 1 order | an order with a proof to check was counted twice; the badge is the number of orders |
| "Rebuild website now" is grey | no hook set (Cloudflare Deploy Hook, DEPLOYDESK.md section J); needs the developer account |
| No push, sound 30 s late | no VAPID keys / switch not saved / no cron / the polling is slowed in a background tab (DEPLOYDESK.md section I) |
| `backend/public/storage` 404 locally | the junction pointed at the old `DentalKart_backend` folder; `rmdir` it and `php artisan storage:link` |

## 5. Open items (the owner's list)
1. **Video upload does not work** (a 6.1 MB file stops at 17 %, then the panel says "could not be uploaded ... raise upload_max_filesize and post_max_size"). That message is Laravel's `video.uploaded` rule: **PHP itself refused the file**, so the limit is the host's `upload_max_filesize` (cPanel default 2 MB). Cure: cPanel, MultiPHP INI Editor, choose `api.jhkconcept.com`, set `upload_max_filesize` 64M, `post_max_size` 64M, `max_execution_time` 300, `memory_limit` 256M, Apply; then retry. **Update (same day):** the web PHP of `api.jhkconcept.com` was measured with a temporary file: PHP 8.3.36, `upload_max_filesize` 20M, `post_max_size` 80M, `max_input_time` and `max_execution_time` 300, `/tmp` writable. So the size limit is NOT the cause. The upload message now says PHP's real reason (interrupted, no temp folder, cannot write, larger than post_max_size) and `Video upload refused` with the PHP error code is written to `storage/logs/laravel.log`. Read the new message and that log line first. Suspect: the connection is cut (PHP error 3, "interrupted") by the host or the slow line; if so the cure is a chunked upload (small parts, each retried), not a bigger limit. To honour "50 MB" raise `upload_max_filesize` to 64M. (The old list of what to collect:) the red message under the upload (or the response in DevTools → Network), the web PHP limits of `api.jhkconcept.com` (`upload_max_filesize`, `post_max_size`, `max_execution_time`, `memory_limit`, MultiPHP INI Editor) and the last lines of `storage/logs/laravel.log`. A size limit of the host is the first suspect.
2. **Push notification** does not arrive. The status card says "Still waiting after 25 seconds: no queue worker took the job" = the cron line for `queue:work --stop-when-empty --max-time=55` is missing or wrong (full PHP path, DEPLOYDESK.md section I). `curl` is now installed.
3. **Cloudflare build settings** to confirm: build command `npm run generate`, deploy command `npx wrangler deploy`, root directory empty; then check an order page after a reload.
4. **Rebuild hook** (Cloudflare Deploy Hook) into Settings → Website (developer).
5. ~~Host ticket: PHP `curl` and `exif`~~ DONE 2026-11-18: the host enabled `curl`, `exif` and `intl` for ea-php83 (verify with `/opt/cpanel/ea-php83/root/usr/bin/php -m | grep -Ei "curl|exif|intl"`).
6. Deploy the newest backend and panel releases from DeployDesk (badge, address block, seeder, InnoDB).
7. Real **mail account** (Settings → E-mail → test) and the `Send e-mails` switch; the owner's alert address.
8. Remove the sub-domain `admin.jhkconcept.com` and its folder.
9. Domain: point `jhkconcept.com` / `www` to the storefront (depends on the decision in GO_LIVE.md section 8), HTTPS (AutoSSL).
10. Load test (100 to 200 users) and the processes number in cPanel → Resource Usage; daily backup of the database and `storage/app/public` + `storage/app/private`.
11. Not run since the split: the full `flow-ui` and `flow-check` (need the bank settings and a clean scratch database); the installable app on a real iPhone; real e-mail delivery.
12. Delete `.git-old-umbrella-backup` (and the two `.bak` files) in `Desktop/jhkstore` when sure.

## 6. How to work with the owner
Short steps, "where to go and what to put", in the owner's language (Roman Urdu or English, follow the last message). Never ask for or store a password, key or token; screenshots with secrets are to be avoided. Push only after the tests are green and say what was pushed; a push to `main` starts a build. Never run `db:wipe`, `migrate:fresh`, `DemoSeeder` on the live server. The owner's local servers (API 8002, `nuxt dev` 3000, Vite 5190, database `jhkstore_db`) are the owner's: do not stop them; use the scratch database `jhkstore_shots` and other ports for tests.
