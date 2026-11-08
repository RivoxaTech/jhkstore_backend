# Build progress (one-go plan: see docs/BUILD_PLAN_ONE_GO.md)

Branch: lean-store. Never merge / push. Baseline before the work: 514 PHPUnit tests (1 skipped), HEAD 04ed208.

## Stages
- Stage 1 (backend prep): DONE, GATE 1 green (PHPUnit 551 tests / 550 passed + 1 skipped, flow-check 553/553 incl. G33, smoke 79, flow-ui UI-H1 ok; two flow-ui timing checks fixed, re-verify in Gate 2)
- Stage 2 (restructure + rename): DONE, GATE 2 green (PHPUnit 551 = 551 tests before/after the move, flow-check 553/553, smoke 79, flow-ui 201 + the fixed UI-B1 check, route/schedule/queue/serve/admin/sw/media ok, store.name JHK Store)
- Stage 3 (storefront): DONE: frontend/ built, generate = 138 routes, e2e 119/119, Lighthouse a11y/BP/SEO 100, final PHPUnit + admin build re-run, docs written (STOREFRONT.md)

## Step in progress
All three stages are done; only the final report to the user is left (nothing merged or pushed).

## Decisions (Stage 1)
- Rebuild state (pending, window, last result) lives in the cache, no new table (46 tables stay).
- `rebuild_enabled` = AUTOMATIC rebuild only; the button and `shop:rebuild` work whenever a hook is set.
- Hook secret is sent as header `X-Rebuild-Secret`; hook address is reduced to its host in the audit log (the address carries a token).
- Change tracking is by admin ROUTE (products, categories, sub-categories, tags, banners, discounts, public settings, logo/favicon/share picture), not by model events: sales never pass there.
- Cache headers decided by route NAME (`StoreCacheHeaders::PUBLIC_ROUTES`); everything else and every non-200 is `no-store`.
- Picture sizes are read lazily with getimagesize and kept in `media.custom_properties.dimensions`.
- Nuxt: the brief says "Nuxt 3 (latest stable)" and uses the `app/` folder layout of Nuxt 4. Plan: Nuxt 3.21.x with `future.compatibilityVersion: 4` (gives `app/`), to be listed as an ambiguity at the end.
- Only THREE design images exist in the project root (hero_image.jpeg, tail_image.jpeg, "refrence ui.jpeg"): there is NO separate logo file. Mapping: reference = "refrence ui.jpeg" (1024x1536), hero background = hero_image.jpeg (1259x650), gift banner background = tail_image.jpeg (1376x389). Logo: drawn as an SVG/PNG from the reference screenshot (teal cart mark + wordmark). Tell the user.
- Reference colours (sampled): primary #09989E, dark text #15414A, footer #024850, accent (2-colour heading) teal #0A9697, gift banner bg #CFEBEE, page bg #F5FBFB.

## Gate commands (Stage 1)
- scratch DB env: `source <scratchpad>/flowenv.sh` (DB_DATABASE=jhkstore_shots, VAPID, ports 8002/8003), `php artisan migrate:fresh --seed --force`, `start_servers`, `bash runflows.sh` (flow-check then flow-ui; outputs flow-check.out / flow-ui.out in the scratchpad)
- PHPUnit: `env -u DB_DATABASE php vendor/bin/phpunit` (about 7 minutes); summarise with a small php reader
- Admin: `cd admin-frontend && npm run build && npm run e2e`

## Left
Nothing. To re-run the Stage 3 gate: start the API (scratch DB with the DemoSeeder) on 8002, `cd frontend && npm run generate && npm run test:e2e`.

## Stage 2 notes
- Tag pre-restructure = 204ce3e. Move done with git mv (history kept); public/storage symlink recreated with storage:link.
- Docs: every backend path prefixed with backend/ by a script; README rewritten; NEW_CLIENT has a cPanel section (3b).
- Rename: only 'My Store' existed (Settings default, .env.example); FEATURE_SPEC banned-word line and the CHANGELOG rename entry are the allowed leftovers.
- Reference images are now in design/ (3 files; no separate logo file exists).

## Stage 3 notes
- Nuxt: first built on 3.21.11 + compatibilityVersion 4; moved to Nuxt 4.6.4 on 2026-11-08 (Nuxt 3 end of life 31 July 2026; sitemap 9.x and robots 7.x need Nuxt 4.6+): generate 138 routes, e2e 119/119.
- API CORS needs the test origin: the e2e/serve use http://127.0.0.1:3000 (in FRONTEND_URLS).
- Each e2e run uses a random phone number (orders are limited to 10 an hour per phone) and the API server needs WHOLESALE_PER_HOUR >= 30.
- Dev DB (jhkstore_shots) has the theme, logo, 2 design banners, a YouTube video on wooden-castle-builder; jhkstore_db is base-seeded.
- Backend change for the storefront: product cards carry variant_id and min_order_qty (commit 720db64, test in StorefrontPrepTest + ProductStoreTest).

## Follow-up (2026-11-08, after Stage 3)
- Step 1: full flow-check 553/553 and flow-ui 202/202 re-run on fresh seeds (one flow-ui run died with a Chrome "frame detached" flake, the helper now retries once; one flow-check run failed the timing-sensitive coupon rate-limit check F23 while the machine was busy and passed on the clean rerun).
- Step 2: frontend upgraded to Nuxt 4.6.4 (see CHANGELOG, STOREFRONT.md "Framework version and support").
