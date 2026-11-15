# Going live

What goes where, what to leave out, how to arrange the repositories, and the checklist for the day. The longer how-to for each step is in [NEW_CLIENT.md](NEW_CLIENT.md).

## 1. What runs where

| Part | Folder | Where it lives | What is deployed |
|---|---|---|---|
| Storefront (customers) | `frontend/` | Cloudflare Pages | Cloudflare builds it: root directory `frontend`, build command `npm run generate`, output `.output/public`. Environment: `NUXT_PUBLIC_API_BASE`, `NUXT_PUBLIC_SITE_URL`. See [STOREFRONT.md](STOREFRONT.md). |
| API + admin panel | `backend/` (the panel is built into it) | cPanel or a VPS | `backend/` with `vendor/` (made with `composer install --no-dev --optimize-autoloader`) and the built panel in `backend/public/admin-assets` (made on your computer with `cd admin-frontend && npm run build`). The domain's document root points at `backend/public`. |
| Admin panel source | `admin-frontend/` | nowhere | Only its build output goes to the server. The source stays in the repository. |

## 2. What must NOT be uploaded to the server

| Leave out | Why |
|---|---|
| `.git`, every `node_modules` | Not needed to run, large |
| `admin-frontend/` (source), `frontend/` | The server only needs the built panel; the storefront is built by Cloudflare |
| `design/`, `scripts/`, `docs/` | Reference pictures, test tools and guides: nothing in the running shop reads them |
| `backend/tests`, `backend/phpunit.xml`, `backend/.phpunit.result.cache` | Tests |
| `backend/.env` of your computer | Write a **new** production `.env` on the server (see section 6) |
| `backend/storage/app/public/*`, `backend/storage/app/private/*`, `backend/storage/logs/*` | Your test pictures, test payment screenshots and logs. A live shop starts empty. |
| `backend/serve-fast.cmd` | A Windows helper for your computer |
| `frontend/.nuxt`, `frontend/.output`, `frontend/dist` | Build leftovers (git ignores them; delete the old `frontend/dist` folder) |
| `backend/database/seeders/DemoSeeder.php` | Sample toys. Never run it on a live shop (the file may stay; do not run it). |

## 3. The `design/` and `scripts/` folders

* **`design/`** (reference pictures and old logos): nothing in the code reads it. **Delete it from the live repository.** The pictures a shop shows (logo, banners, favicon) are uploaded in the admin panel.
* **`scripts/`**: the shop does not need it to run, so it is never deployed. It holds our own tools:
  * `flow-check.mjs` and `adversarial.mjs`: checks that try the whole shop over real HTTP,
  * `flow-db.php`: used by `admin-frontend/e2e/flow-ui.mjs` (the browser test of the panel),
  * `help-videos/`: records the Help videos again when the panel looks different (see [HELP_AND_APP.md](HELP_AND_APP.md)).

  Delete it only if you accept losing those automatic checks and the video maker. A good middle way: keep it in the development repository and leave it out of what you hand to a client.
* `docs/BUILD_PLAN_ONE_GO.md` and `docs/BUILD_PROGRESS.md` are internal build notes and can be deleted. Keep the other guides.
* `frontend/scripts/serve-static.mjs` is a different file (it serves the built storefront for `npm run preview`): leave it.

## 4. One repository or three?

**Keep one repository** (a "monorepo": `backend/`, `admin-frontend/`, `frontend/`, `docs/`), one per client.

* The panel is built **into** the backend and talks to the API on the same address, so they always have to match: one change often touches both. Three repositories would make every such change three commits and three releases that can drift apart.
* The tests (`flow-ui`, the journey, the adversarial run) use the three parts together.
* A new client is one "use this repository as a template" click (see NEW_CLIENT.md).
* Cloudflare Pages can build one folder of a repository (root directory `frontend`), so the storefront still deploys on its own. Set "build watch paths" to `frontend/*` if you do not want a rebuild after every backend commit.

