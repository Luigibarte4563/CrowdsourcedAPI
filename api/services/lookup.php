<?php

/**
 * Lookup/helper functions for the normalized powerguide schema.
 * Resolves human-readable names to lookup table ids and back.
 */

/**
 * Resolve a name to an id in a lookup table. Optionally falls back to a
 * default row when the provided name is missing or unknown.
 */
function lookupId(PDO $conn, string $table, string $valueColumn, ?string $name, ?string $fallbackValue = null) {
    $search = trim((string)$name);

    if ($search !== '') {
        $stmt = $conn->prepare("SELECT id FROM $table WHERE $valueColumn = ? LIMIT 1");
        $stmt->execute([$search]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return (int)$row['id'];
        }
    }

    if ($fallbackValue !== null) {
        $stmt = $conn->prepare("SELECT id FROM $table WHERE $valueColumn = ? LIMIT 1");
        $stmt->execute([$fallbackValue]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return (int)$row['id'];
        }
    }

    return null;
}

function getCategoryId(PDO $conn, ?string $name) {
    return lookupId($conn, 'outage_categories', 'category_name', $name, 'power_outage');
}

function getSeverityId(PDO $conn, ?string $name) {
    return lookupId($conn, 'severity_levels', 'severity_name', $name, 'moderate');
}

function getHazardTypeId(PDO $conn, ?string $name) {
    return lookupId($conn, 'hazard_types', 'hazard_name', $name, 'none');
}

function getStatusId(PDO $conn, ?string $name) {
    return lookupId($conn, 'outage_statuses', 'status_name', $name, 'active');
}

function getStatusNameById(PDO $conn, $statusId) {
    $stmt = $conn->prepare("SELECT status_name FROM outage_statuses WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$statusId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['status_name'] : null;
}

function getCategoryNameById(PDO $conn, $categoryId) {
    $stmt = $conn->prepare("SELECT category_name FROM outage_categories WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$categoryId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['category_name'] : null;
}

function getSeverityNameById(PDO $conn, $severityId) {
    $stmt = $conn->prepare("SELECT severity_name FROM severity_levels WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$severityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['severity_name'] : null;
}

function getHazardNameById(PDO $conn, $hazardId) {
    $stmt = $conn->prepare("SELECT hazard_name FROM hazard_types WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$hazardId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['hazard_name'] : null;
}

function getStationTypeId(PDO $conn, ?string $name) {
    return lookupId($conn, 'power_station_types', 'type_name', $name, 'power_station');
}

function getStationTypeNameById(PDO $conn, $typeId) {
    $stmt = $conn->prepare("SELECT type_name FROM power_station_types WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$typeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['type_name'] : null;
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
