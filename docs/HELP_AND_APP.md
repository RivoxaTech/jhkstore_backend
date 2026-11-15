# The Help videos and the admin app

## The Help section

**Help** is the last item of the admin sidebar, for every signed-in account. It shows short, silent how-to videos with the steps written under each one (Add a product, Edit a product, Categories and sub-categories, Add a discount, Add a coupon, Settings). A person only sees the videos of the screens their account may use (the owner's account does not see the product videos unless the developer gives it product access in Access Control).

The files live in `admin-frontend/public/help/`: `<slug>.mp4`, `<slug>.jpg` (the picture shown before play) and `videos.json` (titles, steps, lengths, the permission each video needs). `npm run build` copies them to `backend/public/admin-assets/help`. The videos are small (about 0.4 to 1 MB, 35 to 65 seconds, 1280 x 760) so they load fast on a phone.

### Making them again (after the panel looks different, or for a client with another logo)

The videos are recordings of the real panel, made by a script, so they can be redone in a few minutes.

1. Run the backend on a **scratch database** with the demo data. The script adds a product, a category, a discount and a coupon and changes the contact settings, so never point it at a real shop: `php artisan migrate:fresh --seed && php artisan db:seed --class=DemoSeeder && php artisan serve --port=8012`.
2. Build the panel: `cd admin-frontend && npm run build`.
3. You need Chrome and `ffmpeg` (any build with libx264). Then, from the repo root: `HELP_BASE=http://127.0.0.1:8012 FFMPEG=/path/to/ffmpeg node scripts/help-videos/make.mjs` (or add a slug to make one video: `... make.mjs add-coupon`).
4. Build the panel again so the new files are copied, and commit `admin-frontend/public/help`.

`scripts/help-videos/flows.mjs` has one flow per video. Every `r.step('...')` is both the banner across the top of the video and the numbered step under it on the Help screen, so the text always matches the pictures. To add a video, add a flow there (slug, title, summary, the permission, which account records it, the steps). Product and category videos are recorded as the developer account, the rest as the owner's account.

## The admin panel as an app (PWA)

The panel can be installed like an app: on a computer (Chrome or Edge: the install icon in the top bar or in the address bar) and on a phone (Chrome: the install icon in the top bar or "Add to Home screen"; iPhone: Share, then "Add to Home Screen"). It then opens in its own window with no browser bars.

* **Manifest:** made by Laravel at `/admin/manifest.webmanifest` (`AdminManifestController`). The name is the store name from Settings plus "Admin", so one build serves every client. It needs no change per client.
* **Icons and favicon:** the files in `admin-frontend/public/` (`favicon.svg`, `favicon.ico`, `favicon-96x96.png`, `apple-touch-icon.png`, `web-app-manifest-192x192.png`, `web-app-manifest-512x512.png`) are the client's logo set; replace them per client (a generator such as realfavicongenerator.net makes all of them). The storefront has the same set in `frontend/public/` (used when no favicon is uploaded in Settings); its `site.webmanifest` holds the store name, change it per client.
* **Service worker:** `backend/public/admin-sw.js`, registered when the panel opens (https or localhost only). It keeps one small page, `admin-offline.html`, and shows it when the panel is opened without a connection. It caches nothing else: orders, products and pictures always come from the server, so the panel never shows old data. It also shows the push notifications (see PUSH_TEST.md).
* The panel needs a connection to work. "Installable" does not mean "works offline".
