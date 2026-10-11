# CrowdsourcedAPI — PowerGuide Dagupan

Crowdsourced power-outage tracking and blackout-survival platform for **Dagupan
City**. Residents report power outages, floods, and electrical hazards; DECORP
(electric company) and field linemen verify and manage them; and safety tools
(battery tracking, food-safety timers, heatmaps, resource/power-station maps)
help residents get through outages.

This repository contains the **REST JSON API** (backend only).

- **Language:** Procedural PHP 8
- **Database:** MariaDB / MySQL
- **Data access:** PDO (prepared statements)
- **Auth:** JWT (HttpOnly cookie) + Google OAuth
- **No framework, no build step** — plain PHP files served by Apache/XAMPP.

---

## Table of Contents

1. [Features](#features)
2. [Tech Stack & Dependencies](#tech-stack--dependencies)
3. [Directory Structure](#directory-structure)
4. [Requirements](#requirements)
5. [Installation / Setup](#installation--setup)
6. [Database & Migration](#database--migration)
7. [Authentication & Authorization](#authentication--authorization)
8. [API Conventions](#api-conventions)
9. [Endpoint Reference](#endpoint-reference)
10. [Configuration (.env)](#configuration-env)
11. [Security Notes](#security-notes)

---

## Features

- **Outage reporting** — residents submit reports (categories, severity,
  hazards, affected houses, photos); anti-spam enforces one active report/user.
- **Field verification & updates** — linemen/company staff verify reports
  (confirmed / not confirmed / false report), record field updates, and advance
  status through `active → under_review → verified → resolved`.
- **Electric company management** — update status of a single report, a whole
  barangay, or the entire city of Dagupan.
- **Maintenance schedules** — company schedules power interruptions and
  auto-notifies users whose saved location falls within the maintenance radius.
- **Power station map** — residents register available charging/solar/generator
  stations; nearby stations are listed sorted by distance.
- **Notifications** — per-user in-app notifications (maintenance, safety-timer
  reminders, hazard verification, etc.), read/unread tracking.
- **Battery tracking** — multiple devices, current percentage, usage logging,
  and simple remaining-hours budgeting.
- **Safety timers** — food/medication safety countdowns (sealed refrigerator,
  deep freezer 24h/48h, medication) with automatic `warning`/`expired` status
  and one-time alerts.
- **Flood & electrical hazards** — crowdsourced reports used to render
  **electrocution-risk areas** (floods + hazards near a location).
- **Heatmap & clustering** — rule-based clustering of outages by barangay or
  radius with confidence scoring (verified/total) and severity forecasting
  (low/moderate/high/critical).
- **Local + Google login** — password accounts and Google OAuth (both always
  assign the `user` role; roles are never client-chosen).

---

## Tech Stack & Dependencies

- PHP 8 (cURL + JSON extensions enabled)
- MariaDB 10.x / MySQL
- [vlucas/phpdotenv](https://github.com/vlucas/phpdotenv) ^5.6 — `.env` loading
- [firebase/php-jwt](https://github.com/firebase/php-jwt) ^7.0 — JWT encoding/decoding

---

## Directory Structure

```
CrowdsourcedAPI/
├── .env                        # configuration (DB, JWT secret, API keys, OAuth)
├── .htaccess                   # Apache rewrite rules (frontend pages)
├── composer.json               # dependency manifest
├── README.md                   # this file
├── endpoints.md                # detailed endpoint reference
├── api/                        # public HTTP endpoints (one file per action)
│   ├── auth/                   # register, login, logout, me, google oauth
│   ├── outage_report/          # user-facing outage reporting
│   ├── outage/                 # staff/lineman field operations
│   ├── outage_report_electric_com/  # electric company management
│   ├── maintenance/            # maintenance schedules
│   ├── maintenance_map/        # maintenance for map display
│   ├── power_station/          # charging/solar/generator stations
│   ├── notification/           # notifications
│   ├── user_location/          # saved user locations
│   ├── battery/                # battery devices & history
│   ├── safety_timer/           # food/medication safety timers
│   ├── flood_report/           # flood reports
│   ├── electrical_hazard/      # electrical hazards
│   ├── risk/                   # combined electrocution-risk areas
│   ├── heatmap/                # outage heatmap & clustering
│   ├── cluster/                # persisted outage clusters
│   ├── reference/              # lookup tables
│   └── services/               # shared helpers (not endpoints)
├── auth/                       # auth helpers (JWT issue/verify, RBAC, OAuth)
│   ├── issue_jwt.php
│   ├── jwt_auth.php
│   ├── rbac.php
│   └── google_oauth.php
├── config/
│   ├── env.php                 # loads .env via phpdotenv
│   ├── cors.php                # Access-Control-* headers, allow-list from .env
│   └── db_connect.php          # PDO connection factory
├── database/
│   └── powerguidedagupan.sql   # canonical normalized schema + seed data
└── vendor/                     # composer dependencies
```

> `uploads/outage/` is created automatically at runtime for report photo uploads.

---

## Requirements

- Apache + PHP 8 (e.g. XAMPP) with `pdo_mysql`, `curl`, `json`, `fileinfo`,
  `openssl` extensions.
- MariaDB / MySQL server.
- [Composer](https://getcomposer.org/) to install PHP dependencies.
- Internet access to geocode (Geoapify) and for Google OAuth / JWKS.

---

## Installation / Setup

1. **Copy the project** into the web root, e.g.
   `C:\xampp\htdocs\CrowdsourcedAPI`.

2. **Install dependencies** (from the project root):

   ```bash
   composer install
   ```

3. **Create `.env`**: copy `.env.example` to `.env` and fill it in (see
   [Configuration](#configuration-env)). A `.env` file is expected by
   `config/env.php`, and it is gitignored, so every machine makes its own.

4. **Make sure MySQL/MariaDB is running**, then import the database (see next
   section).

5. **Serve the API** at `http://localhost/CrowdsourcedAPI` (XAMPP serves the
   `htdocs` folder automatically).

6. Verify with the auth + lookup endpoints:

   ```bash
   curl -c cj.txt -X POST http://localhost/CrowdsourcedAPI/api/auth/register.php \
     -H "Content-Type: application/json" \
     -d '{"first_name":"A","last_name":"B","email":"a@b.com","password":"secret123"}'

   curl -b cj.txt http://localhost/CrowdsourcedAPI/api/reference/get.php
   ```

---

## Database & Migration

The canonical schema is `database/powerguidedagupan.sql` — a **normalized** design
(28 tables) with seeded lookup data:

- **Lookup tables:** `roles`, `outage_categories`, `severity_levels`,
  `hazard_types`, `outage_statuses`, `power_station_types`, `safety_timer_types`,
  `notification_types`.
- **Reference:** `barangays` (36 Dagupan barangays seeded).
- **Users/roles:** `users`, `user_locations`.
- **Outage tracking:** `outage_reports`, `outage_report_images`,
  `outage_report_updates`, `outage_report_verifications`; spatial aggregation in
  `outage_clusters` and `outage_cluster_reports`.
- **Safety tools:** `battery_devices`, `battery_usage_logs`, `safety_timers`,
  `safety_timer_alerts`.
- **Disaster reports:** `flood_reports`, `electrical_hazards`.
- **Company tools:** `maintenance_schedules`, `maintenance_locations`,
  `power_stations`, `notifications`.
- **Staff scoping:** `lineman_assignments` — which `lineman` covers which
  `barangay`, enforced by the backend on the outage endpoints.

> The older `database/crowdsource.sql` and `database/powerguard.sql` are legacy
> schemas kept for reference; **`powerguidedagupan.sql` is the one to import.**

### Migrations (existing database)

Incremental scripts live beside the schema and are numbered, so an existing
database can be brought up to date without being recreated. Each one is written to
be **additive and idempotent** (`CREATE TABLE IF NOT EXISTS`, no `DROP`, no data
rewrites) — re-running one against an already-migrated database is a no-op.

| Script | Adds |
|--------|------|
| `database/002_lineman_assignments.sql` | `lineman_assignments` — lineman ↔ barangay assignments |

Apply one with:

```bash
"C:\xampp\mysql\bin\mysql.exe" -u root powerguide < database\002_lineman_assignments.sql
```

Or in phpMyAdmin: select the `powerguide` database, **Import →** choose
`database/002_lineman_assignments.sql`.

> A fresh install that imports `powerguidedagupan.sql` already contains this
> table — the numbered script is only for a database created before it existed.
> Never re-run `powerguidedagupan.sql` against a populated database: it starts
> with `DROP DATABASE`.

### Import (fresh install)

From a terminal (XAMPP):

```bash
"C:\xampp\mysql\bin\mysql.exe" -u root < database\powerguidedagupan.sql
```

Or in phpMyAdmin: **Import →** choose `database/powerguidedagupan.sql`.

The script `DROP DATABASE IF EXISTS powerguide` first, then recreates it with
the full schema and seed data. The DB does **not** exist beforehand in a fresh
install, so running it is safe (no existing user data is lost).

> **Note:** the seeded `barangays` rows have `NULL` coordinates. When creating
> location-based records, either pass coordinates explicitly, or rely on the
> server's geocoding / barangay-name resolution.

---

## Authentication & Authorization

### JWT cookie flow

1. On `register`, `login`, or `google_callback`, the server issues its own JWT
   and stores it in an **HttpOnly cookie** named `jwt_token`.
2. Every protected endpoint reads and verifies this cookie, decodes the JWT
   (HS256, signed with `JWT_SECRET_KEY`), and uses the payload's
   `id`, `email`, and `role` claims.
3. Tokens expire after **24 hours**.

### Roles

New accounts (local or Google) **always** receive the `user` role. Roles are
stored server-side and carried in the JWT; the frontend can **never** choose a
role.

| Role | Meaning | Typical access |
|------|---------|----------------|
| `user` | Regular resident | report/view outages, floods, hazards; safety tools |
| `lineman` | DECORP field personnel | verify reports, field updates, hazard resolution — **only in assigned barangays** |
| `electric_company` | DECORP staff | manage maintenance, create notifications, resolve outages, assign linemen |
| `admin` | System administrator | same as company + full control |

`lineman`, `electric_company`, and `admin` are collectively treated as
**staff** by several endpoints (staff may act on reports they did not create).
Maintenance creation/update, `notification/create`, `cluster/store` and
`lineman_assignment/*` are restricted to company/admin (`requireRole`).

### Lineman assignments

A `lineman` only operates on outage reports in the barangays an
`electric_company` / `admin` account has assigned to them. The mapping lives in
`lineman_assignments` and is enforced **in SQL** by `auth/lineman_access.php`,
which every outage endpoint calls:

- **List endpoints** (`outage/get.php`, `outage_report_electric_com/get.php`,
  `outage_report/get_detail.php`) restrict the query with a subquery on active
  assignments, so an unauthorized report is never loaded. A `?barangay=` filter
  cannot widen this — it is ANDed with the scope.
- **Single-record actions** (`outage/verify.php`, `outage/add_update.php`,
  `outage_report_electric_com/update_single.php`) check the report's barangay
  *before* writing, answering `403` without touching any table.
- `outage_report_electric_com/update_barangay.php` is checked against the same
  assignments; `update_dagupan.php` (city-wide, no `WHERE` clause) is
  **company/admin only**.
- `electric_company` and `admin` pass through untouched — their access is
  unchanged.

Managers read and write assignments at `api/lineman_assignment/*`; a lineman
reads only their own scope via `my.php`, which takes **no** identity parameter —
the caller comes from the JWT alone. Full reference in
[`endpoints.md`](endpoints.md#lineman-assignments).

### Helpers

- `auth/jwt_auth.php` — `getUserFromJWT()` decodes/verifies the cookie.
- `auth/issue_jwt.php` — `issueJWT()`, role name↔id resolvers, `getUserRecord()`.
- `auth/rbac.php` — `hasRole()`, `requireAuthUser()`, `requireRole()`,
  `denyAccess()`.
- `auth/google_oauth.php` — OAuth URL, code exchange, Google id_token
  verification against Google's JWKS (audience checked against
  `GOOGLE_CLIENT_ID`).

---

## API Conventions

- **Base URL:** `http://localhost/CrowdsourcedAPI`
- **Content-Type:** `application/json` on both request and response.
- **Response envelope:** every endpoint returns a JSON object:

  ```json
  { "success": true|false, "message": "...", ...data }
  ```

- **Auth:** protected endpoints require the `jwt_token` cookie. Missing/invalid
  → `401`. Insufficient role → `403`.
- **Validation:** missing required fields → `400`. Not found / out of coverage →
  `404`. Duplicates (e.g. already-active report, duplicate maintenance) → `409`.
- **Mutation:** endpoints accept a JSON body (most also tolerate form-encoded).
- **Coordinate inputs:** many endpoints accept explicit `latitude`/`longitude`;
  otherwise they geocode via Geoapify or match to a barangay.
- **Lookup values:** string values you send (e.g. `severity`, `category`,
  `status`) are resolved to ids server-side against the lookup tables. See
  `GET /api/reference/get.php` for valid values.

### Coordinate model (barangay-sourced)

Every report, hazard, flood, power station and saved location is placed on the
map by its **barangay's stored centroid**. The `barangays` table holds
`latitude`/`longitude` for each barangay (filled by
`database/fill_barangay_coordinates.php` and baked into
`database/003_barangay_coordinates.sql`). The client selects a barangay, the
API reads the stored centroid, and an optional map pin may refine the point.
The free-text `location_name` / `address` field is only a label shown to other
users; it is **never geocoded**.

Endpoints that accept `barangay_name` (now required) and optional
`latitude`/`longitude`:

| Endpoint | Required | Optional refine |
|---|---|---|
| `outage_report/create.php` | `barangay_name` | `latitude`, `longitude` |
| `outage_report/update.php` | (unchanged) | `barangay_name`, `latitude`, `longitude` |
| `flood_report/create.php` | `barangay_name` | `latitude`, `longitude` |
| `electrical_hazard/create.php` | `barangay_name` | `latitude`, `longitude` |
| `power_station/create.php` | `barangay_name` | `latitude`, `longitude` |
| `power_station/update.php` | (unchanged) | `barangay_name`, `latitude`, `longitude` |
| `user_location/location.php` | `barangay_name` | — |
| `maintenance/create.php` | `barangays[]` (names) | — |
| `maintenance/update.php` | `barangays[]` (names) | — |

A barangay with NULL coordinates is rejected with **503** and the full list of
missing barangays. An unknown barangay name is **400**. Omitting `barangay_name`
is **400**.

---

## Endpoint Reference

A full, per-endpoint reference with methods, roles, and request bodies is in
**[`endpoints.md`](endpoints.md)**.

### Quick index by feature

| Feature | Endpoints (relative to `/api/`) |
|---------|----------------------------------|
| Auth | `auth/register.php`, `auth/login.php`, `auth/logout.php`, `auth/me.php`, `auth/google.php`, `auth/google_callback.php` |
| Reference | `reference/get.php` |
| Outage (user) | `outage_report/{create,get,get_active,get_resolve,get_my_report,get_detail,update,delete,upload_image}.php` |
| Outage (staff) | `outage/{get,verify,add_update}.php` |
| Outage (company) | `outage_report_electric_com/{get,update_single,update_barangay,update_dagupan}.php` |
| Lineman assignments | `lineman_assignment/{get,create,update,delete,my,linemen}.php` |
| Maintenance | `maintenance/{create,update,get,get_upcoming,get_complete,delete}.php`, `maintenance_map/get.php` |
| Power stations | `power_station/{create,get,get_available,get_my_posts,get_near_location,update,delete}.php` |
| Notifications | `notification/{get,mark_as_read,mark_all_as_read,create}.php` |
| User location | `user_location/{get,location}.php` |
| Battery | `battery/{create,get,update,set_percentage,log_usage,get_history,delete}.php` |
| Safety timers | `safety_timer/{create,get,stop,delete}.php` |
| Flood reports | `flood_report/{create,get,get_nearby}.php` |
| Electrical hazards | `electrical_hazard/{create,get,get_nearby,update_status}.php` |
| Risk (electrocution) | `risk/get_nearby.php` |
| Heatmap | `heatmap/get.php` |
| Clusters | `cluster/{store,get}.php` |

---

## Configuration (.env)

`.env` is loaded by `config/env.php` and drives the whole app:

```dotenv
DB_HOST=localhost
DB_NAME=powerguide
DB_USER=root
DB_PASS=

GEOAPIFY_GEOCODING_API_KEY=your_geoapify_key

JWT_SECRET_KEY=your_secret_at_least_32_chars

GOOGLE_CLIENT_ID=your_google_client_id
GOOGLE_CLIENT_SECRET=your_google_client_secret
GOOGLE_REDIRECT_URI=http://localhost/CrowdsourcedAPI/api/auth/google_callback.php

FRONTEND_URL=http://localhost:5174

# ALLOWED_ORIGINS is commented out by default - see the CORS section below.
# ALLOWED_ORIGINS=http://localhost:5174,http://127.0.0.1:5174
```

| Key | Purpose |
|-----|---------|
| `DB_*` | PDO connection (see `config/db_connect.php`) |
| `GEOAPIFY_GEOCODING_API_KEY` | **Only used by `database/fill_barangay_coordinates.php`** — a one-off backfill script. The API no longer geocodes addresses; coordinates come from the `barangays` table. |
| `JWT_SECRET_KEY` | Signs/verifies JWTs (HS256) - use a long random value |
| `GOOGLE_CLIENT_ID` / `_SECRET` / `_REDIRECT_URI` | Google OAuth. **Placeholders - fill in real values** before enabling Google sign-in |
| `FRONTEND_URL` | Where `google_callback.php` redirects the browser. **Not a CORS setting** |
| `ALLOWED_ORIGINS` | Comma-separated browser origins allowed to call this API cross-origin. Optional - a default localhost list is built in |

`.env` is gitignored, so every machine needs its own copy made from
`.env.example`. A missing `.env` is a hard failure (`config/env.php` throws) —
`cors.php` includes that file before it can emit any header, and an API that
answers 500 looks exactly like "CORS is broken" in the browser console.

### CORS

`config/cors.php` is included at the top of every endpoint and is the only place
`Access-Control-*` headers are set. It matters only when the browser calls the
API **cross-origin**, i.e. when the frontend's `VITE_API_BASE_URL` is an absolute
URL such as `http://localhost/CrowdsourcedAPI` instead of the proxied relative
path `/CrowdsourcedAPI`.

The frontend ships with the proxied relative path precisely so this file has
nothing to do in normal development: a same-origin request carries no `Origin`
header at all and needs no CORS headers back.

When it is involved, three constraints shape the implementation:

- **Exact-match allow-list.** `HTTP_ORIGIN` is compared against the list and the
  header is only sent on a hit. A miss means *no* `Access-Control-Allow-Origin`
  header at all, which is what the browser reports as a CORS error.
- **`*` is not an option.** The JWT session is an HttpOnly `jwt_token` cookie
  sent with `credentials: 'include'`, so the response must name one exact origin.
- **`FRONTEND_URL` does nothing here.** It is the OAuth redirect target. Editing
  it will not change CORS; `ALLOWED_ORIGINS` is the only knob.

Defaults, used when `ALLOWED_ORIGINS` is unset:

```
http://localhost:5174, http://127.0.0.1:5174, http://localhost:5173, http://127.0.0.1:5173
```

Setting `ALLOWED_ORIGINS` **replaces** those four rather than extending them, so
opening the dev server over the LAN (`http://192.168.1.20:5174`) means listing
that origin explicitly.

To see what a browser would see:

```bash
curl -i -H "Origin: http://localhost:5174" \
  http://localhost/CrowdsourcedAPI/api/reference/get.php
# -> Access-Control-Allow-Origin: http://localhost:5174

curl -i -H "Origin: http://192.168.1.20:5174" \
  http://localhost/CrowdsourcedAPI/api/reference/get.php
# -> no Access-Control-Allow-Origin: the origin is not in the allow-list
```

---

## Security Notes

- **Prepared statements everywhere** via PDO — no SQL string concatenation.
- **Photo uploads** are validated by extension, MIME (via `fileinfo`), and a
  **5 MB size cap**; stored outside web-reachable logic.
- **Passwords** are hashed with `password_hash()` (bcrypt).
- **JWT** is stored in an `HttpOnly` cookie (not readable by JS) and signed with
  a server secret; roles live inside the signed token and are enforced per
  endpoint.
- **Google identity** is verified against Google's published JWKS and the
  audience (`GOOGLE_CLIENT_ID`) is checked before trusting the email.
- **Ownership checks** — mutate operations scope queries to `user_id` /
  `created_by` so users can only change their own data (battery, timers,
  stations, reports, locations).
- **Assignment checks** — a `lineman`'s outage access is scoped to their
  actively assigned barangays, enforced in SQL by `auth/lineman_access.php`
  (list endpoints exclude unauthorized rows; single-record actions answer `403`
  before writing).
- **Server-derived authorship** — `assigned_by` comes from the JWT, never the
  request body, and an assignment target's role is re-read from `roles` on every
  write, so a client cannot nominate who made a change or who is a lineman.
- **Registration always creates a `user`** — `register.php` ignores any `role` in
  the body. Privileged roles are granted only by writing `users.role_id`
  directly.
- Google OAuth credentials and the JWT secret are **placeholders in `.env`** —
  replace them before deploying publicly; never commit real secrets.
