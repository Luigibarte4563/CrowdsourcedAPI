# PowerGuide Dagupan — Frontend Pseudocode Specification

Frontend blueprint derived **only** from the existing backend
(`CrowdsourcedAPI`, REST JSON API). No backend changes are implied or required.

This document describes every page, navigation flow, user action, form field,
API call, displayed data, and success/error handling the future frontend must
implement, in pseudocode form.

---

## 0. Global Frontend Contract (pseudocode)

### 0.1 API base

```
API_BASE = "http://localhost/CrowdsourcedAPI"
API(url) -> API_BASE + "/api" + url
```

### 0.2 Response envelope

Every endpoint returns JSON:

```
{
  "success": true|false,
  "message": "human readable string",
  ...feature_data   // varies per endpoint
}
```

### 0.3 Authentication transport (IMPORTANT)

- The backend issues a JWT stored in an **HttpOnly cookie** named `jwt_token`
  (set on register / login / google_callback). Frontend JS **cannot read or
  write** this cookie; the browser sends it automatically with every request.
- Frontend never stores tokens; auth state is derived from `GET /api/auth/me.php`.
- Requests must use `credentials: "include"` (or equivalent) so the cookie is
  forwarded.

### 0.4 HTTP status codes to handle globally

```
400 Bad Request        -> field validation error (map message to form errors)
401 Unauthorized       -> no/invalid/expired JWT -> force login screen
403 Forbidden          -> insufficient role OR business rule block (e.g. "already
                          have an active report", "outside coverage area")
404 Not Found          -> record missing
409 Conflict           -> duplicate (registry collision: email / active report /
                          maintenance date+barangay)
500 Server Error       -> generic failure -> show "Try again later"
```

Global error handler pseudocode:

```
function handleApiError(response):
    if response.status == 401:
        clearAuthState()
        redirectTo(LOGIN)
        showToast("Session expired. Please sign in again.")
    else if response.status in [400, 403, 404, 409]:
        showInlineError(response.message)   // show under the triggering form/action
    else:
        showToast("Something went wrong. Please try again.")

function handleApiSuccess(data):
    strip "success/message" from envelope, return the feature payload
```

### 0.5 Roles (server-assigned, reflected by `me.php`)

```
role = "user" | "lineman" | "electric_company" | "admin"
staff   = role in [lineman, electric_company, admin]
company = role in [electric_company, admin]
```

- The frontend NEVER sends a `role` value to create/select a role. New accounts
  always get `user`; role changes are out of scope for the frontend.

### 0.6 App-level state (single source of truth)

```
AppState:
  auth: { user: null | UserRecord, role: string, loaded: boolean }
  lookups: { roles, barangays, outage_categories, severity_levels,
             hazard_types, outage_statuses, power_station_types,
             safety_timer_types, notification_types }
  notificationsUnread: int
  currentLocation: { address, barangay, lat, lng }
  ui: { activeRoute, loader: bool, toasts: [] }

Behavior:
  - on startup: bootstrap()  (see 2.4)
  - any route change: runRouteGuard(route)  (see 6)
```

### 0.7 Common UI primitives (components referenced below)

```
- Toast(message, kind=success|error|info)
- ConfirmDialog(message) -> bool
- BottomNav / SideNav (role-filtered)
- MapView(center, markers, radius)   // renders lat/lng markers + circles
- ListCard / DetailScreen / FormScreen / StatCard / ProgressBar
- EmptyState(text), SkeletonLoader()
- ImagePicker(maxSizeMB=5)           // client-side pre-check jpg/png/gif/webp
- AutoComplete(source=barangays)     // barangay name picker
- GeoInput(label) -> resolves to address string (backend geocodes it)
- CountdownClock(secondsRemaining)
```

---

## 1. Reference / Lookups

Backend Feature
↓
Frontend Page: **No dedicated page** — loaded once during app bootstrap into
`AppState.lookups`; used by every form (dropdowns) and filter bar.
↓
User Action: App start; or "pull to refresh" reference data.
↓
API Request:

```
GET /api/reference/get.php
(cookie jwt_token auto-sent)
```

↓
API Response:

```
{
  "success": true,
  "data": {
    "roles":              [{id, role_name, description}, ...],
    "barangays":          [{id, barangay_name, city, province, latitude, longitude}, ...],
    "outage_categories":  [{id, category_name, description}, ...],
    "severity_levels":    [{id, severity_name, priority}, ...],
    "hazard_types":       [{id, hazard_name, description}, ...],
    "outage_statuses":    [{id, status_name, description}, ...],
    "power_station_types":[{id, type_name}, ...],
    "safety_timer_types": [{id, timer_name, default_duration_hours, warning_hours_before, description}, ...],
    "notification_types": [{id, type_name}, ...]
  }
}
```

↓
Frontend State Update:

```
AppState.lookups = response.data
markReferenceLoaded()
```

↓
UI Update: All dropdowns / chips / map filters become populated. Seed values
used by the UI: outage categories (`power_outage`, `low_voltage`, ...),
severities (`minor`, `moderate`, `critical`), hazard types, statuses
(`active`, `under_review`, `verified`, `resolved`, `rejected`), all 36
barangay names, timer types, notification types.

Errors: 401 → login redirect; 500 → toast; retry button on failed load.

---

## 2. Authentication

### 2.1 Register (local)

Backend Feature: `POST /api/auth/register.php`
↓
Frontend Page: **Register**
↓
User Action: Fill form and submit.
↓
Form fields:

```
first_name   (text, required)
middle_name  (text, optional)
last_name    (text, required)
email        (email, required, must be unique)
password     (password, min 6 chars)
confirm_password (client-side match check only)
```

No role selector is rendered (roles are never client-chosen).
↓
API Request:

```
POST /api/auth/register.php  body(JSON):
{
  "first_name": "...", "middle_name": "..."|null,
  "last_name": "...", "email": "...", "password": "......"
}
```

↓
API Response success:

```
{ "success": true, "message": "Registration successful",
  "user_id": 12, "token_issued": true }
```

(JWT cookie is set by the server; `token_issued` is informational.)

↓
Frontend State Update:

```
call me() to load profile+role   // see 2.4
AppState.auth.user = profile
```

↓
UI Update: Navigate to **Dashboard** (role-filtered). Show success toast
"Account created. Welcome!".

Errors: 400 → inline errors per field (missing email, invalid email, password
too short). 409 → "Email already registered" inline on the email field.
401/403/404/500 → global handler + keep form values.

### 2.2 Login (local)

Backend Feature: `POST /api/auth/login.php`
↓
Frontend Page: **Login**
↓
User Action: Enter credentials → submit.
↓
Form fields:

```
email    (email, required)
password (password, required)
```

↓
API Request:

```
POST /api/auth/login.php  body(JSON): { "email": "...", "password": "..." }
```

↓
API Response success:

```
{ "success": true, "message": "Login successful", "user_id": 12, "token_issued": true }
```

API Response failure (401):

```
{ "success": false, "message": "Invalid credentials" }
```

↓
Frontend State Update:

```
call me() to load profile+role
AppState.auth.user = profile
```

↓
UI Update: Navigate to **Dashboard**. Toasts: success "Logged in" / error
"Invalid credentials".

### 2.3 Google OAuth

Backend Feature: `GET /api/auth/google.php` + `GET /api/auth/google_callback.php`
↓
Frontend Page: **Login/Register** (button "Continue with Google")
↓
User Action: Click Google button.
↓
Flow (browser redirect, NOT ajax):

```
1. window.location = API("/api/auth/google.php")
   // server 302-redirects to Google consent screen
2. User approves outside the app.
3. Google redirects to /api/auth/google_callback.php?code=...
   // server exchanges code, verifies id_token (JWKS + audience),
   // links-or-creates user (always 'user' role), sets jwt_token cookie,
   // returns JSON.
4. Frontend handles two cases:
   a. If callback returns JSON on a page the SPA controls ->
      read {success, user_id, email, name}, then call me() and go to Dashboard.
   b. Simpler: point a dedicated route ("/oauth/callback") at the backend URL;
      on mount, call me(); if authenticated -> Dashboard.
```

↓
API Response (callback):

```
success: { "success": true, "message": "Google sign-in successful",
           "user_id": 12, "token_issued": true, "email": "x@y.com",
           "name": "First Last" }
error  : { "success": false, "message": "Google sign-in was cancelled or failed: ..." }
```

