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
