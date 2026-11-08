# Build progress (one-go plan: see docs/BUILD_PLAN_ONE_GO.md)

Branch: lean-store. Never merge / push. Baseline before the work: 514 PHPUnit tests (1 skipped), HEAD 04ed208.

## Stages
- Stage 1 (backend prep): DONE, GATE 1 green (PHPUnit 551 tests / 550 passed + 1 skipped, flow-check 553/553 incl. G33, smoke 79, flow-ui UI-H1 ok; two flow-ui timing checks fixed, re-verify in Gate 2)
- Stage 2 (restructure + rename): code done (commits e23069d move, 767a9f5 paths, 7f38a98 rename+docs); GATE 2 in progress: PHPUnit 551 tests (same as before the move) green, route:list / schedule:list / queue:work / serve+/admin+sw+media ok, store.name 'JHK Store' ok; flow-check + flow-ui from the new layout running; smoke + base reseed of jhkstore_db still to do
- Stage 3 (storefront): not started

## Step in progress
Gate 2: wait for flow-check and flow-ui (scratchpad runflows.sh, ROOT=backend), then smoke (reseed shots + DemoSeeder), then reseed jhkstore_db base, commit, Stage 3.

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
Gate 1 results -> Stage 2 -> Stage 3.

## Stage 2 notes
- Tag pre-restructure = 204ce3e. Move done with git mv (history kept); public/storage symlink recreated with storage:link.
- Docs: every backend path prefixed with backend/ by a script; README rewritten; NEW_CLIENT has a cPanel section (3b).
- Rename: only 'My Store' existed (Settings default, .env.example); FEATURE_SPEC banned-word line and the CHANGELOG rename entry are the allowed leftovers.
- Reference images are now in design/ (3 files; no separate logo file exists).