↓
UI Update: Dashboard after success; toast with the server message on failure
(user opt-out still lands on Login with reason).

### 2.4 Session restore (`me`)

Backend Feature: `GET /api/auth/me.php`
↓
Frontend Page: **App bootstrap / any protected route mount**
↓
User Action: (automatic)
↓
API Request:

```
GET /api/auth/me.php   (cookie sent)
```

↓
API Response success:

```
{ "success": true, "data": {
    "id": 12, "google_id": null, "first_name": "A", "middle_name": null,
    "last_name": "B", "email": "a@b.com", "picture": null,
    "auth_provider": "local", "role": "user", "role_id": 3,
    "created_at": "2026-.." } }
```

401 → no session.
↓
Frontend State Update:

```
if success:
    AppState.auth.user = data
    AppState.auth.role = data.role
    preloadNotifications()          // see 8
    preloadUserLocation()           // see 11
else if 401:
    AppState.auth.user = null       // show Login
```

↓
UI Update: Splash/loader while `loaded == false`. Then render either
Authenticated shell (role-filtered nav) or Login.

### 2.5 Logout

Backend Feature: `POST /api/auth/logout.php`
↓
Frontend Page: **App shell** (any) — confirmation dialog
↓
User Action: Tap "Logout".
↓
API Request:

```
POST /api/auth/logout.php   (server clears jwt_token cookie)
```

↓
API Response:

```
{ "success": true, "message": "Logged out" }
```

↓
Frontend State Update:

```
AppState.auth.user = null
reset every feature store
```

↓
UI Update: Navigate to **Login**. Toast "Logged out".

---

## 3. Outage Reports (Resident / User-facing)

### 3.1 Public outage feed + filters

Backend Feature: `GET /api/outage_report/get.php`
↓
Frontend Page: **Outage Map & Feed** (resident home-screen tab)
↓
User Action: Open page; change filters; tap a report card; pull-to-refresh.
↓
Filters:

```
status   (from outage_statuses, excludes 'rejected' server-side)
category (from outage_categories)
```

↓
API Request:

```
GET /api/outage_report/get.php?status=active&category=power_outage
```

↓
API Response success:

```
{ "success": true, "count": n, "data": [
    { "id": 1, "report_key": "OR-..", "location_name": "...",
      "latitude": 16.04, "longitude": 120.33, "description": "...",
      "affected_houses": 5, "is_active": 1, "started_at": "...", "resolved_at": null,
      "resolution_note": null, "created_at": "...", "updated_at": "...",
      "barangay_id": 7, "barangay_name": "Lucao",
      "category": "power_outage", "severity": "critical",
      "hazard_type": "fallen_wire", "status": "active" }, ... ] }
```

↓
Frontend State Update:

```
store.reports = data
filterParams = { status, category }
```

↓
UI Update: MapView markers (lat/lng, color by severity) + list of report cards
showing barangay, location, severity chip, status chip, affected houses,
reported time. EmptyState when `count == 0`. Skeleton while loading.

### 3.2 Active / resolved counters (dashboard stats)

Backend Feature: `GET /api/outage_report/get_active.php` + `GET /api/outage_report/get_resolve.php`
↓
Frontend Page: **Dashboard** (stats row)
↓
User Action: (automatic on dashboard load)
↓
API Request (parallel):

```
GET /api/outage_report/get_active.php?status=&category=&severity=
GET /api/outage_report/get_resolve.php
```

↓
API Response:

```
{ "success": true, "message": "...", "total_active_reports": 12 }
{ "success": true, "message": "...", "total_resolved": 340 }
```

↓
Frontend State Update:

```
store.stats.active = total_active_reports
store.stats.resolved = total_resolved
```

↓
UI Update: StatCard "Active outages" / "Resolved". Clicking navigates to the
Outage feed pre-filtered.

### 3.3 Create an outage report

Backend Feature: `POST /api/outage_report/create.php`
↓
Frontend Page: **Report Outage** (form, entry from FAB on Outage tab)
↓
User Action: Complete form and submit.
↓
Form fields:

```
location_name   (text, required -> geocoded by server; coverage-checked)
description     (textarea, required)
category        (select from outage_categories, required)
severity        (select: minor | moderate | critical, required)
hazard_type     (select from hazard_types, required)
affected_houses (number, min 1, default 1)
barangay_name   (autocomplete from barangays, optional; else server matches geo)
started_at      (datetime, optional)
image_url       (optional; set AFTER upload via 3.5 if user picked a photo)
```

↓
API Request:

```
POST /api/outage_report/create.php  body(JSON):
{ "location_name": "...", "description": "...", "category": "power_outage",
  "severity": "critical", "hazard_type": "fallen_wire",
  "affected_houses": 5, "barangay_name": "Lucao" | null, "started_at": "...",
  "image_url": "uploads/outage/..." | null }
```

↓
API Response:

```
success: { "success": true, "message": "Report created",
           "report_id": 42, "barangay": "Lucao" }
error 403: { "success": false, "message": "You already have an active report" }
error 403: { "success": false, "message": "Outside coverage area", "debug": {...} }
error 404: { "success": false, "message": "Unable to resolve location coordinates" }
error 400: { "success": false, "message": "Invalid lookup value" }
```

↓
Frontend State Update:

```
optimisticAdd(report from form)  OR  store.myReports.unshift(report_id=42)
refresh stats (active count +1 on success)
```

↓
UI Update: Navigate to **My Report** detail (3.6 shows the new report) with
success toast. Anti-spam interaction: `if 403 "already have an active report":
  show banner "You already have a pending report" + button "View my report" ->
  navigate to My Report screen.
```
Note: because an image upload requires an existing report id, the UX should
let the user create the report first, then attach photos on the detail screen.

### 3.4 Update own report

Backend Feature: `POST /api/outage_report/update.php`
↓
Frontend Page: **My Report → Edit** (owner only)
↓
User Action: Edit fields and save.
↓
Form fields: same set as 3.3 (`id` + any subset).
↓
API Request:

```
POST /api/outage_report/update.php  body(JSON):
{ "id": 42, "location_name": "...", "description": "...", "category": "...",
  "severity": "...", "hazard_type": "...", "affected_houses": 8,
  "barangay_name": "...", "started_at": "..." }
```

↓
API Response:

```
success: { "success": true, "message": "Report updated successfully" }
error 400: { "success": false, "message": "Invalid location (geocoding failed)" }
```

↓
Frontend State Update:

```
store.myReports replace item(id)
```

↓
UI Update: Return to **My Report** detail, updated data rendered, success toast.

### 3.5 Upload report photo

Backend Feature: `POST /api/outage_report/upload_image.php`
↓
Frontend Page: **My Report detail** (owner or staff) — "Add photo"
↓
User Action: Pick image (client pre-check jpg/png/gif/webp ≤ 5 MB), upload.
↓
API Request (multipart/form-data):

```
POST /api/outage_report/upload_image.php
   form: image=<file>, outage_report_id=42
```

↓
API Response:

```
success: { "success": true, "message": "Image uploaded",
           "image_id": 9, "image_url": "uploads/outage/or_42_....jpg" }
error 400: too large / invalid type / invalid content
error 403: forbidden (not owner, not staff)
```

↓
Frontend State Update:

```
store.detail.images.push({image_id, image_url, uploaded_by, created_at})
```

↓
UI Update: New image thumbnail appended to the photo gallery on the detail page.

### 3.6 My report detail

Backend Feature: `GET /api/outage_report/get_detail.php?id=`
↓
Frontend Page: **My Report** (owner) / **Staff report detail** (staff)
↓
User Action: Open from "My report" list / feed / staff queue; tap "Add photo";
(owner) tap "Edit", "Cancel report"; (staff) verification actions (see 4.2).
↓
API Request:

```
GET /api/outage_report/get_detail.php?id=42
```

↓
API Response:

```
{ "success": true, "data": {
    ...report fields (+ user_id, report_key, resolution_note) ...,
    "status": "...", "barangay_name": "...",
    "images": [{id, uploaded_by, image_url, created_at} ...],
    "updates": [{id, update_message, created_at, to_status} ...],
    "verifications": [{id, verified_by, verification_status, notes, verified_at} ...]
} }
```

