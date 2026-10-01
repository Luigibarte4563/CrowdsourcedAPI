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
> store, lineman assignment, and staff notification endpoints are restricted to
> company/admin.
>
> A `lineman` is additionally **scoped to their assigned barangays**: every outage
> endpoint is limited to reports in a barangay actively assigned to them. See
> [Lineman Assignments](#lineman-assignments).

Role names are stored in the `roles` lookup table and carried in the JWT `role`
claim, so every role check is a string comparison against one of the four names
above. Roles are enforced server-side on every request — the frontend's copy of
the role is only used for hiding UI, never for access decisions.

Both sign-up paths create a `user`, always. `register.php` ignores any `role` in
the request body and `google_callback.php:141` hard-codes `user` for brand-new
Google users, so privileged roles can only be granted by writing
`users.role_id` directly.

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
| `assigned` | a `lineman_assignments` lookup from `auth/lineman_access.php` | the outage's barangay is actively assigned to this lineman |

`jwt`, `auth`, `owner` and `assigned` combine with a `+` in the matrix when a file
layers them on top of its guard. `jwt` and `auth` are functionally identical — both
require any valid JWT and neither checks a role. The distinction is only
stylistic: `jwt` predates `auth/rbac.php` and still hand-rolls its `401`. Treat
them as one tier.

`assigned` narrows a `lineman` only: `electric_company` and `admin` pass through
untouched, which is why their access to the same endpoints is unchanged.

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
- **No role-management endpoint.** There is no user creation by admin, and no
  generic role-assignment API. Roles are changed by writing to `users.role_id`
  directly, and that is now the only way to grant a role: `register.php` ignores
  any `role` in the request and always creates a `user` (see
  [Auth Endpoints](#post-apiauthregisterphp). The one exception is
  `lineman_assignment/linemen.php`, which lists only `role = 'lineman'` accounts
  (id, name, email) so the assignment UI has something to populate a picker with —
  it grants nothing.
- **No `SameSite` / `Secure` cookie attributes.** `issue_jwt.php:46` sets
  `httpOnly` but `secure = false` and emits no `SameSite`, relying on browser
  defaults — acceptable for localhost, not for production over HTTPS.
- **No rate limiting** on `login` or `register`, and no lockout after repeated
  failures.

---

## Endpoint Access by Role

Legend:
`✅` = allowed · `🔒` = blocked (403) · `👤` = only on resources **you** own
`📍` = allowed only for outage reports in a barangay **actively assigned** to that lineman (see [Lineman Assignments](#lineman-assignments))
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
| `GET /api/outage_report/get_active.php` | `jwt` + `assigned` | 🔒 | ✅ | 📍 | ✅ | ✅ |
| `GET /api/outage_report/get_resolve.php` | `jwt` + `assigned` | 🔒 | ✅ | 📍 | ✅ | ✅ |
| `GET /api/outage_report/get_my_report.php` | `jwt` + `owner` | 🔒 | 👤 | 👤 | 👤 | 👤 |
| `GET /api/outage_report/get_detail.php` | `auth` + `owner-or-staff` + `assigned` | 🔒 | 👤 | 📍 | ✅ | ✅ |
| `POST /api/outage_report/update.php` | `jwt` + `owner` | 🔒 | 👤 | 👤 | 👤 | 👤 |
| `POST /api/outage_report/delete.php` | `jwt` + `owner` | 🔒 | 👤 | 👤 | 👤 | 👤 |
| `POST /api/outage_report/upload_image.php` | `auth` + `owner-or-staff` | 🔒 | 👤 | ✅ | ✅ | ✅ |
| **Outage field ops (staff)** |||||||
| `GET /api/outage/get.php` | `role` + `assigned` | 🔒 | 🔒 | 📍 | ✅ | ✅ |
| `POST /api/outage/verify.php` | `role` + `assigned` | 🔒 | 🔒 | 📍 | ✅ | ✅ |
| `POST /api/outage/add_update.php` | `role` + `assigned` | 🔒 | 🔒 | 📍 | ✅ | ✅ |
| **Outage mgmt (company)** |||||||
| `GET /api/outage_report_electric_com/get.php` | `role` + `assigned` | 🔒 | 🔒 | 📍 | ✅ | ✅ |
| `POST /api/outage_report_electric_com/update_single.php` | `role` + `assigned` | 🔒 | 🔒 | 📍 | ✅ | ✅ |
| `POST /api/outage_report_electric_com/update_barangay.php` | `role` + `assigned` | 🔒 | 🔒 | 📍 | ✅ | ✅ |
| `POST /api/outage_report_electric_com/update_dagupan.php` | `role` *(manager only)* | 🔒 | 🔒 | 🔒 | ✅ | ✅ |
| **Lineman assignments** |||||||
| `GET /api/lineman_assignment/get.php` | `role` | 🔒 | 🔒 | 🔒 | ✅ | ✅ |
| `GET /api/lineman_assignment/linemen.php` | `role` | 🔒 | 🔒 | 🔒 | ✅ | ✅ |
| `POST /api/lineman_assignment/create.php` | `role` | 🔒 | 🔒 | 🔒 | ✅ | ✅ |
| `POST /api/lineman_assignment/update.php` | `role` | 🔒 | 🔒 | 🔒 | ✅ | ✅ |
| `POST /api/lineman_assignment/delete.php` | `role` | 🔒 | 🔒 | 🔒 | ✅ | ✅ |
| `GET /api/lineman_assignment/my.php` | `role` *(lineman only)* | 🔒 | 🔒 | ✅ | 🔒 | 🔒 |
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

**`lineman`** (all of `user` **plus**) — field operations, **each limited to
outage reports in a barangay actively assigned to that lineman**:
`outage/get.php`, `outage/verify.php`, `outage/add_update.php`,
`outage_report_electric_com/get.php`, `update_single.php`,
`update_barangay.php`, and owner-or-staff access to
`outage_report/get_detail.php`. Also `cluster/store.php`,
`outage_report/upload_image.php` and `electrical_hazard/update_status.php`
(these are not outage-scoped), plus `lineman_assignment/my.php` for their own
scope. `outage_report_electric_com/update_dagupan.php` is **not** available.

**`electric_company`** (all of `lineman`, **unscoped**, **plus**) — maintenance
management (`maintenance/create.php`, `update.php`, `delete.php`,
`get_complete.php`), broadcasting notifications (`notification/create.php`),
city-wide outage updates (`outage_report_electric_com/update_dagupan.php`), and
lineman assignment management (`lineman_assignment/get.php`, `linemen.php`,
`create.php`, `update.php`, `delete.php`).

**`admin`** — the union of every row in the table.

> There is **no** user-management or general role-assignment endpoint. Role changes
> must be made directly in the database (`users.role_id`); `register.php` always
> creates a `user`. `lineman_assignment/linemen.php` is the single user listing and
> is restricted to `role = 'lineman'` (id, name, email) for the assignment picker.
> See [What this model does *not* do](#what-this-model-does-not-do) for the rest of
> the authorization gaps.

---

## Auth Endpoints

### `POST /api/auth/register.php`
Register a new local user and log them in (sets JWT cookie).
Body: `first_name`, `last_name`, `middle_name?`, `email`, `password` (min 6).

**Always creates a `user`.** Any `role` in the body is ignored outright rather
than validated and honoured — this endpoint is unauthenticated, so honouring it
would let anyone `POST {"role":"admin"}` and mint themselves a privileged
account. `lineman`, `electric_company` and `admin` are granted only by an
administrator writing `users.role_id` directly.

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
Count of open reports. Filters: `?status=`, `?category=`, `?severity=`.
Returns `total_active_reports`.

The count is `status != 'rejected' AND is_active = 1` — i.e. every **open** report,
not only those whose status is literally `active`. It is therefore broader than
`electric_com/get.php?status=active` and the two numbers are not expected to match.
Scoped to the caller's assignments for a lineman, so it always agrees with the rows
`outage/get.php` returns them.

### `GET /api/outage_report/get_resolve.php`
Returns `total_resolved` (count of resolved reports). Scoped for a lineman, like
`get_active.php`.

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

> **Lineman scope.** A `lineman` only ever sees and acts on reports whose
> `barangay_id` has an **active** row in `lineman_assignments` for their user id.
> The restriction is a `WHERE` subquery, not a post-fetch filter, so an
> unauthorized row is never read at all. `electric_company` and `admin` are not
> narrowed. See [Lineman Assignments](#lineman-assignments).

### `GET /api/outage/get.php`
List all reports for staff. Filters: `?status=`, `?category=`, `?severity=`,
`?barangay=` (a name).

`?barangay=` cannot widen a lineman's scope: it is ANDed with the assignment
subquery, so naming an unassigned barangay returns `count: 0` rather than its
reports. For a lineman the response also carries `barangay_id` (not just
`barangay_name`), which is what the UI's assigned-efficacy filter uses.

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

Both single-record endpoints resolve the report and check its barangay **before**
writing anything, so a lineman refused by the assignment check leaves no row in
`outage_report_verifications` / `outage_report_updates` and cannot advance a
status. A missing report is `404`; a report outside the lineman's assigned
barangays is `403`.

---

## Electric Company Outage Management

Require `electric_company`, `admin`, or `lineman` — with a `lineman` limited to
their assigned barangays as above.

### `GET /api/outage_report_electric_com/get.php`
List reports. Filters: `?status=`, `?severity=`, `?active=` (0/1).

### `POST /api/outage_report_electric_com/update_single.php`
Update one report's status. Body: `id`, `status`
(`active`/`under_review`/`verified`/`resolved`/`rejected`). A lineman gets `403`
for a report outside their assigned barangays.

### `POST /api/outage_report_electric_com/update_barangay.php`
Update status for all reports in a barangay. Body: `barangay` (a **name**),
`status`.

For a lineman the name is resolved with a plain lookup and checked against their
assignments first: a `403` for an unassigned or unknown name, and — unlike for
company/admin — `resolveBarangay()` is **not** used, so a lineman request cannot
create a new `barangays` row.

### `POST /api/outage_report_electric_com/update_dagupan.php`
Update status for all reports city-wide. Body: `status`.

**`electric_company` and `admin` only** — a lineman gets `403`. This endpoint
issues one `UPDATE` with no `WHERE` clause, so there is no barangay to narrow it
to and no scoped version of it that would be meaningful.

---

## Lineman Assignments

Which `lineman` covers which `barangay`. Rows live in `lineman_assignments`
(migration: `database/002_lineman_assignments.sql`, also in the base schema) and
are **enforced by the backend** on every outage endpoint listed above.

Manage them with `electric_company` or `admin`; a `lineman` reads only their own.

`assigned_by` is always written from the JWT identity — never from the request
body — so it records who actually made the change. The target's role is re-read
from `roles` on every create and update, so a `user` who was promoted since an
assignment row was written cannot be left as a "lineman" assignment.

`UNIQUE(lineman_id, barangay_id)` means a pair has exactly one row: re-assigning
a deactivated pair **reactivates that row** instead of inserting a duplicate.

### `GET /api/lineman_assignment/get.php`
List assignments. Require `electric_company` or `admin`.

Query: `?lineman_id=`, `?barangay_id=`, `?status=` (`active` | `inactive`).
Each is validated; an invalid value is a `400` rather than a silently ignored
"return everything".

```json
{ "success": true, "message": "...", "count": 1, "data": [
  { "id": 9, "lineman_id": 13, "lineman_name": "Lineman Line",
    "lineman_email": "lineman@gmail.com", "barangay_id": 27,
    "barangay_name": "Pantal", "assigned_by": 37,
    "assigned_by_name": "Test Company", "assigned_at": "2026-10-01 11:29:53",
    "updated_at": "2026-10-01 11:29:53", "status": "active" }
] }
```

Only name and email are exposed for users — never a password hash, `google_id`
or `refresh_token`.

### `POST /api/lineman_assignment/create.php`
Assign a lineman to a barangay. Body: `lineman_id`, `barangay_id` (both positive
integers).

| Status | Meaning |
|---|---|
| `201` | created, or an inactive pair was reactivated |
| `400` | id not a positive integer, user/barangay missing, or the target's role is not `lineman` |
| `403` | caller is not `electric_company` / `admin` |
| `409` | the pair is already active |

Re-activating returns the **same `id`** with `"Assignment reactivated"`, so
history is preserved and no duplicate row can appear.

### `POST /api/lineman_assignment/update.php`
Edit an assignment. Body: `id` (required), and any of `lineman_id`,
`barangay_id`, `status`.

**Partial update** — omitted fields keep their stored value, so
`{ "id": 9, "status": "inactive" }` is a valid status-only edit. `assigned_by`
is rewritten to the authenticated caller and cannot be set from the client.

`400` invalid value · `403` wrong role · `404` unknown `id` · `409` the move
lands on a pair this lineman already holds (no duplicate is created).

### `POST /api/lineman_assignment/delete.php`
Deactivate an assignment. Body: `id`.

Sets `status = 'inactive'` rather than deleting the row, which keeps the record
of who was assigned and when, and lets a later re-assignment reactivate the same
row. Access is revoked **immediately** — a lineman's next request is already
scoped out. `404` if the id does not exist; an already-inactive row is a `200`
(idempotent).

### `GET /api/lineman_assignment/my.php`
The authenticated lineman's own **active** assignments. Require `lineman`.

There is deliberately **no id parameter**: the identity comes from the JWT alone,
so a lineman cannot ask for anybody else's assignments — the question cannot be
posed. `assigned_by` and email are not returned.

```json
{ "success": true, "message": "Your assigned barangays", "count": 1, "data": [
  { "id": 9, "barangay_id": 27, "barangay_name": "Pantal", "status": "active" }
] }
```

### `GET /api/lineman_assignment/linemen.php`
List assignable linemen for the UI picker. Require `electric_company` or `admin`.

Returns only users whose role is `lineman`, and only `id`, `name`, `email`.
This is the only user listing in the API and exists solely because nothing else
could populate a picker.

```json
{ "success": true, "message": "...", "count": 1,
  "data": [ { "id": 13, "name": "Lineman Line", "email": "lineman@gmail.com" } ] }
```

### Status codes used by these endpoints

`200` ok · `201` created · `400` invalid input or non-lineman target ·
`401` not authenticated · `403` wrong role · `404` assignment not found ·
`409` duplicate assignment · `500` server error.

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
