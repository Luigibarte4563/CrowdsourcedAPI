# CrowdsourcedAPI — PowerGuide Dagupan

REST-style JSON API for the PowerGuide Dagupan crowdsourced power-outage tracking
app. Built with **procedural PHP + PDO + MariaDB** and **JWT cookie authentication**
(no framework).

Base URL: `http://localhost/CrowdsourcedAPI`

---

## Authentication

All endpoints (except `register`, `login`, and the Google OAuth flow) require an
authenticated JWT.

- The server issues a JWT in an **`HttpOnly` cookie** named `jwt_token` on
  `register` / `login` / `google_callback`.
- Send the cookie with every subsequent request (a browser does this
  automatically; with `curl` use `-b cookies.txt -c cookies.txt`).
- Unauthorized requests return `401`.

### Roles

| Role | Meaning |
|------|---------|
| `user` | Regular PowerGuide user |
| `lineman` | DECORP field personnel |
| `electric_company` | DECORP / electric company personnel |
| `admin` | System administrator |

> `lineman`, `electric_company`, and `admin` are grouped as **staff** in several
> endpoints and may operate on reports they did not create. Maintenance, cluster
> store, and staff notification endpoints are restricted to company/admin.

Role names are stored in the `roles` lookup table and carried in the JWT `role`
claim, so every role check is a string comparison against one of the four names
above. Roles are enforced server-side on every request — the frontend's copy of
the role is only used for hiding UI, never for access decisions.

> ⚠️ **Known issue — `register.php` accepts a client-supplied `role`.** Despite
> what an earlier revision of this document claimed, the frontend *can* choose
> its own role at registration. `api/auth/register.php:27` reads an optional
> `role` from the request body, and `:85-88` resolves it against the `roles`
> table, falling back to `user` only when the name is unknown. Sending
> `{"role": "admin"}` therefore registers an administrator. The name is validated
> against the lookup table, so only the four real roles can be assigned — but
> none of them are privileged. `google_callback.php:141` does **not** share this
> behaviour: it hard-codes `user` for brand-new Google users. Treat the intended
> behaviour as "always `user`" and fix `register.php` accordingly.

### Standard response envelope

```json
{ "success": true|false, "message": "...", ...data }
```

Requests that mutate data accept a **JSON body** (also tolerate form-encoded).

---

## Authorization

Authentication and authorization are separate steps. Authentication answers
*who is this?*; authorization answers *may they do this, and on this record?*

### How a request is authorized

There is no framework middleware. Each endpoint file is a standalone script
that calls a guard helper at the top, so the guard is visible in the file
itself. The helpers live in `auth/rbac.php`, and the token check in
`auth/jwt_auth.php`.

1. **Decode** — `getUserFromJWT()` reads the `jwt_token` cookie, verifies the
   HS256 signature against `JWT_SECRET_KEY`, and returns the payload
   (`id`, `email`, `role`). Any failure — missing cookie, bad signature,
   expired — returns `null`, and all three are indistinguishable to the caller.
2. **Require a user** — `requireAuthUser()` turns a `null` payload into a `401`.
3. **Require a role** — `requireRole($user, [...])` exits `403` unless
   `$user['role']` is in the allow-list. It is a strict `in_array`, so there is
   no wildcard or hierarchy: `admin` is not implicitly a `lineman`. Each
   role-gated endpoint lists its roles explicitly.
4. **Check ownership** — on top of the role check, a `👤` endpoint filters by
   `user_id` / `created_by` / `reported_by` in the SQL itself, so a
   non-owner simply gets an empty result or a `404` rather than a data leak.

### `401` vs `403` vs `404`

| Status | Meaning |
|--------|---------|
| `401 Unauthorized` | No valid JWT — missing, malformed, wrong signature, or expired. Body: `{"success":false,"message":"Unauthorized"}` |
| `403 Forbidden` | Valid JWT, insufficient role. Emitted by `denyAccess()`. |
| `404 Not Found` | Valid JWT and sufficient role, but the record does not exist **or** is owned by someone else. Used to avoid confirming that another user's row exists. |

A `403` therefore means "your role is too low", and a `404` on a mutation
usually means "not yours" — the two are not interchangeable when debugging.

### Guard styles used in the codebase