↓
Frontend State Update:

```
store.detail = data
```

↓
UI Update: Timeline showing: report created → verifications (confirmed /
not confirmed / false report) → field updates (as status transition log) →
resolved (with `resolved_at` + `resolution_note`). Photo gallery from
`images`. Owner actions (Edit, Cancel) and photo upload only if the report is
in `active`/`under_review`/`verified` state. Staff actions per 4.2.

### 3.7 My reports list

Backend Feature: `GET /api/outage_report/get_my_report.php`
↓
Frontend Page: **My Report** list (one-active-report page; entry point for owner)
↓
User Action: Open page; tap a report; tap "Report new outage".
↓
API Request:

```
GET /api/outage_report/get_my_report.php
```

↓
API Response:

```
same shape as 3.1, but scoped to the authenticated user, excludes 'rejected'
```

↓
UI Update: Card list with status chip; the top card usually shows the single
active report (anti-spam). If an active/under_review/verified report exists,
the "Report new outage" action is disabled with hint text.

### 3.8 Cancel own report (soft delete)

Backend Feature: `POST /api/outage_report/delete.php`
↓
Frontend Page: **My Report detail** → "Cancel report"
↓
User Action: Confirm dialog → submit.
↓
API Request:

```
POST /api/outage_report/delete.php  body: { "id": 42 }
```

↓
API Response:

```
success: { "success": true, "message": "Report cancelled successfully" }
error 404: { "success": false, "message": "Report not found or already processed" }
```

↓
Frontend State Update:

```
store.myReports remove(id)  // is_active=0, status=rejected server-side
```

↓
UI Update: Navigate back to **My Report** list (card removed / shown as
cancelled in history), toast "Report cancelled". This frees the anti-spam
slot so the user can file a new report.

---

## 4. Outage Field / Staff Operations

### 4.1 Staff report queue

Backend Feature: `GET /api/outage/get.php` (staff only)
↓
Frontend Page: **Staff / Field Queue** (lineman, electric_company, admin)
↓
User Action: Open page; filter; open report detail.
↓
Filters: `status`, `category`, `severity`, `barangay`.
↓
API Request:

```
GET /api/outage/get.php?status=active&barangay=Lucao
```

↓
API Response:

```
same shape as 3.1 but includes user_id and ALL statuses (incl. rejected)
```

↓
UI Update: Prioritized list (by severity/status) with a badge for
un-verified reports; "Verify" and "Add update" actions on each (4.2 / 4.3).

### 4.2 Verify report

Backend Feature: `POST /api/outage/verify.php` (staff only)
↓
Frontend Page: **Staff report detail**
↓
User Action: Choose verification verdict, optional notes, submit. Optional
explicit status selector shown as advanced control.
↓
Form fields:

```
verification_status (select: confirmed | not_confirmed | false_report, required)
notes              (textarea, optional)
status             (optional advanced select: active|under_review|verified|resolved|rejected)
```

↓
API Request:

```
POST /api/outage/verify.php  body(JSON):
{ "outage_report_id": 42, "verification_status": "confirmed",
  "notes": "Confirmed by lineman on-site", "status": null }
```

↓
API Response (rule-based result returned):

```
{ "success": true, "message": "Report verified",
  "verification_status": "confirmed", "status": "verified" }
```

Rules to show the user as hint text:
`confirmed → verified` · `false_report → rejected` · `not_confirmed → under_review`.

↓
Frontend State Update:

```
store.detail.status = status
store.detail.verifications.push(new entry)   // optimistic or refetch detail
```

↓
UI Update: Status chip updates; verification history timeline gains an entry;
toast "Report verified". If resolved was chosen: report disappears from the
active queue (is_active=0).

### 4.3 Add field update / advance status

Backend Feature: `POST /api/outage/add_update.php` (staff only)
↓
Frontend Page: **Staff report detail** → "Add field update"
↓
Form fields:

```
update_message (textarea, required)
status         (optional select: active|under_review|verified|resolved|rejected)
```

↓
API Request:

```
POST /api/outage/add_update.php  body(JSON):
{ "outage_report_id": 42, "update_message": "Crew dispatched, transformer fixed",
  "status": "resolved" }
```

↓
API Response:

```
{ "success": true, "message": "Field update recorded", "status": "resolved" }
```

↓
Frontend State Update:

```
store.detail.updates.push(new entry)
store.detail.status = status (if provided)
```

↓
UI Update: Timeline gains the update with its `to_status`; if resolved,
`is_active=0` so the report leaves active views; toast success.

---

## 5. Electric Company Outage Management

### 5.1 Company report list

Backend Feature: `GET /api/outage_report_electric_com/get.php`
(via `outage_report/get_detail.php` for the full detail)
↓
Frontend Page: **Company Control Center** (electric_company, admin)
↓
User Action: Filter list; select scope then change status.
↓
Filters: `status`, `severity`, `active` (0/1).
↓
API Request:

```
GET /api/outage_report_electric_com/get.php?status=active&active=1
```

↓
API Response: same report shape as 4.1 (`count`, `data`).
↓
UI Update: Table/list with multi-select, plus three bulk-action panels (below).

### 5.2 Update single report

Backend Feature: `POST /api/outage_report_electric_com/update_single.php`
↓
Frontend Page: **Company Control Center** → row action
↓
Form fields: `status` (select: active|under_review|verified|resolved|rejected).
↓
API Request:

```
POST .../update_single.php  body: { "id": 42, "status": "resolved" }
```

↓
API Response:

```
{ "success": true, "message": "Updated successfully" }
```

↓
UI Update: Chip updates; resolving removes report from active lists.

### 5.3 Bulk: update by barangay

Backend Feature: `POST /api/outage_report_electric_com/update_barangay.php`
↓
Frontend Page: **Company Control Center** → "Bulk: by barangay"
↓
Form fields: `barangay` (autocomplete), `status` (select).
↓
API Request:

```
POST .../update_barangay.php  body: { "barangay": "Lucao", "status": "resolved" }
```

↓
API Response:

```
{ "success": true, "message": "Barangay updated successfully", "affected": 14 }
```

↓
UI Update: Confirmation dialog BEFORE sending: "This will change status for all
reports in Lucao." After success: toast "14 report(s) updated" + refetch list.

### 5.4 Bulk: whole city

Backend Feature: `POST /api/outage_report_electric_com/update_dagupan.php`
↓
Frontend Page: **Company Control Center** → "Bulk: whole Dagupan"
↓
Form fields: `status` (select).
↓
API Request:

```
POST .../update_dagupan.php  body: { "status": "resolved" }
```

↓
API Response:

```
{ "success": true, "message": "Dagupan updated successfully", "affected": 99 }
```

↓
UI Update: Strong confirm dialog ("blackout cleared city-wide?") → toast +
refetch.

---

## 6. Maintenance Schedules (Company)

### 6.1 Maintenance list (all authenticated users)

Backend Feature: `GET /api/maintenance/get.php`
↓
Frontend Page: **Maintenance** tab (residents view announcements) /
**Company Maintenance** (company manages)
↓
User Action: (view) open schedule detail; (company) create / edit / delete.
↓
API Request:

```
GET /api/maintenance/get.php
```

↓
API Response:

```
{ "success": true, "total": n, "data": [
    { "id": 1, "company_name": "First Last", "radius": 2000,
      "maintenance_date": "2026-09-20", "start_time": "08:00:00",
      "end_time": "17:00:00", "description": "...", "status": "upcoming",
      "locations": [ { "barangay_name": "Lucao", "lat": 16.0435, "lng": 120.3310 }, ... ],
      "created_at": "..." }, ... ] }
```

↓
UI Update: List cards grouped by status (upcoming / ongoing / completed /
cancelled) with affected barangays + datetime range; company sees edit/delete
buttons; residents see "notify me if affected" style info (auto handled backend).

### 6.2 Upcoming count (dashboard)

Backend Feature: `GET /api/maintenance/get_upcoming.php`
↓
Frontend Page: **Dashboard** stats row
↓
API Request:

```
GET /api/maintenance/get_upcoming.php
```

↓
API Response:

```
{ "success": true, "upcoming_count": 3, "current_date": "2026-09-19",
  "current_time": "14:30:00" }
```

↓
UI Update: StatCard "Scheduled interruptions". Tap → Maintenance page.

