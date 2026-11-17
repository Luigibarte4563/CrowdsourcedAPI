<?php

/**
 * Lookup/helper functions for the normalized powerguide schema.
 * Resolves human-readable names to lookup table ids and back.
 */

/**
 * Validate a value against a fixed set of allowed values (ENUM replacement for
 * dropped lookup tables). Returns the value if valid, or the fallback if the
 * input is missing/invalid, or null if no fallback is given.
 */
function validateEnum(?string $value, array $allowed, ?string $fallback = null): ?string {
    $search = trim((string)$value);
    if ($search !== '' && in_array($search, $allowed, true)) {
        return $search;
    }
    return $fallback !== null ? $fallback : null;
}

const SEVERITY_VALUES   = ['low', 'moderate', 'high', 'critical'];
const STATUS_VALUES     = ['active', 'under_review', 'verified', 'resolved', 'rejected'];
const CATEGORY_VALUES   = ['power_outage', 'low_voltage', 'power_fluctuation', 'transformer_explosion', 'fallen_power_line', 'electrical_fire', 'scheduled_maintenance', 'unknown_issue'];
const HAZARD_TYPE_VALUES = ['none', 'smoke', 'sparks', 'fire', 'fallen_wire', 'explosion_sound', 'submerged_electrical_equipment', 'low_hanging_cable', 'exposed_wire', 'electrical_shock_risk'];
const STATION_TYPE_VALUES = ['power_station', 'solar_station', 'charging_station', 'generator_station'];
const NOTIFICATION_TYPE_VALUES = ['maintenance', 'outage', 'emergency', 'flood', 'electrical_hazard', 'battery', 'safety_timer', 'system'];
const TIMER_TYPE_VALUES = ['sealed_refrigerator', 'deep_freezer_24h', 'deep_freezer_48h', 'medication'];

// Default durations (hours) and warning hours per timer_type, matching the
// retired safety_timer_types table. Copy into PHP backend constants.
const TIMER_DEFAULTS = [
    'sealed_refrigerator'  => ['duration' => 4.0,  'warning' => 1.0],
    'deep_freezer_24h'     => ['duration' => 24.0, 'warning' => 4.0],
    'deep_freezer_48h'     => ['duration' => 48.0, 'warning' => 6.0],
    'medication'           => ['duration' => 4.0,  'warning' => 1.0],
];

function getCategory(?string $name): ?string {
    return validateEnum($name, CATEGORY_VALUES, 'power_outage');
}

function getSeverity(PDO $conn, ?string $name): ?string {
    return validateEnum($name, SEVERITY_VALUES, 'moderate');
}

function getHazardType(?string $name): ?string {
    return validateEnum($name, HAZARD_TYPE_VALUES, 'none');
}

function getStatus(PDO $conn, ?string $name): ?string {
    return validateEnum($name, STATUS_VALUES, 'active');
}

function getStationType(?string $name): ?string {
    return validateEnum($name, STATION_TYPE_VALUES, 'power_station');
}

function getNotificationType(?string $name): ?string {
    return validateEnum($name, NOTIFICATION_TYPE_VALUES, 'system');
}

function getTimerType(?string $name): ?string {
    return validateEnum($name, TIMER_TYPE_VALUES, 'medication');
}

function getTimerDefaults(?string $timerType): ?array {
    return TIMER_DEFAULTS[$timerType] ?? null;
}

/**
 * Finds or creates a barangay row by name and returns its id.
 *
 * DANGER: this INSERTS an unknown name. Do not use it to validate or compare a
 * name against existing rows - asking "does this barangay already have a
 * maintenance?" would then silently add the barangay to the city. Use
 * `findBarangayId()` for that, or `resolveBarangayCoords()` when a point is
 * needed too.
 */
function resolveBarangay(PDO $conn, ?string $barangayName) {
    $name = trim((string)$barangayName);
    if ($name === '') {
        return null;
    }

    $stmt = $conn->prepare("SELECT id FROM barangays WHERE barangay_name = ? LIMIT 1");
    $stmt->execute([$name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return (int)$row['id'];
    }

    $stmt = $conn->prepare("INSERT INTO barangays (barangay_name) VALUES (?)");
    $stmt->execute([$name]);
    return (int)$conn->lastInsertId();
}

/**
 * Looks up a barangay's stored coordinates. NEVER creates a row.
 *
 * This is the only source of a record's position now that the barangay is the
 * coordinate authority: an address is a label, not something to geocode. A
 * report's point is the chosen barangay's centroid, optionally refined by an
 * explicit pin the user dropped on the map.
 *
 * Returns null when the name is empty, matches no row, or matches a row whose
 * coordinates are still NULL - the distinction matters, because
 * `resolveBarangay()` above would happily INSERT an unknown name and hand back
 * a brand-new barangay with no coordinates, which used to be harmless (the
 * geocoder supplied the point) and is now a silent dead end.
 *
 * Lookups are case-insensitive on the second attempt: the client submits the
 * name straight from `reference/get.php`, but a hand-typed "poblacion oeste"
 * should not become a 400.
 *
 * @return array{id:int, name:string, latitude:float, longitude:float}|null
 */
function resolveBarangayCoords(PDO $conn, ?string $barangayName) {
    $name = trim((string)$barangayName);
    if ($name === '') {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT id, barangay_name, latitude, longitude
         FROM barangays
         WHERE barangay_name = ?
         LIMIT 1"
    );
    $stmt->execute([$name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $stmt = $conn->prepare(
            "SELECT id, barangay_name, latitude, longitude
             FROM barangays
             WHERE LOWER(barangay_name) = LOWER(?)
             LIMIT 1"
        );
        $stmt->execute([$name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row || $row['latitude'] === null || $row['longitude'] === null) {
        return null;
    }

    return [
        "id" => (int)$row['id'],
        "name" => $row['barangay_name'],
        "latitude" => (float)$row['latitude'],
        "longitude" => (float)$row['longitude'],
    ];
}

/**
 * Names the barangays that still have no coordinates, for an actionable error.
 * Cached per request via a static so a page that resolves several records does
 * not re-query for every one.
 */
function barangaysMissingCoordinates(PDO $conn) {
    static $missing = null;
    if ($missing !== null) {
        return $missing;
    }

    $stmt = $conn->query(
        "SELECT barangay_name FROM barangays
         WHERE latitude IS NULL OR longitude IS NULL
         ORDER BY barangay_name"
    );
    return $missing = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Looks up a barangay id by name. NEVER creates a row.
 *
 * `resolveBarangay()` above inserts when the name is unknown, which is wrong
 * for any caller that only wants to COMPARE against existing rows: asking
 * whether a barangay already has a maintenance would otherwise quietly add it
 * to the city.
 */
function findBarangayId(PDO $conn, ?string $barangayName) {
    $name = trim((string)$barangayName);
    if ($name === '') {
        return null;
    }

    $stmt = $conn->prepare("SELECT id FROM barangays WHERE barangay_name = ? LIMIT 1");
    $stmt->execute([$name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $stmt = $conn->prepare("SELECT id FROM barangays WHERE LOWER(barangay_name) = LOWER(?) LIMIT 1");
        $stmt->execute([$name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    return $row ? (int)$row['id'] : null;
}

/**
 * Haversine distance in meters (shared helper).
 */
function haversineDistanceMeters($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371000;

    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat / 2) ** 2 +
        cos(deg2rad($lat1)) *
        cos(deg2rad($lat2)) *
        sin($dLon / 2) ** 2;

    return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
}
