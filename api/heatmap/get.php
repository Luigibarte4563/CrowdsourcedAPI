<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';
require_once __DIR__ . '/../services/lookup.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

requireAuthUser();

$mode = $_GET['mode'] ?? 'by_barangay';    /* 'by_barangay' | 'clusters' */
$radius = (int)($_GET['radius'] ?? 1000);  /* meters, for cluster mode */
$days = (int)($_GET['days'] ?? 7);         /* lookback window */

try {
    /* Active outage reports, joined with verification/severity info */
    $cutoff = date("Y-m-d H:i:s", strtotime("-$days days"));
    $sql = "
        SELECT
            o.id,
            o.barangay_id,
            b.barangay_name,
            o.latitude,
            o.longitude,
            o.affected_houses,
            o.is_active,
            o.created_at,
            s.severity_name AS severity,
            COUNT(v.id) AS verified_count
        FROM outage_reports o
        LEFT JOIN barangays b ON b.id = o.barangay_id
        LEFT JOIN severity_levels s ON s.id = o.severity_id
        LEFT JOIN outage_report_verifications v
            ON v.outage_report_id = o.id AND v.verification_status = 'confirmed'
        WHERE o.is_active = 1
          AND o.created_at >= :cutoff
        GROUP BY o.id, o.barangay_id, b.barangay_name, o.latitude, o.longitude,
                 o.affected_houses, o.is_active, o.created_at, s.severity_name
        ORDER BY o.created_at DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(":cutoff", $cutoff);
    $stmt->execute();
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];

    if ($mode === 'clusters') {
        $result = buildClusters($reports, $radius);
    } else {
        $result = buildByBarangay($reports);
    }

    echo json_encode([
        "success" => true,
        "mode" => $mode,
        "lookback_days" => $days,
        "report_count" => count($reports),
        "point_count" => count($result),
        "data" => $result
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}

/**
 * Rule-based nearest-neighbour clustering using a shared radius.
 * Each report carries total (1) and verified counts so confidence can be
 * computed as verified / total * 100.
 */
function buildClusters(array $reports, int $radiusMeters): array {
    $clusters = [];

    foreach ($reports as $report) {
        $lat = (float)$report['latitude'];
        $lng = (float)$report['longitude'];

        $placed = false;
        foreach ($clusters as &$c) {
            $d = haversineDistanceMeters($c['center_lat'], $c['center_lng'], $lat, $lng);
            if ($d <= $radiusMeters) {
                $c['reports'][] = $report;
                $c['total'] += 1;
                $c['verified'] += (int)$report['verified_count'];
                $c['affected_houses'] += (int)$report['affected_houses'];
                $c['severities'][] = $report['severity'];
                $c['center_lat'] = array_sum(array_column($c['reports'], 'latitude')) / count($c['reports']);
                $c['center_lng'] = array_sum(array_column($c['reports'], 'longitude')) / count($c['reports']);
                $placed = true;
                break;
            }
        }
        unset($c);

        if (!$placed) {
            $clusters[] = [
                'center_lat' => $lat,
                'center_lng' => $lng,
                'radius_meters' => $radiusMeters,
                'reports' => [$report],
                'total' => 1,
                'verified' => (int)$report['verified_count'],
                'affected_houses' => (int)$report['affected_houses'],
                'severities' => [$report['severity']]
            ];
        }
    }

    $out = [];
    foreach ($clusters as $c) {
        $confidence = $c['total'] > 0 ? round(($c['verified'] / $c['total']) * 100, 2) : 0;
        $severityScore = round(avgSeverityScore($c['severities']), 2);

        $out[] = [
            'latitude' => round((float)$c['center_lat'], 6),
            'longitude' => round((float)$c['center_lng'], 6),
            'radius_meters' => $c['radius_meters'],
            'report_count' => $c['total'],
            'affected_houses' => $c['affected_houses'],
            'confidence_score' => $confidence,
            'severity_score' => $severityScore,
            'forecast_level' => forecastLevel($confidence, $severityScore)
        ];
    }

    usort($out, fn($a, $b) => $b['report_count'] <=> $a['report_count']);
    return $out;
}

function buildByBarangay(array $reports): array {
    $groups = [];

    foreach ($reports as $report) {
        $key = $report['barangay_id'] ?: 'unknown';
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'barangay_id' => $report['barangay_id'],
                'barangay_name' => $report['barangay_name'] ?: 'Unknown',
                'report_count' => 0,
                'affected_houses' => 0,
                'verified' => 0,
                'severities' => [],
                'lat_sum' => 0,
                'lng_sum' => 0,
                'coord_count' => 0
            ];
        }
        $g =& $groups[$key];
        $g['report_count'] += 1;
        $g['affected_houses'] += (int)$report['affected_houses'];
        $g['verified'] += (int)$report['verified_count'];
        $g['severities'][] = $report['severity'];
        if ($report['latitude'] !== null && $report['longitude'] !== null) {
            $g['lat_sum'] += (float)$report['latitude'];
            $g['lng_sum'] += (float)$report['longitude'];
            $g['coord_count'] += 1;
        }
        unset($g);
    }

    $out = [];
    foreach ($groups as $g) {
        $confidence = $g['report_count'] > 0 ? round(($g['verified'] / $g['report_count']) * 100, 2) : 0;
        $severityScore = round(avgSeverityScore($g['severities']), 2);

        $out[] = [
            'barangay_id' => $g['barangay_id'],
            'barangay_name' => $g['barangay_name'],
            'report_count' => $g['report_count'],
            'affected_houses' => $g['affected_houses'],
            'latitude' => $g['coord_count'] > 0 ? round($g['lat_sum'] / $g['coord_count'], 6) : null,
            'longitude' => $g['coord_count'] > 0 ? round($g['lng_sum'] / $g['coord_count'], 6) : null,
            'confidence_score' => $confidence,
            'severity_score' => $severityScore,
            'forecast_level' => forecastLevel($confidence, $severityScore)
        ];
    }

    usort($out, fn($a, $b) => $b['report_count'] <=> $a['report_count']);
    return $out;
}

/**
 * Numeric severity weight used for severity_score.
 * Outage severities in the normalized schema are minor/moderate/critical.
 */
function severityWeight(?string $severity): float {
    return match ($severity) {
        'critical' => 100.0,
        'high' => 75.0,
        'moderate' => 50.0,
        'minor' => 25.0,
        'low' => 25.0,
        default => 0.0
    };
}

function avgSeverityScore(array $severities): float {
    if (count($severities) === 0) {
        return 0;
    }
    $sum = 0;
    foreach ($severities as $s) {
        $sum += severityWeight($s);
    }
    return $sum / count($severities);
}

/**
 * Rule-based forecast: low/moderate/high/critical.
 */
function forecastLevel(float $confidence, float $severityScore): string {
    if ($severityScore >= 75 || $confidence >= 90 || $severityScore >= 50 && $confidence >= 60) {
        return 'critical';
    }
    if ($severityScore >= 50 || $confidence >= 70) {
        return 'high';
    }
    if ($severityScore >= 25 || $confidence >= 40) {
        return 'moderate';
    }
    return 'low';
}
