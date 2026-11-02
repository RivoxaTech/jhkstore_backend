# Access control

There are exactly **two admin accounts** and **two roles**. Nobody else can log in: customers have no account, and there is no screen to create more admins.

| Role | Who | Starts with |
|---|---|---|
| `developer` | the team that builds and runs the site | everything **except orders** (no orders screen, no order API, no order number, total or customer detail anywhere, dashboard included). The customers list is off by default (personal data) but can be switched on |
| `admin` | the client | the dashboard, orders, the customers list (with export) and three Settings tabs: General, Footer & Social, Orders & Delivery. The developer switches the rest on or off |

Both accounts are created by the seeder from `.env`: `SEED_DEVELOPER_USERNAME` / `SEED_DEVELOPER_PASSWORD` and `SEED_ADMIN_USERNAME` / `SEED_ADMIN_PASSWORD` (optional `_NAME` and `_EMAIL`). In production the seeder refuses a password shorter than 12 characters or equal to the username, and refuses identical usernames. Running the seeder again never changes an existing password or the permissions the developer set.

## Permissions

| Permission | Allows | Admin default | Developer default |
|---|---|---|---|
| `dashboard.view` | the dashboard | ✔ | ✔ |
| `products.view` / `products.manage` | products and tags: see / create, edit, delete, pictures | – | ✔ |
| `categories.view` / `categories.manage` | categories and sub-categories: see / create, edit, delete | – | ✔ |
| `orders.view` / `orders.manage` | orders: see / change status, notes. Includes the printable invoice and packing slip | ✔ | **never** |
| `customers.view` / `customers.export` | the customers list / the CSV download | ✔ | off, can be switched on |
| `settings.general` | Settings → General (store name, contact details) | ✔ | ✔ |
| `settings.branding` | Settings → Branding (logo, favicon) | – | ✔ |
| `settings.banners` | Settings → Banners (create, edit, delete, reorder, activate, placements, pictures) | – | ✔ |
| `settings.footer` | Settings → Footer & Social | ✔ | ✔ |
| `settings.currency_tax` | Settings → Currency & Tax | – | ✔ |
| `settings.orders_delivery` | Settings → Orders & Delivery | ✔ | ✔ |
| `settings.seo` | Settings → SEO | – | ✔ |
| `access.manage` | the Access Control screen | **never** | ✔ (fixed) |

The list lives in `App\Domain\Auth\AdminAccess` (`GROUPS`). Adding a Settings tab later (for example *Payment details*) = one entry in `SettingController::TABS`, its setting definitions in `Settings::DEFINITIONS`, and a `settings.payment_details` line in `AdminAccess::GROUPS`; the Access Control screen, the API checks and the tab list of the panel follow from those.

## Access Control screen (developer only)

Sidebar → **Access Control**. One switch per permission for the `admin` role, grouped by section (a group can be switched on or off as a whole), and the developer's own customers-list switches. Saving writes the role in the spatie tables, so the change is effective on the admin's next request (no deployment, no re-login).

API: `GET /api/v1/admin/access-control` (the matrix) and `PUT /api/v1/admin/access-control/{admin|developer}` with `{ "permissions": ["products.view", …] }` (the full list of switches that are on).

## Hard rules

Enforced in the backend, on every write **and** on every check, and covered by tests:

* The developer can never hold `orders.*`. `PUT access-control/developer` with an orders permission answers 422; a permission row inserted by hand into the database is ignored by `AdminAccess::allows()`.
* `access.manage` belongs to the developer only; asking to give it to the admin answers 422.
* The developer's other permissions are fixed on; only `customers.view` and `customers.export` can be changed for the developer.
* The dashboard sends **no** order figures, revenue or order numbers to an admin without `orders.view`, and its activity list leaves out every order-related entry.

## Enforcement

* **API (403)**: every admin route except login, logout and `me` carries `actor:admin` and `admin.can:<permission>`. The permission is checked **before** the record is looked up, so a forbidden call is 403 whether or not the record exists. A test walks every admin route and fails if one has no permission check, and calls an endpoint of every permission as each role.
* **Settings**: `GET /admin/settings` lists only the tabs the admin may open and only their values; `PUT /admin/settings` and the picture endpoints check the tab of every key they touch (403 if any is not allowed).
* **Panel**: `GET /admin/auth/me` returns the effective `permissions`. The sidebar items, the Settings tabs and the buttons without permission are hidden; opening a URL without permission shows the "no access" page.

## Audit log

`audit_logs` records every admin change: settings, products, categories, tags, banners, order status and notes, and **permission changes** (`access.updated` with the role and the lists of granted and revoked permissions, subject = the role) and customer exports (`customers.exported`).