### 6.3 Create maintenance (company/admin)

Backend Feature: `POST /api/maintenance/create.php`
↓
Frontend Page: **Company Maintenance → New Schedule**
↓
Form fields:

```
maintenance_date (date, required)
start_time       (time, required)
end_time         (time, required)
barangays        (multi-select from barangays, required, min 1)
description      (textarea, optional)
radius           (number, meters, default 2000)
```

↓
API Request:

```
POST /api/maintenance/create.php  body(JSON):
{ "maintenance_date": "2026-09-20", "start_time": "08:00:00",
  "end_time": "17:00:00", "barangays": ["Lucao","Pantal"],
  "description": "Line upgrade", "radius": 2000 }
```

↓
API Response:

```
success: { "success": true, "message": "Maintenance created successfully",
           "maintenance_id": 5, "barangays": [...], "users_notified": 148 }
error 409: { "success": false, "message": "Maintenance already exists for: Lucao" }
```

↓
Frontend State Update:

```
store.maintenance.unshift(newSchedule)
store.stats.upcoming = store.stats.upcoming + 1
```

↓
UI Update: Toast "Created — 148 residents notified". Conflicting barangays
show as inline error on the barangay field.

### 6.4 Update maintenance (company/admin)

Backend Feature: `POST /api/maintenance/update.php`
↓
Frontend Page: **Company Maintenance → Edit**
↓
Form fields: same as 6.3 + optional `status` override
(upcoming|ongoing|completed|cancelled).
↓
API Request:

```
POST /api/maintenance/update.php  body(JSON):
{ "maintenance_id": 5, "maintenance_date": "...", "start_time": "...",
  "end_time": "...", "barangays": [...], "description": "...", "radius": 1000,
  "status": "cancel" -> "cancelled" }
```

↓
API Response:

```
{ "success": true, "message": "Maintenance updated successfully",
  "maintenance_id": 5, "status": "cancelled", "barangays": [...],
  "users_notified": 96 }
```

↓
UI Update: Row refreshes with new status/datetime; toast includes
`users_notified` "96 residents re-notified".

### 6.5 Delete maintenance (company/admin, own record)

Backend Feature: `POST /api/maintenance/delete.php`
↓
Frontend Page: **Company Maintenance** → row delete
↓
User Action: Confirm dialog ("This also deletes linked notifications").
↓
API Request:

```
POST /api/maintenance/delete.php  body: { "maintenance_id": 5 }
```

↓
API Response:

```
{ "success": true, "message": "Maintenance deleted successfully", "maintenance_id": 5 }
error: { "success": false, "message": "Maintenance not found or unauthorized" }
```

↓
Frontend State Update:

```
store.maintenance.remove(id)
store.stats.upcoming = max(0, store.stats.upcoming - 1)
```

↓
UI Update: Row removed, toast.

### 6.6 Maintenance map overlay

Backend Feature: `GET /api/maintenance_map/get.php`
↓
Frontend Page: **Map** (underlying map layer toggle "Scheduled maintenance")
↓
Filters: `status`, `date`.
↓
API Request:

```
GET /api/maintenance_map/get.php?status=upcoming
```

↓
API Response:

```
{ "success": true, "message": "...", "total": n,
  "data": [ { id, created_by, company_name, radius, maintenance_date,
              start_time, end_time, description, status, created_at,
              locations: [{barangay_name, lat, lng}, ...] } ] }
```

↓
UI Update: Draw each schedule's radius circle around its pin/barangay center,
colored by status (upcoming=amber, ongoing=red, completed=green). Tapping a
circle opens the schedule detail.

---

## 7. Power Stations

### 7.1 Station list + near-me

Backend Feature: `GET /api/power_station/get.php`,
`GET /api/power_station/get_near_location.php`
↓
Frontend Page: **Power Stations** tab (residents)
↓
User Action: Toggle list / "near me" / map; change radius.
↓
API Request:

```
GET /api/power_station/get.php?page=1&limit=50
GET /api/power_station/get_near_location.php?radius=5000
```

↓
API Response (near variant adds):

```
{ "success": true, "count": 6, "has_location": true, "radius": 5000,
  "data": [ { id, created_by, station_name, location_name, latitude, longitude,
              station_type, access_type, availability_status, operating_hours,
              charging_type, description, image, created_at, updated_at,
              barangay_name, distance: 812.5 }, ... ] }
```

`has_location: false` → stations returned with `distance: 0` unsorted; UI must
show "Set your location to sort by distance" prompt (link to 11).

↓
UI Update: Cards showing name, type badge (power/solar/charging/generator),
availability status color, access (free/paid), operating hours, charging type,
distance if present. "Available only" is guaranteed server-side for near-list.

### 7.2 Available count (dashboard)

Backend Feature: `GET /api/power_station/get_available.php`
↓
Frontend Page: **Dashboard** stats row
↓
API Request:

```
GET /api/power_station/get_available.php
```

↓
API Response:

```
{ "success": true, "total_available": 4 }
```

↓
UI Update: StatCard "Stations available"; tap → Power Stations (near me first).

### 7.3 My station (create)

Backend Feature: `POST /api/power_station/create.php`
↓
Frontend Page: **Power Stations → Add my station**
↓
Form fields:

```
station_name       (text, required)
location_name      (text, required -> geocoded)
station_type       (select: power_station|solar_station|charging_station|generator_station, default power_station)
access_type        (select: free|paid, default free)
availability_status(select: available|busy|offline|maintenance, default available)
operating_hours    (text, optional)
charging_type      (text, optional)
description        (textarea, optional)
image              (text url, optional)
barangay_name      (autocomplete, optional)
latitude/longitude (optional numeric)
```

↓
API Request:

```
POST /api/power_station/create.php  body(JSON): { ...above }
```

↓
API Response:

```
error 403: { "success": false, "message": "You already have a power station. Please update it instead." }
```

↓
UI Update: On 403, switch the form into "edit my existing station" mode
(7.4) with the existing record prefilled. Show the one-station-per-user limit
to the user. Otherwise toast + navigate to my station view.

### 7.4 Update my station

Backend Feature: `POST /api/power_station/update.php`
↓
Frontend Page: **My station** (owner) → edit
↓
API Request:

```
POST /api/power_station/update.php  body(JSON): { id, ...anyOf above }
```

↓
API Response:

```
{ "success": true, "message": "Updated successfully",
  "data": { "id": 3, "latitude": ..., "longitude": ... } }
error 404: { "success": false, "message": "Not found or unauthorized" }
```

↓
UI Update: Station card refreshed; map marker moved if coordinates changed.

### 7.5 My stations list

Backend Feature: `GET /api/power_station/get_my_posts.php`
↓
Frontend Page: **My station** dashboard widget
↓
API Request:

```
GET /api/power_station/get_my_posts.php
```

↓
UI Update: Card with edit/delete actions; "Add station" only when none exists.

### 7.6 Delete my station

Backend Feature: `DELETE` by `POST /api/power_station/delete.php`
↓
Frontend Page: **My station** → delete (confirm dialog)
↓
API Request:

```
POST /api/power_station/delete.php  body: { "station_id": 3 }
```

↓
API Response:

```
{ "success": true, "message": "Power station deleted successfully" }
error 404: { "success": false, "message": "Station not found or not owned by user" }
```

↓
UI Update: Remove card; "Add station" becomes available again.

---

## 8. Notifications

### 8.1 Notification center

Backend Feature: `GET /api/notification/get.php`
↓
Frontend Page: **Notifications** (bell icon → screen; desktop can also poll)
↓
User Action: Open; tap item to open referenced feature; mark read.
↓
Filters: `unread=1`, `type`, `maintenance_id`; pagination `limit`/`offset`.
↓
API Request:

```
GET /api/notification/get.php?limit=50&offset=0
GET /api/notification/get.php?unread=1        // badge refresh
```

↓
API Response:

```
{ "success": true, "total": n, "unread_count": 3, "limit": 50, "offset": 0,
  "data": [ { "id": 9, "user_id": 12, "title": "Scheduled Power Maintenance",
              "message": "...", "type": "maintenance", "is_read": 0,
              "outage_report_id": null, "maintenance_id": 5,
              "flood_report_id": null, "electrical_hazard_id": null,
              "safety_timer_id": null, "created_at": "..." }, ... ] }
```

