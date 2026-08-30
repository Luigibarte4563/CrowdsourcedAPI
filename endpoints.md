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

Roles are **always assigned by the server** (a new user always gets `user`;
the frontend can never choose a role). Roles are carried in the JWT `role` claim
and enforced server-side.

| Role | Meaning |
|------|---------|
| `user` | Regular PowerGuide user |
| `lineman` | DECORP field personnel |
| `electric_company` | DECORP / electric company personnel |
| `admin` | System administrator |

> `lineman`, `electric_company`, and `admin` are grouped as **staff** in several
> endpoints and may operate on reports they did not create. Maintenance, cluster
> store, and staff notification endpoints are restricted to company/admin.

### Standard response envelope

```json
{ "success": true|false, "message": "...", ...data }
```

Requests that mutate data accept a **JSON body** (also tolerate form-encoded).

---

## Auth Endpoints

### `POST /api/auth/register.php`
Register a new local user and log them in (sets JWT cookie).
Body: `first_name`, `last_name`, `middle_name?`, `email`, `password` (min 6).

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
creates the user (always `user` role), and sets the JWT cookie. Returns JSON.

> Requires real `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` in `.env`
> (currently placeholders).

---

## Reference / Lookups

### `GET /api/reference/get.php`
Returns all lookup tables in one call:
`roles`, `barangays`, `outage_categories`, `severity_levels`, `hazard_types`,
`outage_statuses`, `power_station_types`, `safety_timer_types`,
`notification_types`.

---

## Outage Reports (user-facing)

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

### `GET /api/user_location/get.php`
Get the user's primary saved location.

### `POST /api/user_location/location.php`
Save/update the user's location. Body: `address` (or `location_name`),
`barangay_name`, `latitude`/`longitude` (optional; geocoded if omitted).

---

## Battery Devices

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

### `GET /api/risk/get_nearby.php?lat=&lng=&radius=`
Returns nearby active floods **and** unresolved electrical hazards together
(each tagged with `category`), the primary source for rendering potential
electrocution-risk areas on the map.

---

## Heatmap & Clustering

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