The **Guard** column in the access matrix below names the exact pattern each
endpoint file uses:

| Guard | Pattern | Enforces |
|-------|---------|----------|
| `public` | no guard call at all | nothing |
| `jwt` | `getUserFromJWT()` + a hand-written `401` | any valid JWT |
| `auth` | `requireAuthUser()` | any valid JWT |
| `role` | `requireRole(requireAuthUser(), [...])` | an explicit role allow-list |
| `role-lr` | `hasRole()` + `denyAccess()` written out longhand | an explicit role allow-list |
| `owner` | the above, plus `user_id` / `created_by` in the SQL | ownership of the record |
| `owner-or-staff` | `requireAuthUser()` + an `$isStaff` branch on the fetched row | staff, or the record's owner |

`jwt`, `auth`, and `owner` combine with a `+` in the matrix when a file layers
ownership on top of its guard. `jwt` and `auth` are functionally identical — both
require any valid JWT and neither checks a role. The distinction is only
stylistic: `jwt` predates `auth/rbac.php` and still hand-rolls its `401`. Treat
them as one tier.

### What this model does *not* do

- **No permission table.** There is no `permissions` / `role_permissions`
  table anywhere. Authorization is coarse RBAC on `users.role_id` plus
  per-query ownership. There are no per-action or per-resource grants.
- **The role is never re-read from the database.** It is trusted from the JWT
  for that token's full 24-hour life. If an admin demotes a user in the
  database, the demotion has no effect until that user's token expires or they
  log in again. `me.php` is the only endpoint that re-reads the live role, so it
  can disagree with what the other endpoints actually enforce.
- **No token revocation.** `logout.php` only expires the cookie. Tokens are not
  tracked server-side, so a copied or stolen token keeps working until `exp`.
  The `users.refresh_token` column exists in the schema but is never written
  or read.
- **No role-management endpoint.** There is no user listing, user creation by
  admin, or role assignment API. Roles are changed by writing to
  `users.role_id` directly. Until `register.php` is fixed, that is the only
  *safe* way to grant a role.
- **No `SameSite` / `Secure` cookie attributes.** `issue_jwt.php:46` sets
  `httpOnly` but `secure = false` and emits no `SameSite`, relying on browser
  defaults — acceptable for localhost, not for production over HTTPS.
- **No rate limiting** on `login` or `register`, and no lockout after repeated
  failures.

---

## Endpoint Access by Role

Legend:
`✅` = allowed · `🔒` = blocked (403) · `👤` = only on resources **you** own
`—` = no authentication needed (public)