↓
Frontend State Update:

```
AppState.notificationsUnread = unread_count
store.notifications = data
```

↓
UI Update: Unread badge on bell. Deep-link by type:
`maintenance → maintenance detail · safety_timer → safety timer screen ·
electrical_hazard → hazard detail · flood → flood detail · outage → outage detail`.

### 8.2 Mark one read

Backend Feature: `POST /api/notification/mark_as_read.php`
↓
User Action: Tap an unread notification.
↓
API Request:

```
POST .../mark_as_read.php  body: { "notification_id": 9 }
```

↓
API Response:

```
{ "success": true, "message": "Notification marked as read" }
```

↓
UI Update: Item becomes "read", badge decrements (also refetch unread to stay
consistent).

### 8.3 Mark all read

Backend Feature: `POST /api/notification/mark_all_as_read.php`
↓
User Action: "Mark all as read" button.
↓
API Request:

```
POST .../mark_all_as_read.php
```

↓
API Response:

```
{ "success": true, "message": "All notifications marked as read", "updated": 3 }
```

↓
UI Update: Badge cleared, all rows styled read.

### 8.4 Create notification (company/admin)

Backend Feature: `POST /api/notification/create.php`
↓
Frontend Page: **Company Notify** (part of Company Control Center)
↓
Form fields:

```
target  (user id, free text for power users)  OR
user_id / title / message / type (for single)
   — or —
notifications: [ {user_id, title, message, type}, ... ]  (bulk)
type defaults to "maintenance"
```

↓
API Request:

```
POST .../create.php  body(JSON):
{ "notifications": [ { "user_id": 7, "title": "...", "message": "...", "type": "system" } ] }
```

↓
API Response:

```
{ "success": true, "message": "Notification(s) created", "created": 2 }
```

↓
UI Update: Toast "2 notification(s) sent". (Most notifications are generated
by the backend automatically — this screen is a manual broadcast tool.)

---

## 9. Safety Timers

### 9.1 Timer list (live countdowns)

Backend Feature: `GET /api/safety_timer/get.php`
↓
Frontend Page: **Safety Timers** tab
↓
User Action: Open; start/stop/delete timers; receive reminder toasts.
↓
API Request:

```
GET /api/safety_timer/get.php
```

↓
API Response:

```
{ "success": true, "count": n, "data": [
    { id, user_id, timer_type_id, title, started_at, expected_expiration_at,
      warning_at, notes, status, completed_at, timer_name: "...",  // joined
      default_duration_hours, warning_hours_before, type_description,
      remaining_seconds, timer_id }, ... ] }
```

Note: this GET is a "live" read — the server recomputes
`status` (`running|warning|expired|stopped`) and fires one-time alerts +
notifications when a timer enters `warning`/`expired`. The frontend should
poll/refresh this endpoint (e.g. every 60s) while the screen is open, and drive
the `CountdownClock` from `remaining_seconds`.

↓
UI Update: Card per timer: title, type, big countdown, status color
(running=green, warning=amber, expired=red, stopped=gray), progress bar to
`warning_at`/`expiration`. When a card flips to warning/expired via refresh,
show a toast and a notification also appears (server-created).

### 9.2 Create timer

Backend Feature: `POST /api/safety_timer/create.php`
↓
Frontend Page: **Safety Timers → Start timer**
↓
Form fields (either path — see labels):

```
timer_type_name     (select: sealed_refrigerator | deep_freezer_24h |
                      deep_freezer_48h | medication  — recommended path)
  -- OR custom --
duration_hours          (number)
warning_hours_before    (number, >= 0)
title               (text, optional; defaults to type name)
notes               (textarea, optional)
started_at          (datetime, optional; defaults to now)
```

↓
API Request:

```
POST /api/safety_timer/create.php  body(JSON):
{ "timer_type_name": "sealed_refrigerator", "title": "Fridge food", "notes": "..." }
```

↓
API Response:

```
{ "success": true, "message": "Safety timer started", "timer_id": 8,
  "started_at": "...", "warning_at": "...", "expected_expiration_at": "...",
  "status": "running" }
```

↓
UI Update: New card appears with live countdown; toast with the expected
warning/expiration times.

### 9.3 Stop timer

Backend Feature: `POST /api/safety_timer/stop.php`
↓
Frontend Page: **Safety Timer card** → "Stop"
↓
API Request:

```
POST .../stop.php  body: { "timer_id": 8 }
```

↓
API Response:

```
{ "success": true, "message": "Timer stopped" }
```

↓
UI Update: Card status → stopped, countdown frozen at 0 (grey), stop button
hidden, keep/delete remain.

### 9.4 Delete timer

Backend Feature: `POST /api/safety_timer/delete.php`
↓
Frontend Page: **Safety Timer card** → "Delete" (confirm)
↓
API Request:

```
POST .../delete.php  body: { "timer_id": 8 }
```

↓
API Response:

```
{ "success": true, "message": "Timer deleted" }
error 404: { "success": false, "message": "Timer not found" }
```

↓
UI Update: Card removed; history (if any) revsed; toast.

---

## 10. Battery Tracking

### 10.1 Device dashboard

Backend Feature: `GET /api/battery/get.php`
↓
Frontend Page: **Battery Tracker** tab
↓
User Action: Open; quick-update a shared/primary device percentage.
↓
API Request:

```
GET /api/battery/get.php
```

↓
API Response:

```
{ "success": true, "count": n, "data": [
    { id, device_name, device_type, capacity_mah, current_percentage,
      is_primary, created_at, updated_at,
      recent_logs: [{ battery_percentage_start, battery_percentage_end,
                      usage_minutes, estimated_watts, activity, logged_at } ...],
      estimated_hours_remaining: 4.5,
      estimated_usage_rate_per_hour: 3.2 }, ... ] }
```

`estimated_hours_remaining` is `null` until there are usage logs.

↓
UI Update: Card per device: name, type icon, percentage ring/bar, primary
badge, "≈ 4.5h left @ 3.2%/hr" estimate, container of recent logs. Tap card →
device detail (10.5).

### 10.2 Add device

Backend Feature: `POST /api/battery/create.php`
↓
Frontend Page: **Battery Tracker → Add device**
↓
Form fields:

```
device_name          (text, required)
device_type          (select: phone|laptop|powerbank|ups|tablet|other, required)
capacity_mah         (number, optional, >= 0)
current_percentage   (number 0–100, default 100)
is_primary           (toggle; setting true unsets others server-side)
```

↓
API Request:

```
POST /api/battery/create.php  body(JSON): { ...above }
```

↓
API Response:

```
{ "success": true, "message": "Battery device created", "device_id": 21 }
```

↓
UI Update: Card appended; if primary, other primary badges clear.

### 10.3 Update device / set percentage

Backend Feature: `POST /api/battery/update.php`,
`POST /api/battery/set_percentage.php` (quick action)
↓
Frontend Page: **Device detail / dashboard quick-edit**
↓
User Action: Edit fields, or drag/enter a new percentage.
↓
API Request:

```
POST .../update.php         body: { device_id, ...fields }
POST .../set_percentage.php body: { device_id, current_percentage: 62 }
```

↓
API Response:

```
{ "success": true, "message": "Device updated" }
{ "success": true, "message": "Battery percentage updated", "current_percentage": 62 }
```

↓
UI Update: Percentage ring/estimate recalculated (estimate may be re-fetched
from get since server computes it).

### 10.4 Log usage

Backend Feature: `POST /api/battery/log_usage.php`
↓
Frontend Page: **Device detail → "Log usage"**
↓
Form fields:

```
device_id                 (implicit)
battery_percentage_start  (number, required)
battery_percentage_end    (number, required — also updates device level)
usage_minutes             (number, optional)
estimated_watts           (number, optional)
activity                  (text, optional)
```

↓
API Request:

```
POST .../log_usage.php  body(JSON): { device_id: 21, battery_percentage_start: 90,
  battery_percentage_end: 70, usage_minutes: 60, activity: "movies" }
```

↓
API Response:

```
{ "success": true, "message": "Battery usage logged", "log_id": 3 }
```

↓
UI Update: Refresh device detail → recent_logs updated, device percentage =
end value, estimate recomputed; toast "Usage logged".

### 10.5 Device history

