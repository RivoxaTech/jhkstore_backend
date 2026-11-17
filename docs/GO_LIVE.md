# Going live

What goes where, what to leave out, how to arrange the repositories, and the checklist for the day. The longer how-to for each step is in [NEW_CLIENT.md](NEW_CLIENT.md).

## 1. What runs where

| Part | Repository | Where it lives | What is deployed |
|---|---|---|---|
| Storefront (customers) | [jhkstore_frontend](https://github.com/RivoxaTech/jhkstore_frontend) | Cloudflare Pages | Cloudflare builds it from the repository: **root directory empty**, build command `npm run generate`, output `.output/public`. Environment: `NUXT_PUBLIC_API_BASE`, `NUXT_PUBLIC_SITE_URL`, `NODE_VERSION=24`. See [STOREFRONT.md](STOREFRONT.md). |
| API + admin panel | [jhkstore_backend](https://github.com/RivoxaTech/jhkstore_backend) (the repository root is the Laravel app) | cPanel (PKWebHost) or a VPS | The app with `vendor/` (made with `composer install --no-dev --optimize-autoloader`, by CI or on your computer) and the built panel in `public/admin-assets` (made from the admin repository, see below), in a folder on the server such as `/home/USER/jhkstore/backend`. The domain's document root points at its `public/` folder. |
| Admin panel source | [jhkstore_admin-frontend](https://github.com/RivoxaTech/jhkstore_admin-frontend) | nowhere | Only its **build output** goes to the server (inside `backend/public/admin-assets`). The source stays in its repository. |

## 2. What must NOT be uploaded to the server

The backend repository (its whole root) goes to the server, into a folder outside the web root. Leave out:

| Leave out | Why |
|---|---|
| `.git`, every `node_modules` | Not needed to run, large |
| `docs/`, `scripts/` | Guides and our test tools: nothing in the running shop reads them |
| `tests/`, `phpunit.xml`, `.phpunit.result.cache` | Tests |
| `.env` of your computer | Write a **new** production `.env` on the server (see section 6) |
| `storage/app/public/*`, `storage/app/private/*`, `storage/logs/*` | Your test pictures, test payment screenshots and logs. A live shop starts empty. |
| `serve-fast.cmd` | A Windows helper for your computer |
| `database/seeders/DemoSeeder.php` | Sample toys. Never run it on a live shop (the file may stay; do not run it). |

The other two repositories are never uploaded: the storefront is built by Cloudflare, the admin panel is built on your computer and only its output is uploaded.

## 3. The tools folders

* **`backend/scripts/`**: `flow-check.mjs` and `adversarial.mjs` (checks that try the whole shop over real HTTP) and `flow-db.php` (used by them and by the panel's browser test). Never deployed.
* **`admin-frontend/tools/help-videos/`** (admin repository): records the Help videos again when the panel looks different (see [HELP_AND_APP.md](HELP_AND_APP.md)).
* **`frontend/tests/`** (storefront repository): the browser tests, the speed tests and the journey (a customer and the shop owner in one browser session).
* `frontend/scripts/serve-static.mjs` serves the built storefront for `npm run preview`.
* The old `design/` folder (reference pictures) was deleted; it is still in the git history. The pictures a shop shows (logo, banners, favicon) are uploaded in the admin panel.

## 4. Three repositories

**Decision taken: three repositories, one per part** (they were one repository until 2026-11-17):

* [jhkstore_backend](https://github.com/RivoxaTech/jhkstore_backend): the Laravel API at the root of the repository (it serves the admin panel's build), the guides in `docs/`, `scripts/`, `CHANGELOG.md` and the project `README.md`.
* [jhkstore_admin-frontend](https://github.com/RivoxaTech/jhkstore_admin-frontend): the admin panel and the Help video maker, at the root of the repository.
* [jhkstore_frontend](https://github.com/RivoxaTech/jhkstore_frontend): the storefront, at the root of the repository (so Cloudflare Pages has no root directory to set).

What the split means in daily work:

* **Clone them side by side**, in folders named `backend`, `admin-frontend` and `frontend` (see the `README.md`). The admin build (`npm run build`) writes into `../backend/public/admin-assets` (or `ADMIN_BUILD_OUT`), `flow-ui` and the journey use the backend next to them, the backend tests look at `../admin-frontend` only when it is there.
* **The panel and the API must match**: the panel is built into the backend and talks to the API on the same address. After a change in either, check that the other still works (`backend/scripts/flow-check.mjs`, `admin-frontend` `npm run flow-ui`), and **deploy them together**: rebuild the panel, upload `backend/public/admin-assets` with the backend.
* **A change that touches two parts is two commits in two repositories** (an API change and the storefront or panel change that uses it). Deploy the backend first, then the storefront.
* **A new client** is a copy of each of the three repositories (a "use this as a template" click for each) and their own environment values.
* **History**: the two new repositories kept the history of their folders (`git subtree split`, the storefront 23 and the panel 63 commits). The backend repository kept everything, including the old copies of the other two folders in its past commits.

## 5. The GitHub repositories

* `origin` of the backend repository = `https://github.com/RivoxaTech/jhkstore_backend.git`; the storefront is `https://github.com/RivoxaTech/jhkstore_frontend.git`, the panel `https://github.com/RivoxaTech/jhkstore_admin-frontend.git`. The old `startxlaravel02` repository is not used any more.
* The history was checked before it was sent: it contains no passwords or keys (only the `.env.example` files).
* Later work: commit on a branch, push the branch, merge on GitHub. **Cloudflare Pages builds the production branch of the storefront repository (`main`) only.**

Deploying the backend and the admin panel with DeployDesk (build on GitHub, deploy to the cPanel server, rollback): [DEPLOYDESK.md](DEPLOYDESK.md).

## 6. The checklist for the day

Backend `.env` on the server:

* `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://api.yourshop.com`, a new `APP_KEY` (`php artisan key:generate`), the database values.
* `SEED_DEVELOPER_PASSWORD` and `SEED_ADMIN_PASSWORD`: at least 12 characters, not the username (production refuses weaker ones). Change both accounts' passwords again from the Profile page after the first sign-in.
* `FRONTEND_URLS` = the Cloudflare address of the storefront. **Do not** put the storefront in `SANCTUM_STATEFUL_DOMAINS` (only the admin address), or every order fails with a 419.
* Mail: set the account in **Settings → E-mail** (or `MAIL_*`), then send a test e-mail. Turn **Send e-mails** on in Notifications.
* Push: `php artisan shop:vapid`, copy the three lines, set `VAPID_SUBJECT=mailto:you@yourshop.com`.

On the server:

* `php artisan migrate --force` then `php artisan db:seed --force` (**not** `DemoSeeder`), `php artisan storage:link`.
* `php artisan config:cache && php artisan route:cache && php artisan event:cache && php artisan view:cache`.
* A queue worker (`php artisan queue:work --tries=3` under a process manager) **or** the cron `* * * * * php artisan queue:work --stop-when-empty --max-time=55`, plus the cron `* * * * * php artisan schedule:run`.
* PHP: `upload_max_filesize` and `post_max_size` above 50 MB (product videos), OPcache on.
* HTTPS everywhere (an `http://` API under an `https://` site is blocked by the browser).
* Back up the database and `storage/app/public` + `storage/app/private` (pictures and payment proofs) every day.

Cloudflare Pages (the `jhkstore_frontend` repository): the two environment variables, the deploy hook pasted in **Settings → Website** (see NEW_CLIENT.md section 6b), then place a test order on the live site, check the e-mail, the push notification and the invoice.

## 7. Speed after launch

If adding to the cart, redirecting or placing an order feels slow on the live site, measure first (the browser's Network tab, and `docs/PERFORMANCE.md`). The usual causes, in the order to check them:

1. **CORS preflight.** The storefront and the API are on different addresses, so a cart, quote or order request is first preceded by an `OPTIONS` request (because of the `X-Cart-Token` / `Idempotency-Key` headers and the JSON body). `max_age` in `backend/config/cors.php` used to be `0`, which stopped the browser from remembering the answer, so every call cost two round trips. **It is now 7200** (`CORS_MAX_AGE` in `.env`, the most Chrome keeps; Firefox keeps up to 24 hours): the browser asks once per address for two hours. After the update run `php artisan config:cache` again.
2. Server distance and PHP set-up: OPcache, `config:cache`, PHP-FPM instead of a slow shared set-up, the database on the same machine.
3. Work done during the request that could wait until after it (the order already sends its e-mails and push after the response).
4. Pictures from the API address: put Cloudflare in front of the API address so `/storage/*` and the public GET calls are cached at the edge.

The numbers before and after the speed session, and how to measure again, are in [PERFORMANCE.md](PERFORMANCE.md) ("The customer side"). [NEXT_CHAT_PROMPTS.md](NEXT_CHAT_PROMPTS.md) keeps the prompt of that session.

### What only the hosting can do (steps for you, not changed in the code)

Do these once on the live set-up, then check each with the browser's Network tab (open the site, add a toy to the cart, press F12):

1. **No redirect in front of the API.** Open `https://api.yourshop.com/api/v1/store/settings` exactly as the storefront has it in `NUXT_PUBLIC_API_BASE`: it must answer `200` at once. A redirect (`http` to `https`, `www`, a trailing slash) costs another round trip and a browser **refuses to follow a redirect on a preflight**, so the call fails. Use the final address in `NUXT_PUBLIC_API_BASE` and have `https` only.
2. **Check the preflight is remembered.** In the Network tab filter `cart`, add a toy twice: the first call shows an `OPTIONS` row, the second one should not. If it does, look at the `OPTIONS` answer's headers for `Access-Control-Max-Age: 7200` (if it is missing, `config:cache` was not run again or a proxy strips it).
3. **Cloudflare in front of the API address** (an orange cloud DNS record for `api.yourshop.com`): *SSL/TLS* mode **Full (strict)**, *Speed → Optimization*: HTTP/2 and HTTP/3 on, Brotli on, *Always Use HTTPS* on. The catalogue answers (`/store/settings`, `/products`, `/categories`, `/banners`) already send `Cache-Control: public, s-maxage=300, stale-while-revalidate=60` and an ETag: Cloudflare honours that for JSON only when a **Cache Rule** says so. Rule 1: URI path starts with `/api/v1/store/` and method is GET and there is no `X-Cart-Token` → *Eligible for cache*, *Edge TTL: use cache-control header if present*, *Browser TTL: respect origin*. Rule 2: URI path starts with `/storage/` → *Eligible for cache*, Edge TTL 1 month (the pictures never change under the same name). Never cache `/api/v1/admin/*` and never a POST (Cloudflare does neither by default). Check a second `GET /products` shows `cf-cache-status: HIT`.
4. **PHP on the server**: OPcache on (`opcache.enable=1`, `opcache.validate_timestamps=0` after you upload, restart PHP when you deploy), `php artisan config:cache route:cache event:cache view:cache`, `composer install --no-dev --optimize-autoloader`, PHP-FPM or LiteSpeed (not plain CGI, which starts PHP for every request and cannot send an answer before the e-mail work is done).
5. **The API server near your customers**: the customers are in Mauritius; a server in Europe adds about 150 to 250 ms to every call, one in the Indian Ocean region or South Africa about 50 to 120 ms. A CDN cannot help a cart call (it is never cached), the distance can.

### How often must the storefront be rebuilt? (Cloudflare Pages builds)

**Not after every edit.** The storefront shows the shop's current data without a rebuild: a page asks the API for the live product, the product lists, the banners and the settings as soon as it opens (checked on 2026-11-17: an edited name and price, and a product created after the build, show within about 0.6 s, by their link, in the shop, on the home page and on the category page). A rebuild only refreshes **what a search engine reads** (the texts, titles and prices in the HTML, the sitemap, the structured data) and the first picture a visitor sees before the live answer arrives (a very short flash of the old text is possible). A rebuild is *not* needed for price, stock, a new product or an order. A change of the **theme, logo or footer** may show only after a rebuild or a reload: look at it once on the live site before you rely on this.

Cloudflare Pages' free plan allows about 500 builds a month (check the current limit in the Cloudflare dashboard), and every automatic rebuild is one. Choose one:

1. **Automatic rebuild off, one rebuild a night (recommended).** Settings → Website: switch *Rebuild the website automatically* off. Add a cron on the server (cPanel → Cron Jobs, once a day at night): `0 3 * * * cd /home/USER/jhkstore/backend && php artisan shop:rebuild >> /dev/null 2>&1`. About 30 builds a month. The owner has the *Rebuild website now* button in **Settings → Website** (it shows the status too; the hook address and secret stay hidden from the owner's account) and presses it after a big change (a new season, many new products) so Google sees it the same day.
2. **Automatic rebuild on with the longest wait.** *Wait after the last change* = 3600 seconds: at most one build an hour, and only in hours with a change. A shop edited every hour of every day would still reach 720 a month, so this is for a calm shop.
3. **Direct upload (no Pages build at all).** Build on your computer or in a GitHub Action and upload the folder with Cloudflare's `wrangler pages deploy`: those uploads are not counted as builds (check this in the current Cloudflare documentation first). It needs a trigger of your own, so use it only if option 1 is not enough.

**How soon does a new product show without a rebuild?** At once when the API answers straight from the server. With a cache in front of the API (the Cloudflare rule of this section) the public lists are kept for up to `STORE_CACHE_S_MAXAGE` seconds (300 by default) plus one minute in the visitor's browser, so a product added now can take up to about 5 to 6 minutes to show on the shop pages (its own page by link shows as soon as the cache of that address expires). If the owner expects it faster, set `STORE_CACHE_S_MAXAGE=60` in the server's `.env` (and `php artisan config:cache`): more reads reach PHP, the delay drops to about 1 to 2 minutes.

Without a rebuild the admin cannot break anything: orders, prices and stock never depended on it.

**The price of "live": a visitor's page now costs one to five API reads** (the product page one, the home page five). They are cacheable for everybody (`s-maxage=300`): put Cloudflare (the free proxy, no build limit, not Pages) in front of the API address with the cache rule of this section, otherwise every page view runs PHP on the host. On a host with a limit on processes or CPU this matters more than the builds do.
