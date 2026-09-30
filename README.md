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

3. **Create `.env`** (see [Configuration](#configuration-env)). A `.env` file is
   expected by `config/env.php`.

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
(27 tables) with seeded lookup data:

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

> The older `database/crowdsource.sql` and `database/powerguard.sql` are legacy
> schemas kept for reference; **`powerguidedagupan.sql` is the one to import.**

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
| `lineman` | DECORP field personnel | verify reports, field updates, hazard resolution |
| `electric_company` | DECORP staff | manage maintenance, create notifications, resolve outages |
| `admin` | System administrator | same as company + full control |

`lineman`, `electric_company`, and `admin` are collectively treated as
**staff** by several endpoints (staff may act on reports they did not create).
Maintenance creation/update, `notification/create`, and `cluster/store` are
restricted to company/admin (`requireRole`).

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
```

| Key | Purpose |
|-----|---------|
| `DB_*` | PDO connection (see `config/db_connect.php`) |
| `GEOAPIFY_GEOCODING_API_KEY` | Geocodes location strings into lat/lng |
| `JWT_SECRET_KEY` | Signs/verifies JWTs (HS256) — use a long random value |
| `GOOGLE_CLIENT_ID` / `_SECRET` / `GOOGLE_REDIRECT_URI` | Google OAuth. **Placeholders — fill in real values** before enabling Google sign-in |

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
- Google OAuth credentials and the JWT secret are **placeholders in `.env`** —
  replace them before deploying publicly; never commit real secrets.