Backend Feature: `GET /api/battery/get_history.php?device_id=`
↓
Frontend Page: **Device detail → History**
↓
API Request:

```
GET /api/battery/get_history.php?device_id=21
```

↓
API Response:

```
{ "success": true, "count": m, "data": [
   { id, battery_device_id, battery_percentage_start, battery_percentage_end,
     usage_minutes, estimated_watts, activity, logged_at }, ... ] }
```

↓
UI Update: Timeline/table of sessions (start→end %, minutes, watts, activity,
timestamp). EmptyState if none.

### 10.6 Delete device

Backend Feature: `POST /api/battery/delete.php`
↓
Frontend Page: **Device detail** → delete (confirm)
↓
API Request:

```
POST .../delete.php  body: { device_id: 21 }
```

↓
API Response:

```
{ "success": true, "message": "Device deleted" }
error 404: { "success": false, "message": "Device not found" }
```

↓
UI Update: Card removed, toast. If a new primary is needed, UI prompts to set
one.

---

## 11. User Location

### 11.1 View / set home location

Backend Feature: `GET /api/user_location/get.php`,
`POST /api/user_location/location.php`
↓
Frontend Page: **Profile → My Location** (also used on first-run onboarding
and by "near me" prompts)
↓
Form fields:

```
address / location_name  (text, required -> geocoded by server)
barangay_name            (autocomplete, optional; geo-matched if absent)
latitude / longitude     (optional numeric; geocoded if omitted)
```

↓
API Request:

```
GET  /api/user_location/get.php
POST /api/user_location/location.php  body(JSON):
     { "address": "A. Fernandez Ave, Dagupan", "barangay_name": "Pantal" }
```

↓
API Response (GET):

```
{ "success": true, "data": { "location_name": exec | null, "address": "...",
  "barangay": "Pantal", "barangay_id": 4, "latitude": 16.04, "longitude": 120.33,
  "updated_at": "..." } }
```

API Response (POST):

```
{ "success": true, "message": "Location updated successfully",
  "data": { address, latitude, longitude, barangay, barangay_id } }
error 404: { "success": false, "message": "Location not found" }
```

↓
Frontend State Update:

```
AppState.currentLocation = response.data
```

↓
UI Update: Show address/barangay card with "Edit". Location is used:
- as center point for `power_station/get_near_location` (server-side),
- to determine which maintenance notices affect the user,
- to seed outage/flood/hazard report forms when convenient.

---

## 12. Flood Reports

### 12.1 Flood feed + map

Backend Feature: `GET /api/flood_report/get.php`
↓
Frontend Page: **Disasters → Floods**
↓
Filters: `status`, `flood_level` (low|moderate|high|severe), `barangay`.
↓
API Request:

```
GET /api/flood_report/get.php?flood_level=high&barangay=Lucao
```

↓
API Response:

```
{ "success": true, "count": n, "data": [
  { id, reported_by, location_name, latitude, longitude, flood_depth_cm,
    flood_level, description, image_proof, status, reported_at, updated_at,
    barangay_name }, ... ] }
```

↓
UI Update: Map markers sized/colored by flood_level + list cards (depth,
level chip, status chip, photo, barangay).

### 12.2 Report flood

Backend Feature: `POST /api/flood_report/create.php`
↓
Frontend Page: **Report Flood** (form)
↓
Form fields:

```
location_name   (text, required; or explicit lat/lng)
flood_level     (select: low|moderate|high|severe, required)
flood_depth_cm  (number, optional)
description     (textarea, optional)
barangay_name   (autocomplete, optional)
image_url       (text url, optional)
latitude/longitude (optional numeric)
```

↓
API Request:

```
POST /api/flood_report/create.php  body(JSON): { ...above }
```

↓
API Response:

```
{ "success": true, "message": "Flood report created", "flood_report_id": 15 }
error 404: { "success": false, "message": "Unable to resolve location coordinates" }
```

↓
UI Update: Navigate to Flood feed with the new marker on top; toast.

### 12.3 Nearby floods (risk overlay data)

Backend Feature: `GET /api/flood_report/get_nearby.php?lat=&lng=&radius=`
↓
Frontend Page: **Map → risk layer (flood component)**
↓
API Request:

```
GET /api/flood_report/get_nearby.php?lat=16.04&lng=120.33&radius=3000
```

↓
API Response:

```
{ "success": true, "count": k, "radius": 3000,
  "data": [ { id, location_name, latitude, longitude, flood_depth_cm,
              flood_level, description, image_proof, status, reported_at,
              barangay_name, distance }, ... ] }
```

(only non-`cleared` floods)
↓
UI Update: Draw flood pins within radius; see also combined risk layer (14).

---

## 13. Electrical Hazards

### 13.1 Hazard feed + map

Backend Feature: `GET /api/electrical_hazard/get.php`
↓
Frontend Page: **Disasters → Hazards**
↓
Filters: `status`, `severity` (low|moderate|high|critical), `barangay`.
↓
API Request:

```
GET /api/electrical_hazard/get.php?status=reported
```

↓
API Response:

```
{ "success": true, "count": n, "data": [
  { id, reported_by, location_name, latitude, longitude, description, severity,
    status, image_proof, reported_at, resolved_at, barangay_name, hazard_type },
  ... ] }
```

↓
UI Update: Map + list; marker color by severity; status chip
(reported|verified|resolved).

### 13.2 Report hazard

Backend Feature: `POST /api/electrical_hazard/create.php`
↓
Frontend Page: **Report Hazard** (form)
↓
Form fields:

```
location_name   (text, required; or explicit lat/lng)
hazard_type     (select from hazard_types — e.g. submerged_electrical_equipment,
                 fallen_wire, sparks; required)
severity        (select: low|moderate|high|critical, default moderate)
description     (textarea, optional)
barangay_name   (autocomplete, optional)
image_url       (text url, optional)
latitude/longitude (optional numeric)
```

↓
API Request:

```
POST /api/electrical_hazard/create.php  body(JSON): { ...above }
```

↓
API Response:

```
{ "success": true, "message": "Electrical hazard reported", "hazard_id": 22 }
```

↓
UI Update: New marker on hazard map; toast.

### 13.3 Update hazard status

Backend Feature: `POST /api/electrical_hazard/update_status.php`
↓
Frontend Page: **Hazard detail** — owner **or** staff
↓
Form fields: `status` (select: reported|verified|resolved).
Rules: owner may act on own; staff may act on any; staff verifying/resolving
auto-notifies the reporter (server-side).
↓
API Request:

```
POST .../update_status.php  body: { "hazard_id": 22, "status": "resolved" }
```

↓
API Response:

```
{ "success": true, "message": "Hazard status updated", "status": "resolved" }
error 404 / 403 handling per global contract
```

↓
UI Update: Status chip updates; if resolved, `resolved_at` shown and marker
leaves active/risk layers; staff toast "Reporter notified".

### 13.4 Nearby hazards (risk overlay data)

Backend Feature: `GET /api/electrical_hazard/get_nearby.php?lat=&lng=&radius=`
↓
Frontend Page: **Map → risk layer (hazard component)**
↓
API Response:

```
{ "success": true, "count": k, "radius": 3000,
  "data": [ { id, location_name, ... , hazard_type, distance }, ... ] }
```

(only unresolved hazards)

---

## 14. Electrocution-Risk Areas (combined)

Backend Feature: `GET /api/risk/get_nearby.php?lat=&lng=&radius=`(primary source)
↓
Frontend Page: **Map → "Risk / Danger zone" layer** (resident alerting)
↓
User Action: Enable layer, or app auto-calls near the user's saved location.
↓
API Request:

```
GET /api/risk/get_nearby.php?lat=16.04&lng=120.33&radius=3000
```

↓
API Response:

```
{ "success": true, "radius": 3000,
  "counts": { "floods": 3, "hazards": 2 },
  "data": {
    "floods": [ { id, category: "flood", location_name, lat, lng,
                  risk_level: "high", description, reported_at,
                  barangay_name, distance }, ... ],
    "hazards": [ { id, category: "hazard", location_name, lat, lng,
                   risk_level: "critical", description, reported_at,
                   barangay_name, distance }, ... ]
  } }
```

Note: `risk_level` is `flood_level` for floods and `severity` for hazards.
↓
Frontend State Update:

```
store.risk = { "floods": [...], "hazards": [...] }
```

