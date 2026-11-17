# Prompts for the next chat

Three ready prompts, to paste into a new AI chat. **Start prompt** is the short one you type: it sends the new session to read these docs. Prompt 1 gives it everything that has been built and the rules of the work. Prompt 2 is for a focused session on the speed of the customer side.

---

## Start prompt (type this in the new chat)

```
This is the JHK Store project, in THREE git repositories cloned side by side in one folder (jhkstore/backend, jhkstore/admin-frontend, jhkstore/frontend). We are going live. Your memory of earlier sessions is empty: the docs (backend/docs/) are the source of truth.

Before you do anything, read, in this order: backend/docs/NEXT_CHAT_PROMPTS.md (the section "Prompt 1: the project so far" is your briefing and your rules: keep them), backend/docs/GO_LIVE.md, backend/docs/HANDOVER.md, the top of backend/CHANGELOG.md and the last sections of backend/docs/QA_REPORT.md. Then tell me in a few lines what you understood and what you will do first.

The repositories: backend = https://github.com/RivoxaTech/jhkstore_backend.git (the repository root is the Laravel app; the guides are in docs/, the check scripts in scripts/; on the computer it is the folder jhkstore/backend), admin panel = https://github.com/RivoxaTech/jhkstore_admin-frontend.git, storefront = https://github.com/RivoxaTech/jhkstore_frontend.git. Check each with git remote -v and git log. Nothing may be lost: do not rewrite history, do not force-push, do not delete branches. From now on: work on a branch, commit, and push only when I say "push".

What I want, in this order (ask me before anything destructive):
1. Check that the three repositories are in order (remotes, branches, a fresh clone of each builds and the tests pass).
2. Walk me through the go-live checklist of docs/GO_LIVE.md (server, .env, Cloudflare Pages, mail, queue, backups).
3. Then do the speed session: "Prompt 2" in docs/NEXT_CHAT_PROMPTS.md.
```


---

## Prompt 1: the project so far

You are taking over a finished-but-not-yet-live online toy shop that I (the owner, a developer at an agency) built with an AI assistant over many sessions. Read the repository before you change anything, then continue the work I ask for at the end. Reply in plain language.

### The repository

Three repositories, one set per client, cloned side by side: `backend/` (Laravel 13, PHP 8.3, MySQL 8.4; repository jhkstore_backend; it also holds `backend/docs/` (the guides: start with `HANDOVER.md`, then `NEW_CLIENT.md`, `GO_LIVE.md`, `ACCESS_CONTROL.md`, `SETTINGS.md`, `STOREFRONT.md`, `HELP_AND_APP.md`, `PERFORMANCE.md`, `QA_REPORT.md`), `backend/CHANGELOG.md` and `backend/scripts/` (flow-check, adversarial, flow-db)), `admin-frontend/` (repository jhkstore_admin-frontend: Vue 3 + Vite + Pinia admin panel, built into `backend/public/admin-assets` and served by Laravel at `/admin`; also `tools/help-videos`, the Help video maker), `frontend/` (repository jhkstore_frontend: Nuxt 4 static storefront, `nuxt generate`, Cloudflare Pages with an empty root directory). (They were one repository until 2026-11-17; the old `design/` folder of reference pictures was deleted and is still in the git history.)

