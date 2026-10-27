# Dentalkart Admin (Vue 3)

Admin panel for the Dentalkart backend (Vue 3, Vite, Pinia, Vue Router).

```bash
npm install
npm run dev        # http://localhost:5190   (backend must run on http://localhost:8000)
npm run build      # -> ../public/admin-assets, served by Laravel at http://localhost:8000/admin
```

* Login: `admin` / `admin` (local seed account). Cookie session (HttpOnly) + CSRF; the Postman/script side of the API uses bearer tokens.
* The dev server proxies `/api`, `/sanctum`, `/storage` to `BACKEND_URL` (see `.env.example`), so the browser sees one origin.
* Pages: dashboard, companies (tabs, search, filters), create/edit company (logo required, catalogue optional), company detail
  (approve / reject / suspend, brands with logo upload, private catalogue download).
* Backend `.env` needs `SANCTUM_STATEFUL_DOMAINS` to include `localhost:5190` and `localhost:8000` (already set locally).
* Production: HTTPS, `SESSION_SECURE_COOKIE=true`, set `SANCTUM_STATEFUL_DOMAINS` to the real admin domain, change the seeded admin password.