↓
UI Update: Render high-visibility "electrocution risk" overlay markers
(floods as water-color icons, hazards as lightning icons) within the radius
circle; a summary line "3 flood + 2 hazard risks nearby". Tapping an item
opens the corresponding flood/hazard detail. Poll this endpoint when the layer
is active.

---

## 15. Heatmap & Clustering

### 15.1 Heatmap

Backend Feature: `GET /api/heatmap/get.php`
↓
Frontend Page: **Map → "Outage heatmap" layer**, **Dashboard mini heatmap**
↓
User Action: Toggle mode (per barangay / cluster), radius (clusters), days.
↓
API Request:

```
GET /api/heatmap/get.php?mode=by_barangay&days=7
GET /api/heatmap/get.php?mode=clusters&radius=1000&days=7
```

↓
API Response:

```
mode=by_barangay:
{ "success": true, "mode": "by_barangay", "lookback_days": 7,
  "report_count": 40, "point_count": 12, "data": [
   { barangay_id, barangay_name, report_count, affected_houses, latitude,
     longitude, confidence_score: 66.67, severity_score: 50,
     forecast_level: "high" }, ... ] }

mode=clusters:
{ "success": true, "mode": "clusters", "lookback_days": 7,
  "report_count": 40, "point_count": 6, "data": [
   { latitude, longitude, radius_meters: 1000, report_count, affected_houses,
     confidence_score, severity_score, forecast_level }, ... ] }
```

`forecast_level` = low | moderate | high | critical (rule-based).
↓
UI Update: Heat zones colored by foreast level (blue→yellow→orange→red);
barangay polygons shaded by report count; cluster circles sized by
`report_count`, labeled with confidence %/forecast. Tapping a point opens the
outage feed filtered to that barangay.

### 15.2 Cluster list (analysis history)

Backend Feature: `GET /api/cluster/get.php`
↓
Frontend Page: **Company/Staff → Clusters** (optional analytics screen)
↓
Filters: `status`, `barangay`, `from_date`, `include_reports=1`.
↓
API Request:

```
GET /api/cluster/get.php?include_reports=1
```

↓
API Response:

```
{ "success": true, "count": n, "data": [
   { id, cluster_date, center_latitude, center_longitude, radius_meters,
     report_count, affected_houses, confidence_score, severity_score,
     forecast_level, status, calculated_at, barangay_name,
     reports: [ {outage_report_id, distance_meters, report_key, location_name} ] },
  ... ] }
```

↓
UI Update: Table/list of persisted clusters with constituent report chips;
tap cluster → opens outage feed showing those reports.

### 15.3 Store a cluster (staff: lineman/company/admin)

Backend Feature: `POST /api/cluster/store.php`
↓
Frontend Page: **Staff/Company → Save cluster** (usually driven from the
heatmap screen: "Save this cluster")
↓
Form fields (prefilled from selected heatmap point, editable):

```
barangay_id     (int, required)
latitude        (float, required)
longitude       (float, required)
radius_meters   (int, default 500)
report_count    (int, default 1)
affected_houses (int, default 0)
confidence_score(float, default 0)
severity_score  (float, default 0)
forecast_level  (select: low|moderate|high|critical, default low)
cluster_date    (date, optional, defaults today)
report_ids      (array of outage report ids, linked with computed distance)
```

↓
API Request:

```
POST /api/cluster/store.php  body(JSON): { ...above }
```

↓
API Response:

```
{ "success": true, "message": "Cluster persisted", "cluster_id": 30 }
error 400: { "success": false, "message": "barangay_id, latitude and longitude are required" }
```

↓
UI Update: Toast "Cluster saved" + the new cluster appears in 15.2.

---

## 16. Navigation Map (role-aware)

### 16.1 Route → Role gating

```
RUN_ROUTE_GUARD(route):
  if not AppState.auth.loaded:
      wait for bootstrap
  if route.requiresAuth and not AppState.auth.user:
      redirect -> LOGIN ; return
  if route.requiredRoles and AppState.auth.role not in route.requiredRoles:
      show "403 — access denied" ; redirect -> role's home ; return
```

### 16.2 Sitemap

```
GUEST (not authenticated)
  /login            Login                    (2.2, Google 2.3)
  /register         Register                 (2.1)
  /oauth/callback   Google OAuth landing     (2.3)

AUTHENTICATED SHELL (bottom nav for all roles):
  /dashboard        Dashboard                (stats: 3.2, 6.2, 7.2 + shortcuts)
  /map              Map & Heatmap            (3.1, 6.6, 7.1, 12.1/[3], 13.1/[4],
                                              14, 15.1)
  /outages          Outage feed + filters    (3.1)
  /outages/:id      Outage detail            (3.6, 4.2, 4.3)
  /report/outage    Report outage            (3.3; disabled while active report)
  /my-report        My report list/detail    (3.7, 3.6, 3.4, 3.5, 3.8)
  /maintenance      Maintenance list         (6.1)
  /stations         Power stations           (7.1)
  /stations/add     Add my station           (7.3)
  /stations/my      My station               (7.4, 7.5, 7.6)
  /safety-timers    Safety timers            (9.x)
  /battery          Battery tracker          (10.x)
  /battery/:id      Device detail+history    (10.5)
  /disasters/floods       Flood feed/report  (12.x)
  /disasters/hazards      Hazard feed/report (13.x)
  /notifications    Notifications            (8.x)
  /profile          Profile (name, role, picture), My Location (11), Logout (2.5)

STAFF-ONLY EXTENSIONS (lineman / electric_company / admin):
  /staff/queue      Field verification queue (4.1)

COMPANY-ONLY (electric_company / admin):
  /company          Control Center           (5.x reports, 8.4 notify)
  /company/maintenance    Manage schedules   (6.3, 6.4, 6.5)
  /company/clusters       Cluster history    (15.2, 15.3)
```

### 16.3 Primary navigation (role-filtered)

```
ALL      : Dashboard, Map, Outages, Maintenance, Stations,
           Safety Timers, Battery, Disasters, Notifications, Profile
STAFF    : + Field Queue
COMPANY  : + Company (control center, notify), Manage Maintenance, Clusters
```

---

## 17. CRUD Operations Summary (feature → create/read/update/delete)

| Feature | Create | Read | Update | Delete |
|---|---|---|---|---|
| Auth user | register / google | me | — (profile read-only) | — |
| Outage report | outage_report/create | get / get_detail / get_my_report / get_active / get_resolve | outage_report/update | outage_report/delete (cancel) |
| Outage photo | upload_image | (via get_detail images) | — | — |
| Outage verify/update | outage/verify, outage/add_update | outage/get (staff) | — | — |
| Company outage mgmt | — | electric_com/get | update_single / update_barangay / update_dagupan | — |
| Maintenance | maintenance/create | maintenance/get / get_upcoming / get_complete / maintenance_map/get | maintenance/update | maintenance/delete |
| Power station | power_station/create | get / get_available / get_my_posts / get_near_location | power_station/update | power_station/delete |
| Notification (user) | — | notification/get | mark_as_read / mark_all_as_read | — |
| Notification (company) | notification/create | — | — | — |
| User location | location.php (upsert) | user_location/get | location.php (upsert) | — |
| Battery device | battery/create | battery/get / get_history | battery/update / set_percentage / log_usage | battery/delete |
| Safety timer | safety_timer/create | safety_timer/get | safety_timer/stop | safety_timer/delete |
| Flood report | flood_report/create | flood_report/get / get_nearby | — | — |
| Electrical hazard | electrical_hazard/create | electrical_hazard/get / get_nearby | electrical_hazard/update_status | — |
| Risk near | — | risk/get_nearby | — | — |
| Heatmap | — | heatmap/get | — | — |
| Cluster | cluster/store (staff) | cluster/get | — | — |

---

## 18. Full High-Level System Flow