**Guard** names the authorization pattern the endpoint file actually uses — see
[Guard styles](#guard-styles-used-in-the-codebase) for what each one means and
[What this model does not do](#what-this-model-does-not-do) for the gaps.

| Endpoint | Guard | Public | `user` | `lineman` | `electric_company` | `admin` |
|----------|-------|:------:|:------:|:---------:|:------------------:|:-------:|
| **Auth** |||||||
| `POST /api/auth/register.php` | `public` | — | ✅ | ✅ | ✅ | ✅ |
| `POST /api/auth/login.php` | `public` | — | ✅ | ✅ | ✅ | ✅ |
| `POST /api/auth/logout.php` | `public` | — | ✅ | ✅ | ✅ | ✅ |
| `GET /api/auth/google.php` | `public` | — | ✅ | ✅ | ✅ | ✅ |
| `GET /api/auth/google_callback.php` | `public` | — | ✅ | ✅ | ✅ | ✅ |
| `GET /api/auth/me.php` | `jwt` | 🔒 | ✅ | ✅ | ✅ | ✅ |
| **Reference** |||||||
| `GET /api/reference/get.php` | `jwt` | 🔒 | ✅ | ✅ | ✅ | ✅ |
| **Outage reports (own)** |||||||
| `POST /api/outage_report/create.php` | `jwt` | 🔒 | ✅ | ✅ | ✅ | ✅ |
| `GET /api/outage_report/get.php` | `jwt` | 🔒 | ✅ | ✅ | ✅ | ✅ |
| `GET /api/outage_report/get_active.php` | `jwt` | 🔒 | ✅ | ✅ | ✅ | ✅ |
| `GET /api/outage_report/get_resolve.php` | `jwt` | 🔒 | ✅ | ✅ | ✅ | ✅ |
| `GET /api/outage_report/get_my_report.php` | `jwt` + `owner` | 🔒 | 👤 | 👤 | 👤 | 👤 |
| `GET /api/outage_report/get_detail.php` | `auth` + `owner-or-staff` | 🔒 | 👤 | ✅ | ✅ | ✅ |
| `POST /api/outage_report/update.php` | `jwt` + `owner` | 🔒 | 👤 | 👤 | 👤 | 👤 |
| `POST /api/outage_report/delete.php` | `jwt` + `owner` | 🔒 | 👤 | 👤 | 👤 | 👤 |
| `POST /api/outage_report/upload_image.php` | `auth` + `owner-or-staff` | 🔒 | 👤 | ✅ | ✅ | ✅ |
| **Outage field ops (staff)** |||||||
| `GET /api/outage/get.php` | `role` | 🔒 | 🔒 | ✅ | ✅ | ✅ |
| `POST /api/outage/verify.php` | `role` | 🔒 | 🔒 | ✅ | ✅ | ✅ |
| `POST /api/outage/add_update.php` | `role` | 🔒 | 🔒 | ✅ | ✅ | ✅ |
| **Outage mgmt (company)** |||||||
| `GET /api/outage_report_electric_com/get.php` | `role` | 🔒 | 🔒 | ✅ | ✅ | ✅ |
| `POST /api/outage_report_electric_com/update_single.php` | `role` | 🔒 | 🔒 | ✅ | ✅ | ✅ |
| `POST /api/outage_report_electric_com/update_barangay.php` | `role` | 🔒 | 🔒 | ✅ | ✅ | ✅ |
| `POST /api/outage_report_electric_com/update_dagupan.php` | `role` | 🔒 | 🔒 | ✅ | ✅ | ✅ |
| **Maintenance (company writes)** |||||||
| `POST /api/maintenance/create.php` | `role` | 🔒 | 🔒 | 🔒 | ✅ | ✅ |
| `POST /api/maintenance/update.php` | `role` *(no owner scope)* | 🔒 | 🔒 | 🔒 | ✅ | ✅ |
| `POST /api/maintenance/delete.php` | `role` + `owner` | 🔒 | 🔒 | 🔒 | ✅ | ✅ |
| `GET /api/maintenance/get_complete.php` | `role` | 🔒 | 🔒 | 🔒 | ✅ | ✅ |
| `GET /api/maintenance/get.php` | `jwt` | 🔒 | ✅ | ✅ | ✅ | ✅ |
| `GET /api/maintenance/get_upcoming.php` | `jwt` | 🔒 | ✅ | ✅ | ✅ | ✅ |
| `GET /api/maintenance_map/get.php` | `jwt` | 🔒 | ✅ | ✅ | ✅ | ✅ |
| **Power stations** |||||||
| `POST /api/power_station/create.php` | `jwt` | 🔒 | ✅ | ✅ | ✅ | ✅ |
| `GET /api/power_station/get.php` | `jwt` | 🔒 | ✅ | ✅ | ✅ | ✅ |
| `GET /api/power_station/get_available.php` | `jwt` | 🔒 | ✅ | ✅ | ✅ | ✅ |
| `GET /api/power_station/get_near_location.php` | `jwt` + self | 🔒 | 👤 | 👤 | 👤 | 👤 |
| `GET /api/power_station/get_my_posts.php` | `jwt` + `owner` | 🔒 | 👤 | 👤 | 👤 | 👤 |
| `POST /api/power_station/update.php` | `jwt` + `owner` | 🔒 | 👤 | 👤 | 👤 | 👤 |
| `POST /api/power_station/delete.php` | `jwt` + `owner` | 🔒 | 👤 | 👤 | 👤 | 👤 |
| **Notifications** |||||||
| `GET /api/notification/get.php` | `jwt` + `owner` | 🔒 | 👤 | 👤 | 👤 | 👤 |
| `POST /api/notification/mark_as_read.php` | `jwt` + `owner` | 🔒 | 👤 | 👤 | 👤 | 👤 |
| `POST /api/notification/mark_all_as_read.php` | `jwt` + `owner` | 🔒 | 👤 | 👤 | 👤 | 👤 |
| `POST /api/notification/create.php` | `role` | 🔒 | 🔒 | 🔒 | ✅ | ✅ |
| **User location** |||||||
| `GET /api/user_location/get.php` | `jwt` + self | 🔒 | 👤 | 👤 | 👤 | 👤 |
| `POST /api/user_location/location.php` | `jwt` + self | 🔒 | 👤 | 👤 | 👤 | 👤 |
| **Battery devices** |||||||
| `POST /api/battery/create.php` | `auth` | 🔒 | 👤 | 👤 | 👤 | 👤 |
| `GET /api/battery/get.php` | `auth` + `owner` | 🔒 | 👤 | 👤 | 👤 | 👤 |
| `GET /api/battery/get_history.php` | `auth` + `owner` | 🔒 | 👤 | 👤 | 👤 | 👤 |
| `POST /api/battery/update.php` | `auth` + `owner` | 🔒 | 👤 | 👤 | 👤 | 👤 |
| `POST /api/battery/set_percentage.php` | `auth` + `owner` | 🔒 | 👤 | 👤 | 👤 | 👤 |
| `POST /api/battery/log_usage.php` | `auth` + `owner` | 🔒 | 👤 | 👤 | 👤 | 👤 |
| `POST /api/battery/delete.php` | `auth` + `owner` | 🔒 | 👤 | 👤 | 👤 | 👤 |
| **Safety timers** |||||||
| `POST /api/safety_timer/create.php` | `auth` | 🔒 | 👤 | 👤 | 👤 | 👤 |
| `GET /api/safety_timer/get.php` | `auth` + `owner` | 🔒 | 👤 | 👤 | 👤 | 👤 |
| `POST /api/safety_timer/stop.php` | `auth` + `owner` | 🔒 | 👤 | 👤 | 👤 | 👤 |
| `POST /api/safety_timer/delete.php` | `auth` + `owner` | 🔒 | 👤 | 👤 | 👤 | 👤 |
| **Flood reports** |||||||
| `POST /api/flood_report/create.php` | `auth` | 🔒 | ✅ | ✅ | ✅ | ✅ |
| `GET /api/flood_report/get.php` | `auth` | 🔒 | ✅ | ✅ | ✅ | ✅ |
| `GET /api/flood_report/get_nearby.php` | `auth` | 🔒 | ✅ | ✅ | ✅ | ✅ |
| **Electrical hazards** |||||||
| `POST /api/electrical_hazard/create.php` | `auth` | 🔒 | ✅ | ✅ | ✅ | ✅ |
| `GET /api/electrical_hazard/get.php` | `auth` | 🔒 | ✅ | ✅ | ✅ | ✅ |
| `GET /api/electrical_hazard/get_nearby.php` | `auth` | 🔒 | ✅ | ✅ | ✅ | ✅ |
| `POST /api/electrical_hazard/update_status.php` | `auth` + `owner-or-staff` | 🔒 | 👤 | ✅ | ✅ | ✅ |
| **Risk / heatmap / clusters** |||||||
| `GET /api/risk/get_nearby.php` | `auth` | 🔒 | ✅ | ✅ | ✅ | ✅ |
| `GET /api/heatmap/get.php` | `auth` | 🔒 | ✅ | ✅ | ✅ | ✅ |
| `GET /api/cluster/get.php` | `auth` | 🔒 | ✅ | ✅ | ✅ | ✅ |
| `POST /api/cluster/store.php` | `role-lr` | 🔒 | 🔒 | ✅ | ✅ | ✅ |

> `api/services/*.php` (`create_notification`, `get_coordinates`, `lookup`) are
> **internal includes**, not HTTP endpoints — they are not meant to be called
> directly.

### Quick list per role

**Everyone (no login)** — `register.php`, `login.php`, `logout.php`,
`google.php`, `google_callback.php`

**`user`** — everything marked `👤` or `✅` in the table: report & view outage
reports (own detail only), power stations, maintenance viewing, notifications,
user location, battery tracking, safety timers, flood reports, electrical
hazards, risk areas, heatmap, cluster viewing, reference lookups, `me.php`.

**`lineman`** (all of `user` **plus**) — field operations:
`outage/get.php`, `outage/verify.php`, `outage/add_update.php`,
`outage_report_electric_com/*` (all 4), `cluster/store.php`, and owner-or-staff
access to `outage_report/get_detail.php`, `outage_report/upload_image.php`,
`electrical_hazard/update_status.php`.

**`electric_company`** (all of `lineman` **plus**) — maintenance management
(`maintenance/create.php`, `update.php`, `delete.php`, `get_complete.php`) and
broadcasting notifications (`notification/create.php`).

**`admin`** — the union of every row in the table.

> There is **no** user-management, role-assignment, or user-listing endpoint.
> Role changes must be made directly in the database (`users.role_id`). See
> [What this model does *not* do](#what-this-model-does-not-do) for the rest of
> the authorization gaps.

---

## Auth Endpoints

### `POST /api/auth/register.php`
Register a new local user and log them in (sets JWT cookie).
Body: `first_name`, `last_name`, `middle_name?`, `email`, `password` (min 6).

Also accepts an optional `role` — **see the warning under
[Roles](#roles)**; this is a privilege-escalation bug, not a feature. A client
can request any of the four roles. Omit it.

### `POST /api/auth/login.php`
Login with local credentials (sets JWT cookie).
Body: `email`, `password`.

### `POST /api/auth/logout.php`
Clears the JWT cookie.

### `GET /api/auth/me.php`
Returns the authenticated user's profile, including their `role`.

### `GET /api/auth/google.php`
Redirects the browser to Google's OAuth consent screen.

### `GET /api/auth/google_callback.php`
OAuth callback: exchanges the `code`, verifies the Google id_token, links or
creates the user (new Google users are always created as `user`), and sets the
JWT cookie.

**This endpoint never returns JSON.** It is a top-level browser navigation, so
every outcome is a `302` to the frontend — success to
`{FRONTEND_URL}/auth/google-callback?token={jwt}`, failure to
`{FRONTEND_URL}/login?error={code}`. The success redirect puts the JWT in a
query string, which lands in browser history and server logs; prefer the
cookie-based `login.php` path where possible. Failure codes are
`google_auth_denied`, `google_missing_code`, `google_exchange_failed`,
`google_invalid_token`, `google_role_missing`, `google_db_error`,
`google_jwt_failed`. Details are written to the PHP error log, not the response.

> Requires real `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` in `.env`
> (currently placeholders).

---

## Reference / Lookups

> **Roles:** any authenticated user.

### `GET /api/reference/get.php`
Returns all lookup tables in one call:
`roles`, `barangays`, `outage_categories`, `severity_levels`, `hazard_types`,
`outage_statuses`, `power_station_types`, `safety_timer_types`,
`notification_types`.

---

## Outage Reports (user-facing)

> **Roles:** any authenticated user. `get_detail` is owner-only for `user`
> (staff may open any report); `update` and `delete` are **owner-only for every
> role** — staff must use `/api/outage/*` or `/api/outage_report_electric_com/*`
> instead. `upload_image` is owner-or-staff.

### `POST /api/outage_report/create.php`
Create an outage report. Coordinates are geocoded (Geoapify) or matched to a
barangay. Enforces one active report per user.
Body: `location_name`, `description`, `category?`, `severity?`, `hazard_type?`,
`affected_houses?`, `barangay_name?`, `started_at?`, `image_url?`.
Out-of-coverage-area locations are rejected (`403`).

### `GET /api/outage_report/get.php`
List active (non-rejected) reports. Filters: `?status=`, `?category=`.

### `GET /api/outage_report/get_active.php`
Count of active reports. Filters: `?status=`, `?category=`, `?severity=`.
Returns `total_active_reports`.

### `GET /api/outage_report/get_resolve.php`
Returns `total_resolved` (count of resolved reports).

### `GET /api/outage_report/get_my_report.php`
List the authenticated user's own reports.

### `GET /api/outage_report/get_detail.php?id=`
Full report detail with `images`, `updates`, and `verifications`.
Regular users may only view their own; staff may view any.

### `POST /api/outage_report/update.php`
Update own report. Body: `id`, plus any of `location_name`, `description`,
`category`, `severity`, `hazard_type`, `affected_houses`, `barangay_name`,
`started_at`.

### `POST /api/outage_report/delete.php`
Cancel (soft-delete) own active report. Body: `id`. Sets status `rejected`,
`is_active = 0`.

### `POST /api/outage_report/upload_image.php`
Multipart upload. Fields: `image` (file, max 5 MB, jpg/png/gif/webp),
`outage_report_id`. Owner or staff only. Stores under `uploads/outage/` and
records in `outage_report_images`.

---

## Outage Field / Staff Operations

These endpoints require a **staff** role (`lineman`, `electric_company`, `admin`).

### `GET /api/outage/get.php`
List all reports for staff. Filters: `?status=`, `?category=`, `?severity=`,
`?barangay=`.

### `POST /api/outage/verify.php`
Verify a report and preserve the verification history. Body:
`outage_report_id`, `verification_status` (`confirmed` | `not_confirmed` |
`false_report`), `notes?`, `status?` (optional explicit target status).
Rule-based result: `confirmed → verified`, `false_report → rejected`,
`not_confirmed → under_review`.

### `POST /api/outage/add_update.php`
Add a field update (history) and optionally advance status. Body:
`outage_report_id`, `update_message`, `status?` (optional; `resolved` also sets
`is_active = 0`).

---

## Electric Company Outage Management

Require `electric_company`, `admin`, or `lineman`.

### `GET /api/outage_report_electric_com/get.php`
List reports. Filters: `?status=`, `?severity=`, `?active=` (0/1).

### `POST /api/outage_report_electric_com/update_single.php`
Update one report's status. Body: `id`, `status`
(`active`/`under_review`/`verified`/`resolved`/`rejected`).

### `POST /api/outage_report_electric_com/update_barangay.php`
Update status for all reports in a barangay. Body: `barangay`, `status`.

### `POST /api/outage_report_electric_com/update_dagupan.php`
Update status for all reports city-wide. Body: `status`.

---

## Maintenance Schedules

Create/update require `electric_company` or `admin`. List endpoints require any
authenticated user. Affected users are auto-notified based on their location
within the maintenance radius.

### `POST /api/maintenance/create.php`
Body: `maintenance_date` (`YYYY-MM-DD`), `start_time`, `end_time`,
`barangays` (array of names), `description?`, `radius?` (default 2000 m).
Returns `users_notified`.

### `POST /api/maintenance/update.php`
Body: `maintenance_id`, `maintenance_date`, `start_time`, `end_time`,
`barangays` (array), `description?`, `radius?`, `status?` (optional:
`upcoming`/`ongoing`/`completed`/`cancelled`). Status is otherwise computed from
the date/time.

> ⚠️ Unlike `delete.php`, this endpoint's `UPDATE` is **not** scoped by
> `created_by` — any `electric_company` or `admin` user can edit **any**
> maintenance schedule, not just the ones they created. Treat that as a bug;
> add `AND created_by = :user_id` to match the delete behaviour.

### `GET /api/maintenance/get.php`
List electric-company maintenance schedules with their affected `locations`.

### `GET /api/maintenance/get_upcoming.php`
Returns `upcoming_count` (schedules with status `upcoming`/`ongoing`).

### `GET /api/maintenance/get_complete.php`
Lists completed maintenance (`electric_company`/`admin` only).

### `POST /api/maintenance/delete.php`
Delete own maintenance (company/admin only). Body: `maintenance_id`. Cleans up
linked notifications, locations, and the schedule.

### `GET /api/maintenance_map/get.php`
List maintenance with coordinates for map display.
Filters: `?status=`, `?date=`.

---

## Power Stations

> **Roles:** any authenticated user may browse, post, and view their own posts.
> `update` and `delete` are **owner-only for every role**. `get_near_location`
> and `get_my_posts` are scoped to the caller.

### `POST /api/power_station/create.php`
Body: `station_name`, `location_name`, `station_type?` (`power_station` |
`solar_station` | `charging_station` | `generator_station`), `access_type?`
(`free`/`paid`), `availability_status?`, `operating_hours?`, `charging_type?`,
`description?`, `image?`, `latitude?`/`longitude?`, `barangay_name?`.

### `GET /api/power_station/get.php`
List stations (paginated). Query: `?page=`, `?limit=`.

### `GET /api/power_station/get_available.php`
Returns `total_available` count.

### `GET /api/power_station/get_my_posts.php`
List the authenticated user's own stations.

### `GET /api/power_station/get_near_location.php`
List available stations near the user's primary saved location, sorted by
distance. Query: `?radius=` (meters).

### `POST /api/power_station/update.php`
Update own station. Body: `id` + fields to change (same set as create).

### `POST /api/power_station/delete.php`
Delete own station. Body: `station_id`.

---

## Notifications

> **Roles:** `get`, `mark_as_read`, `mark_all_as_read` are own-notifications-only
> for any authenticated user. `create` is **`electric_company` / `admin` only**.

### `GET /api/notification/get.php`
List the user's notifications. Query: `?unread=1`, `?type=`, `?maintenance_id=`,
`?limit=`, `?offset=`.

### `POST /api/notification/mark_as_read.php`
Body: `notification_id`.

### `POST /api/notification/mark_all_as_read.php`
Marks all of the user's notifications read.

### `POST /api/notification/create.php`
Staff only (`electric_company`/`admin`). Create one or many notifications.
Either a single object `{user_id, title, message, type?}` or an array
`{notifications: [{user_id, title, message, type?}, ...]}`.

> Most notifications are created automatically by the system (e.g. maintenance
> schedules, safety-timer reminders, hazard verification).

---

## User Location

> **Roles:** own location only, any authenticated user.

### `GET /api/user_location/get.php`
Get the user's primary saved location.

### `POST /api/user_location/location.php`
Save/update the user's location. Body: `address` (or `location_name`),
`barangay_name`, `latitude`/`longitude` (optional; geocoded if omitted).

---

## Battery Devices

> **Roles:** own devices only, any authenticated user. Staff have no special
> access to other users' devices.

### `POST /api/battery/create.php`
Body: `device_name`, `device_type` (`phone`|`laptop`|`powerbank`|`ups`|`tablet`
|`other`), `capacity_mah?`, `current_percentage?` (0–100, default 100),
`is_primary?`. Only one device is `is_primary`.

### `GET /api/battery/get.php`
List the user's devices with `recent_logs`, `estimated_usage_rate_per_hour`,
and `estimated_hours_remaining` (simple %-per-hour budgeting).

### `POST /api/battery/update.php`
Update a device. Body: `device_id` + any of `device_name`, `device_type`,
`capacity_mah`, `current_percentage`, `is_primary`. Ownership enforced.

### `POST /api/battery/set_percentage.php`
Update just the current battery level. Body: `device_id`, `current_percentage`.
Ownership enforced.

### `POST /api/battery/log_usage.php`
Log a usage session. Body: `device_id`, `battery_percentage_start`,
`battery_percentage_end`, `usage_minutes?`, `estimated_watts?`, `activity?`.
Also updates the device's `current_percentage` to the end value.

### `GET /api/battery/get_history.php?device_id=`
List usage logs for a device (ownership enforced).

### `POST /api/battery/delete.php`
Body: `device_id`. Ownership enforced.

---

## Safety Timers

> **Roles:** own timers only, any authenticated user.

### `GET /api/reference/get.php`
Returns `safety_timer_types`:
`sealed_refrigerator` (4h / warn 1h), `deep_freezer_24h` (24h / 4h),
`deep_freezer_48h` (48h / 6h), `medication` (4h / 1h).

### `POST /api/safety_timer/create.php`
Start a timer for the current user. Provide either `timer_type_name` (one of the
types above) **or** `duration_hours` + `warning_hours_before`. Optional
`title`, `notes`, `started_at`.
Returns `timer_id`, `started_at`, `warning_at`, `expected_expiration_at`,
`status` (`running` initially).

### `GET /api/safety_timer/get.php`
List the user's timers with live-computed `status`
(`running`/`warning`/`expired`/`stopped`) and `remaining_seconds`. When a timer
enters `warning` or `expired`, a one-time alert is recorded and a notification
is created.

### `POST /api/safety_timer/stop.php`
Body: `timer_id`. Sets status `stopped`, `completed_at = NOW()`.

### `POST /api/safety_timer/delete.php`
Body: `timer_id`. Deletes own timer.

---

## Flood Reports

> **Roles:** any authenticated user. No staff-only variant; clearing is done via
> the electrical-hazard / outage workflows.

### `POST /api/flood_report/create.php`
Body: `location_name`, `flood_level` (`low`/`moderate`/`high`/`severe`),
`flood_depth_cm?`, `description?`, `barangay_name?`, `latitude?`/`longitude?`,
`image_url?`. Coordinates geocoded if not provided.

### `GET /api/flood_report/get.php`
List flood reports. Filters: `?status=`, `?flood_level=`, `?barangay=`.

### `GET /api/flood_report/get_nearby.php?lat=&lng=&radius=`
List active (non-cleared) floods within `radius` meters, sorted by distance.
Used for electrocution-risk display.

---

## Electrical Hazards

> **Roles:** create/list/nearby are open to any authenticated user;
> `update_status` is owner-only for `user` and open to all staff.

### `POST /api/electrical_hazard/create.php`
Body: `location_name`, `hazard_type` (see `hazard_types` lookup, e.g.
`submerged_electrical_equipment`, `fallen_wire`, `sparks`), `severity?`
(`low`/`moderate`/`high`/`critical`), `description?`, `barangay_name?`,
`latitude?`/`longitude?`, `image_url?`.

### `GET /api/electrical_hazard/get.php`
List hazards. Filters: `?status=`, `?severity=`, `?barangay=`.

### `GET /api/electrical_hazard/get_nearby.php?lat=&lng=&radius=`
List unresolved hazards within `radius` meters, sorted by distance.

### `POST /api/electrical_hazard/update_status.php`
Body: `hazard_id`, `status` (`reported`/`verified`/`resolved`). Owners may act
on their own; staff may act on any (`verified`/`resolved` also notifies the
reporter).

---

## Combined Risk Areas

> **Roles:** any authenticated user.

### `GET /api/risk/get_nearby.php?lat=&lng=&radius=`
Returns nearby active floods **and** unresolved electrical hazards together
(each tagged with `category`), the primary source for rendering potential
electrocution-risk areas on the map.

---

## Heatmap & Clustering

> **Roles:** `heatmap/get.php` and `cluster/get.php` are open to any
> authenticated user; `cluster/store.php` is **staff only**
> (`lineman` / `electric_company` / `admin`).

### `GET /api/heatmap/get.php`
Rule-based heatmap of active outage reports.
Query: `?mode=` (`by_barangay` default | `clusters`), `?radius=` (cluster
radius, default 1000 m), `?days=` (lookback window, default 7).
For each point/barangay it returns `report_count`, `affected_houses`,
`confidence_score` (verified / total × 100), `severity_score`, and
`forecast_level` (`low`/`moderate`/`high`/`critical`).

### `POST /api/cluster/store.php` (staff: lineman/company/admin)
Persist a computed cluster to `outage_clusters`. Body: `barangay_id`,
`latitude`, `longitude`, `radius_meters?`, `report_count?`,
`affected_houses?`, `confidence_score?`, `severity_score?`, `forecast_level?`,
`cluster_date?`, `report_ids` (array, linked into `outage_cluster_reports` with
their distance from the center).

### `GET /api/cluster/get.php`
List stored clusters. Filters: `?status=`, `?barangay=`, `?from_date=`,
`?include_reports=1`.

---

## Notes

- **Geocoding**: locations without explicit `latitude`/`longitude` are geocoded
  via Geoapify (`GEOAPIFY_GEOCODING_API_KEY` in `.env`).
- **Lookup tables**: the string values you send (`category`, `severity`,
  `hazard_type`, `station_type`, `status`, `timer_type_name`) are resolved to
  ids server-side; see `GET /api/reference/get.php` for the valid values.
- **Test data**: the `powerguide` database ships with seeded lookup tables and
  36 barangays. The `barangays` seed rows have `NULL` coordinates; use the
  barangay name (auto-created if needed) or pass coordinates explicitly.
- **Configuration** lives in `.env`: DB credentials, `JWT_SECRET_KEY`,
`GEOAPIFY_GEOCODING_API_KEY`, and Google OAuth credentials (placeholders —
fill in real values before enabling Google sign-in).