The folder is called `jhkstore` (it was `DentalKart_backend`). Git: the three remotes are named above; the old `startxlaravel02` remote was removed. Do not rewrite history, do not force-push, do not delete branches; work on a branch and push only when I say so. Commit locally, commit messages end with the trailer `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.

### The shop in one paragraph

Customers browse a static storefront (home, shop with sort and filters, categories, product page with options, bulk prices, gallery and video, cart drawer, one-page checkout with cash on delivery or bank transfer plus a payment screenshot, order tracking by order number and phone, a wholesale enquiry form, About / Shipping / Returns / Terms / Privacy pages written for Mauritius). The price block shows the struck-through old price first, then the price. The shop owner uses the admin panel; the developer (agency) has a second account with more power. There is no customer login.

### Accounts and rules of access

Two accounts and two roles only (`developer`, `admin`; local seed passwords `developer`/`developer` and `admin`/`admin`; production needs 12+ characters). The developer manages the catalogue, theme and technical settings and never sees orders by default; the admin (the shop owner) sees orders, customers, wholesale, discounts, coupons and the General/Store/Delivery/Payments/Notifications settings. **Access Control** (developer only) switches permissions for the admin. A technical Settings tab (Footer, Currency & Tax, SEO, Branding, Banners, E-mail, ...) opens with its own switch; a single technical field inside a client tab (store name, mail sender, hook address) also needs `settings.advanced`. If an update adds a permission the database lacks, `AdminAccess::allows()` adds it on the spot.

### What is built (by area)

* **Orders:** pending, confirmed, packed, shipped, delivered, cancelled, rejected. After confirmation any later status can be chosen from a menu. Invoice and packing slip print views (logo left, phone and e-mail right; the slip says what to collect), WhatsApp button, internal notes, status timeline, stock reserved and released, delivery fee "to be confirmed" flow. Totals: Sales = ordered, Revenue = money received (bank transfer verified and order confirmed, cash on delivery when delivered).
* **Payments:** cash on delivery, bank transfer with proof upload (private storage), the owner verifies or rejects the proof; bank accounts list (IBAN optional, shown only when filled).
* **Catalogue:** products with options (own SKU auto-made, price, old price, stock, alert level), bulk price tiers, pictures and uploaded videos, tags, categories and sub-categories, SEO, related products, drag-to-arrange.
* **Promotions and delivery:** automatic discounts and coupons (scopes: store, products, categories, sub-categories), delivery methods flat / free / free above / by zone / owner confirms.
* **Customers and wholesale:** customers list with copy icons, abandoned carts (captured from checkout), wholesale requests with an optional BRN, CSV exports.
* **Notifications:** e-mails to customers and the owner, admin push (VAPID) with a chime, a queue worker (and a fallback that sends right after the response when no worker runs), website rebuild hook to Cloudflare. The mail account can be set in the panel (**Settings → E-mail**: server, port, encryption, user, encrypted password, sender, quick setup for Gmail/Outlook/Brevo/Mailgun/cPanel, test e-mail); off = the server's `MAIL_*`.
* **Admin panel:** dashboard cards (Sales, Revenue, Orders, Wholesale), light and dark theme, phone layouts (cards, icon buttons), its own drop-down list and calendar, thin themed scroll bars, a profile page with password change, real-time badges, **Help** (a video-site style grid of seven silent how-to videos with step times; `scripts/help-videos` records them again), and it is an **installable PWA** (manifest made by Laravel, offline page, install button).
* **Speed work already done:** the admin has an API client with memory and stale-while-revalidate, hover prefetch, one badges poll; the backend has indexes, a persistent connection locally, `Server-Timing` headers; `docs/PERFORMANCE.md` has the numbers.

### The rounds of work, short

Phases A to C (payments, promotions, delivery, compact product form, settings tiers, push, dashboard), the Nuxt 4 upgrade, round E (honeypot, queue worker), round F (speed and an adversarial end-to-end test: found no-worker mail loss, abandoned carts never saved, a broken static build), round G/H (sidebar, profile, themes, phone layouts, dark theme root cause, own pop-ups), round I (BRN, copy icons, optional IBAN), round J (Help videos, PWA, favicons, Mauritius policy pages), round K (mail account from the panel, access fix, Help like a video site). `CHANGELOG.md` and `docs/QA_REPORT.md` list every change and every test result.

### How to test (nothing is trusted until these pass)

* `cd backend && php artisan test` (PHPUnit on database `jhkstore_test`; about 607 tests, 1 skipped).
* Browser suites (headless Chrome, scratch database **`jhkstore_shots`**, never the real dev database): `admin-frontend/e2e/flow-ui.mjs` (the panel, about 242 checks), `frontend/tests/e2e.mjs` (storefront, 151), `backend/scripts/adversarial.mjs` (165), `frontend/tests/journey.mjs` (a customer and the owner, 100), `backend/scripts/flow-check.mjs` (real HTTP, about 1.5 hours, run alone). Build the panel first (`cd admin-frontend && npm run build`) and generate the storefront for the e2e/journey.
* Known and accepted: sorting by price on a category with an automatic discount can look off by a hair (the list sorts by the stored price, the card shows the discounted one); one order-search test can fail once when an id collides with digits of a test phone number (it passes on re-run).

### Rules of work (keep them)

1. Never merge, never push, never touch `main`, never use `--force`, unless I say so in this chat. Commit locally. Check the branch before every commit.
2. The database `jhkstore_db` and the servers on ports 8002 (`php artisan serve`), 3000 (`nuxt dev`), 5190 (Vite) are mine: never wipe, kill or run artisan/tinker against them by accident (an artisan command run from a plain shell hits `jhkstore_db`). Use a scratch database and other ports.
3. Do not hide failures: report test failures with the output, and say what was not tested.
4. Update `CHANGELOG.md`, the docs and `docs/QA_REPORT.md` with every change. No long dashes in customer-facing text; short, human wording.
5. Fix the cause, add a test for it, look at screenshots for visual work.

### What I want now

I am going live. (1) Check the three repositories are in order, (2) walk me through the go-live checklist in `backend/docs/GO_LIVE.md`, (3) then, if I ask, do the speed work of prompt 2 (it was done on 2026-11-16: the numbers are in `backend/docs/PERFORMANCE.md`, "The customer side").

---

## Prompt 2: speed of the customer side

You are working on the shop described in prompt 1 (repository, rules and tests there). **Goal: the storefront should feel instant when a customer adds to the cart, moves between pages (redirects) and places an order.** Right now these three actions take noticeable time. Measure first, then fix the causes with the smallest changes, and prove each one with numbers.

### What is known

* The storefront is a static Nuxt site (`frontend/`, `npm run generate`) and the API is Laravel (`backend/`) on a different address, so **every cart, quote and order call is a cross-origin request from the browser**. On the live set-up the storefront is on Cloudflare Pages and the API on cPanel or a VPS, probably far from some customers.
* **First suspect:** `backend/config/cors.php` has `'max_age' => 0`. The browser then sends an `OPTIONS` preflight before every JSON request with the headers we use (cart token, `Idempotency-Key`), and cannot remember the answer, so each action costs two round trips. Setting `max_age` to 7200 (Chrome's cap) should halve the delay of every cart and checkout call. Verify with the Network tab before and after.
* Already done earlier (do not redo): admin panel speed (see `docs/PERFORMANCE.md`), indexes, `Server-Timing` header (local only), persistent database connection locally, the cart uses an instant drawer with the server call finishing in the background (`frontend/app/components/ProductCard.vue`, `composables/useCart.ts`), the storefront speed script `frontend/tests/perf.mjs`.
* Do not judge speed on `nuxt dev` and `php artisan serve` (single-threaded, boots the framework on every request). Judge on a production-like set-up: built storefront, PHP-FPM or at least OPcache, `php artisan config:cache route:cache event:cache view:cache`, `composer install --no-dev --optimize-autoloader`.

### What to do

1. **Measure** (write the numbers in `docs/PERFORMANCE.md`): for add to cart, the quote and the place-order call, and a page change: time to the answer (`Server-Timing` of the API, browser time, number of requests per action, whether a preflight happens, payload sizes). Do it with and without throttling (Chrome "Fast 4G") and with a simulated 150 ms round trip, because that is what a customer far from the server sees.
2. **Find the biggest costs** and rank them. Candidates, in the order I would check:
   * the CORS preflight (above), and any redirect (`http` to `https`, `www`, trailing slash) in front of the API;
   * one action making several requests one after the other (add to cart then a cart refresh then a quote): merge them, return the new cart in the add-to-cart answer, or run them in parallel;
   * the place-order request doing work the customer does not need to wait for (mail, push, stock alerts, the rebuild hook) instead of after the response; the checkout quote called on every keystroke without a pause;
   * page changes: link prefetching (`NuxtLink` prefetch, `prefetchOn`), the data of the next page fetched only after the click, skeletons instead of blank waits, payload files, fonts and picture sizes (width and height, modern formats, lazy loading below the fold, a small hero);
   * the API on a slow shared host: OPcache, `config:cache`, database on the same machine, slow queries on `/products`, `/cart`, `/checkout/quote` (use `EXPLAIN`);
   * caching: Cloudflare in front of the API address so the public GET calls (`/store/settings`, `/products`, `/categories`) and `/storage/*` pictures are cached at the edge (the API already sends `Cache-Control` with `s-maxage` and `stale-while-revalidate`; check Cloudflare really honours it), HTTP/2 or 3, compression (Brotli).
3. **Fix** the top causes. Keep every change small, tested and documented. Optimistic updates are welcome (show the new cart count at once, undo with a message on failure) as long as the server stays the authority for prices and stock.
4. **Prove it**: the same measurements after, in a table (before, after, how). Run the full test suites (prompt 1, "How to test") so nothing else broke, update `CHANGELOG.md` and `docs/PERFORMANCE.md`, commit locally.

### Rules

The rules of prompt 1 apply (no push, no touching my database or my servers, report failures honestly). Do not change prices, stock or order rules to make things faster. Do not add a tracking or analytics script. If a fix needs something on the hosting (Cloudflare settings, PHP settings, a cron), do not guess: write the exact steps for me in `docs/GO_LIVE.md` and tell me.