```
BOOTSTRAP
  SplashScreen -> AppState.auth.loaded = false
  [fire in parallel]
     1. GET /api/auth/me.php            -> profile + role  (or 401)
     2. GET /api/reference/get.php      -> lookups
  if me() ok:
      AppState.auth = {user, role}
      preload() -> GET /notification/get.php?unread=1 -> unread badge
      preload() -> GET /user_location/get.php          -> current location
  else:
      allow GUEST routes only

LOGIN / REGISTER
  /login   -> email+password -> login() -> me() -> role routing
  /register-> fields -> register() -> me() -> role routing
  /oauth/callback -> me() -> role routing
  role routing:
     user            -> /dashboard
     lineman         -> /dashboard (staff queue shortcut)
     electric_company-> /dashboard (company control center shortcut)
     admin           -> /dashboard (admin sees everything)

DASHBOARD (all roles)
  load stats in parallel:
      outage_report/get_active.php
      outage_report/get_resolve.php
      maintenance/get_upcoming.php
      power_station/get_available.php
  render: greeting + role-aware quick actions +
          StatCards(active outages, resolved, scheduled maintenance,
                    stations available) +
          personal widgets: my active report state, battery summary,
          running safety timer teaser, unread notifications
  quick actions by role:
      user   -> Report outage, My report, Battery, Safety timer, Power stations
      lineman-> Field queue, Verify reports
      company-> Control center, New maintenance, Notify

MAP (all roles)
  base layer: outage markers from outage_report/get (color by severity)
  optional layers: maintenance_map/get circles,
                   risk/get_nearby (floods+hazards), 
                   heatmap/get (by_barangay | clusters)
  tap marker -> route to feature detail page

FEATURE PAGES (resident user) — each is a self-contained CRUD flow
  Outages   : feed -> detail -> (owner) edit / add photo / cancel report
  Report    : anti-spam guard (one active report) -> create -> upload photo
  Maintenance: read-only announcements + map layer
  Stations  : list / near-me -> add/update/delete own station
  Safety    : start -> live countdown (poll get) -> stop/delete
  Battery   : devices -> add/update percentage -> log usage -> history -> delete
  Floods    : feed -> report + map pins
  Hazards   : feed -> report / update own status
  Notifications: list -> open deep-link -> mark read / all read
  Profile   : view info -> set location -> logout

FEATURE PAGES (staff)
  Field queue: outage/get -> detail -> verify (confirmed/not/false) -> add update
               -> hazards: update_status -> resolved
  Risk/cluster analytics: heatmap -> save cluster (cluster/store)

FEATURE PAGES (company/admin)
  Control center: electric_com/get -> update_single / update_barangay /
                  update_dagupan (bulk with confirm)
  Maintenance: create -> edit -> delete (auto re-notifies users)
  Notify     : notification/create (manual broadcast)
  Clusters   : cluster/get history

LOGOUT (any role, profile menu)
  confirm -> POST auth/logout.php -> clear local state ->
  /login (toast "Logged out")
```

---

## 19. Frontend → Backend Endpoint Index (cheat sheet for the implementer)

All under `API_BASE + "/api"`. `C` = company/admin. `S` = staff
(lineman/company/admin). Bold = used by everyone authenticated.

| Endpoint | Page refs | Who |
|---|---|---|
| `auth/register.php` | 2.1 | guest |
| `auth/login.php` | 2.2 | guest |
| `auth/google.php`, `auth/google_callback.php` | 2.3 | guest |
| `auth/logout.php` | 2.5 | all |
| `auth/me.php` | 2.4 | all |
| `reference/get.php` | 1 | all |
| `outage_report/create.php` | 3.3 | user |
| `outage_report/get.php` | 3.1 | all |
| `outage_report/get_active.php` | 3.2 | all |
| `outage_report/get_resolve.php` | 3.2 | all |
| `outage_report/get_my_report.php` | 3.7 | all |
| `outage_report/get_detail.php` | 3.6 | owner/staff |
| `outage_report/update.php` | 3.4 | owner |
| `outage_report/delete.php` | 3.8 | owner |
| `outage_report/upload_image.php` | 3.5 | owner/staff |
| `outage/get.php` | 4.1 | S |
| `outage/verify.php` | 4.2 | S |
| `outage/add_update.php` | 4.3 | S |
| `outage_report_electric_com/get.php` | 5.1 | S |
| `outage_report_electric_com/update_single.php` | 5.2 | S |
| `outage_report_electric_com/update_barangay.php` | 5.3 | S |
| `outage_report_electric_com/update_dagupan.php` | 5.4 | S |
| `maintenance/create.php` | 6.3 | C |
| `maintenance/update.php` | 6.4 | C |
| `maintenance/delete.php` | 6.5 | C |
| `maintenance/get.php` | 6.1 | all |
| `maintenance/get_upcoming.php` | 6.2 | all |
| `maintenance/get_complete.php` | 6.x | C |
| `maintenance_map/get.php` | 6.6 | all |
| `power_station/create.php` | 7.3 | user |
| `power_station/get.php` | 7.1 | all |
| `power_station/get_available.php` | 7.2 | all |
| `power_station/get_my_posts.php` | 7.5 | all |
| `power_station/get_near_location.php` | 7.1 | all |
| `power_station/update.php` | 7.4 | owner |
| `power_station/delete.php` | 7.6 | owner |
| `notification/get.php` | 8.1 | all |
| `notification/mark_as_read.php` | 8.2 | all |
| `notification/mark_all_as_read.php` | 8.3 | all |
| `notification/create.php` | 8.4 | C |
| `user_location/get.php` | 11.1 | all |
| `user_location/location.php` | 11.1 | all |
| `battery/create.php` | 10.2 | user |
| `battery/get.php` | 10.1 | all |
| `battery/update.php` | 10.3 | owner |
| `battery/set_percentage.php` | 10.3 | owner |
| `battery/log_usage.php` | 10.4 | owner |
| `battery/get_history.php` | 10.5 | owner |
| `battery/delete.php` | 10.6 | owner |
| `safety_timer/create.php` | 9.2 | user |
| `safety_timer/get.php` | 9.1 | all |
| `safety_timer/stop.php` | 9.3 | owner |
| `safety_timer/delete.php` | 9.4 | owner |
| `flood_report/create.php` | 12.2 | user |
| `flood_report/get.php` | 12.1 | all |
| `flood_report/get_nearby.php` | 12.3 | all |
| `electrical_hazard/create.php` | 13.2 | user |
| `electrical_hazard/get.php` | 13.1 | all |
| `electrical_hazard/get_nearby.php` | 13.4 | all |
| `electrical_hazard/update_status.php` | 13.3 | owner/staff |
| `risk/get_nearby.php` | 14 | all |
| `heatmap/get.php` | 15.1 | all |
| `cluster/store.php` | 15.3 | S |
| `cluster/get.php` | 15.2 | all |

---

## 20. Frontend Implementation Notes (constraints from the backend)

1. **Cookies, not tokens** — never read `jwt_token` in JS; rely on `credentials:
   "include"` and `me.php` to detect auth state. 24h expiry → global 401 handler
   returns to login.
2. **No role control** — never render or send a role picker. Show role as a
   read-only badge from `me.php`.
3. **Lookup strings, not ids** — the API accepts display names for `category`,
   `severity`, `hazard_type`, `status`, `station_type`, `timer_type_name`;
   populate all selects from `reference/get.php`.
4. **Single-active constraints** the UI must reflect:
   - one active outage report per user (disable "report" when one exists);
   - one power station per user (switch to edit mode);
   - one primary battery device;
   - maintenance duplicate (date+barangay) → 409 inline error.
5. **Geocoding is server-side** — frontend only sends an address string;
   "Unable to resolve location coordinates" (404) and "Outside coverage area"
   (403) must be shown as guidance on the location field.
6. **Photo constraints** — jpg/png/gif/webp, ≤ 5 MB, and the upload endpoint
   requires an existing `outage_report_id`.
7. **Status transition rules** (surface as hints): verification
   confirmed→verified, not_confirmed→under_review, false_report→rejected;
   `resolved` always flips `is_active` to 0 (report/… leaves active views);
   maintenance status is usually computed from date/time.
8. **Live data patterns** — poll safety_timer/get while the timer screen is
   open (server fires one-time alerts/notifications); poll notification/get for
   the unread badge; refetch risk/heatmap when those map layers are active.
9. **Mutation responses** are lean — after create/update/delete the frontend
   should refetch the affected list/detail when it needs server-computed values
   (e.g. battery estimates, maintenance `users_notified`, verification target
   status).
10. **Company pages have destructive bulk actions** — always confirm before
    `update_dagupan` / `update_barangay`, and surface the returned `affected`
    count afterwards.