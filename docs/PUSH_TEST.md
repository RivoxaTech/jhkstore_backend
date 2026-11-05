# Push notifications: a manual test you can run on your machine

Goal: place an order through the storefront API and watch a real browser notification appear on your desktop (Windows + Laragon, Chrome). About ten minutes.

What a test in code can prove (`php artisan test`, `scripts/flow-check.mjs`) is the server half: the message content, who gets it (never the developer), the queue, the error reporting. What only a real browser can prove: that the browser accepts the subscription, that the push service delivers, and that the operating system shows the notification. This guide covers that second half.

## 1. Set up

1. **Keys.** In a terminal in the project folder (Laragon's PHP on Windows needs `OPENSSL_CONF`):

   ```
   set OPENSSL_CONF=C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\extras\ssl\openssl.cnf
   php artisan shop:vapid
   ```

   (PowerShell: `$env:OPENSSL_CONF = '...'`.) It prints three lines.
2. **`.env`.** Paste them in, set `VAPID_SUBJECT` to a real contact (`mailto:you@example.com`), and keep `QUEUE_CONNECTION=database`. Then `php artisan config:clear`.
3. **Run the app on localhost** (a port other than 8000 if another project uses it):

   ```
   php artisan serve --port=8002
   ```

   Chrome allows push on `http://localhost` and `http://127.0.0.1`; any other address needs https.
4. **Run the queue worker** in a second terminal, and leave it open:

   ```
   php artisan queue:work
   ```

   Push messages are queued like e-mails: without a worker nothing is sent. (Optional: `php artisan schedule:work` in a third terminal runs the one-minute heartbeat the status card uses to say "worker running".)
5. **Switch push on.** Log in as the developer at `http://127.0.0.1:8002/admin`, open Settings → Notifications and switch **Admin push** on, then **Save changes**. The developer never receives order notifications, so this account only configures.

## 2. Subscribe as the client admin

1. Log out and log in as the client admin (`admin`, or the account the developer gave to the client).
2. Click the **bell** in the top bar. Chrome asks "Allow notifications?": choose **Allow**. The bell turns green.
3. Click the **status** button (the pulse icon next to the bell) or open Settings → Notifications. In **Notification status** every line should be green: browser support Yes, permission Granted, this device subscribed Yes, push keys Configured, switch On, queue worker Running (or "No activity seen yet" until the first job).
4. Press **Send test notification**. Within a few seconds Windows shows "Test notification". The card says "Sent" or shows the exact error (see the troubleshooting list). This sends a real push through the real queue to this browser only.

## 3. The real thing: an order

Place an order through the storefront API (the product detail endpoint gives you a `variant_id`):

```
curl -X POST http://127.0.0.1:8002/api/v1/store/orders ^
  -H "Content-Type: application/json" -H "Accept: application/json" ^
  -d "{\"customer\":{\"name\":\"Test Buyer\",\"phone\":\"+92 300 1234567\"},\"address\":{\"line1\":\"1 Test Road\",\"city\":\"Lahore\"},\"items\":[{\"variant_id\":1,\"qty\":1}]}"
```

Expected, within a few seconds, with the admin panel open **or closed**:

- A Windows notification titled **New order**, body `ORD-2026-000001 · $24.00` (order number and total), with the store logo as its icon (if a logo is uploaded).
- It **stays on screen until you click it** (`requireInteraction`).
- Clicking it opens (or focuses the tab that is already open and goes to) `/admin/orders/{id}`.
- Placing a second order creates a second notification (each order has its own tag); a newer message about the **same** order replaces the older one.

Sound: the browser and the operating system play their own default notification sound, and a website cannot change it. So the panel has its own alert: while a panel tab is open, when the pending-orders number rises (or a push message arrives) it plays a short chime and shows a toast. Click anywhere on the page once after loading so the browser allows sound (the header says "Click anywhere once to enable sound" until then); the speaker button in the top bar mutes and unmutes (remembered in this browser).

## 4. Troubleshooting

| Symptom | Cause and fix |
|---|---|
| The bell is grey and says it cannot show notifications | The address is not https and not localhost, or the browser has no push support. Use `http://localhost:…` for the test; production needs https. |
| "Notifications are blocked for this site" | Chrome: the lock icon next to the address → Site settings → Notifications → Allow. Then press the bell again. |
| Permission "Not asked yet" and nothing happens on the bell | The bell asks only when pressed; a blocked pop-up setting for the site can hide the prompt. |
| Status: "Push keys (VAPID): Missing" | `php artisan shop:vapid`, put the three lines in `.env`, `php artisan config:clear` (and restart `queue:work`: a running worker keeps the old config). Changing the keys later invalidates every subscribed browser: press the bell off and on again. |
| "Push switched on in Settings: Off" | Settings → Notifications → Admin push → Save changes. |
| Test stays "Waiting for the queue worker…" and then "no queue worker took the job" | `php artisan queue:work` is not running (or runs against another database / `.env`). Start it; it picks the job up and the test finishes. |
| Test says "Could not resolve host" / a cURL error | This computer cannot reach the browser vendor's push service (Chrome uses `fcm.googleapis.com`, Edge `wns2-…notify.windows.com`, Firefox `updates.push.services.mozilla.com`). Check the internet connection, proxy and firewall. Push needs outbound https from the **server**. |
| Test says 401 / 403 / "VAPID" error | The key pair does not match the one the browser subscribed with: press the bell off and on again; check `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` were not truncated. |
| Test says "no longer subscribed" | The subscription expired (the browser data was cleared). The status card removes it; press the bell again. |
| "Sent" but nothing appears | The operating system hides it: Windows **Focus Assist / Do Not Disturb** is on (Settings → System → Notifications), or Chrome is not allowed to notify in Windows (Settings → System → Notifications → Google Chrome → On). macOS: System Settings → Notifications → Chrome → Allow, and turn Focus off. |
| Chrome is closed and nothing arrives | Chrome must be allowed to run in the background (Settings → System → "Continue running background apps when Google Chrome is closed"), or at least be open. |
| Works in Chrome, not in Safari | Safari on macOS 13+ supports web push for sites opened normally; on iPhone/iPad the site must be added to the Home Screen first. Safari asks for permission only after a click and needs https. |
| An order was placed but nothing was sent | Check, in order: Settings → Notifications → the **New order** row has Admin push on; the account holds `orders.view`; `jobs` / `failed_jobs` tables (`php artisan queue:failed`); `storage/logs/laravel.log` ("Web push failed: …"). |
| The developer does not get order notifications | By design: the developer role can never hold `orders.view`. A developer with `wholesale.view` switched on gets wholesale-request notifications only. |

## 5. What is verified automatically and what is not

Verified by tests (`php artisan test`, flow-check G29/G31/H): the message (title "New order", order number and total in the body, logo icon, tag per order, link to the order), who receives it (never the developer), per-event switches, the job on the real queue, the test notification flow and its error text, the queue worker states (running / stopped / idle), the status card data, the header's sound toggle and chime wiring in a headless browser.

Only a real browser and desktop can confirm: the permission prompt, the subscription with the real push service, that the notification appears and stays until clicked, the click opening the right order, the system sound, Focus Assist behaviour, and the chime actually being audible.