Two repositories (backend + admin together, storefront alone) is the only split that makes sense, and only if a different person or company works on the storefront and must not see the backend. **Do not split the admin panel from the backend.**

### If you use two repositories (the plan)

* **Backend repository** = `backend/` + `admin-frontend/` (the panel is built into the backend: they stay together) + `docs/` + `scripts/` + `README.md` + `CHANGELOG.md`. It is deployed to cPanel / the VPS.
* **Storefront repository** = the content of `frontend/` **at the root of the repository** (so `package.json` and `nuxt.config.ts` are in the top folder). Cloudflare Pages builds it: **root directory empty**, build command `npm run generate`, output `.output/public`.
* What the split touches, to fix when you do it:
  * `frontend/tests/journey.mjs` reads `backend/storage/logs/laravel.log` and uses the backend scripts through a sibling folder: move it to the backend repository (for example `scripts/journey.mjs`) or make the paths configurable. `frontend/tests/e2e.mjs` and `perf.mjs` only need the API address and stay with the storefront.
  * The links between the guides (the storefront guide lives in the backend repository's `docs/`; give the storefront repository a short `README.md` with its environment variables and the Cloudflare steps).
  * The deploy hook, `FRONTEND_URLS` and `NUXT_PUBLIC_API_BASE` do not change.
* The history can be kept for both (checked: the storefront alone is 21 commits):

  ```
  # storefront repository: only the history of frontend/, with its files at the root
  git subtree split --prefix=frontend -b storefront-only lean-store
  git push STOREFRONT_URL storefront-only:main

  # backend repository: everything except frontend/
  git checkout -b backend-only lean-store
  git rm -r frontend design docs/BUILD_PLAN_ONE_GO.md docs/BUILD_PROGRESS.md
  git commit -m "Storefront moved to its own repository"
  git push BACKEND_URL backend-only:main
  ```

  (`git rm -r frontend` keeps the old storefront files in the backend repository's past commits. For a repository without them, copy the folders into a new folder, `git init`, commit and push instead.)

## 5. Moving to a new GitHub repository

* The project is connected to `origin` = `https://github.com/startxlaravel02/DentalKart_backend.git`. The work is on the branch `lean-store` (109 commits ahead of `main`); `main` is the older version.
* Nothing has ever been pushed from the development sessions.
* To attach ONE new, empty repository and send everything, with the history (for two repositories see the plan above):

  ```
  git remote set-url origin https://github.com/NEW-OWNER/NEW-REPO.git
  git push -u origin lean-store:main
  ```
* For a repository **without** the history (a clean start): delete `design/` (and anything else you do not want to ship), then `git checkout --orphan clean && git add -A && git commit -m "Initial version"` and push that branch as `main`.
* The history was checked: it contains no passwords or keys (only the three `.env.example` files). It does contain the pictures of `design/`, which is one more reason to start clean if you delete that folder.

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

Cloudflare Pages: the two environment variables, the deploy hook pasted in **Settings → Website** (see NEW_CLIENT.md section 6b), then place a test order on the live site, check the e-mail, the push notification and the invoice.

## 7. Speed after launch

If adding to the cart, redirecting or placing an order feels slow on the live site, measure first (the browser's Network tab, and `docs/PERFORMANCE.md`). The usual causes, in the order to check them:

1. **CORS preflight.** The storefront and the API are on different addresses, so every cart, quote and order request is first preceded by an `OPTIONS` request. `backend/config/cors.php` has `'max_age' => 0`, which stops the browser from remembering the answer; set it to `7200`.
2. Server distance and PHP set-up: OPcache, `config:cache`, PHP-FPM instead of a slow shared set-up, the database on the same machine.
3. Work done during the request that could wait until after it.
4. Pictures from the API address: put Cloudflare in front of the API address so `/storage/*` and the public GET calls are cached at the edge.

[NEXT_CHAT_PROMPTS.md](NEXT_CHAT_PROMPTS.md) has a ready prompt for a focused speed session.
