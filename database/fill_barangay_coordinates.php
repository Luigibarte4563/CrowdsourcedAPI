<?php

/**
 * One-off backfill: give every row in `barangays` a latitude/longitude.
 *
 * WHY THIS EXISTS
 * ---------------
 * `barangays` was seeded with names only, so every row had NULL coordinates.
 * That left geocoding as the only source of a report's coordinates, and every
 * endpoint geocoded the user's free-text address - a terrible geocoding query.
 * "Barangay I" and friends routinely resolve to another barangay, another
 * city, or another country.
 *
 * After this backfill the barangay IS the coordinate source: a report's point
 * is the chosen barangay's stored centroid (an explicit map pin still refines
 * it). Addresses become labels, never geocoding input.
 *
 * ONCE THIS HAS RUN, GEOAPIFY IS NO LONGER USED BY THE API AT ALL.
 *
 * SOURCES, IN ORDER
 * -----------------
 * 1. OpenStreetMap via Nominatim - primary. It has real `place=village` /
 *   `place=quarter` entries for Dagupan's barangays, at useful precision.
 * 2. Geoapify - fallback, and the source the old code used.
 *
 * Neither geocoder is trusted. A result is accepted only if it survives every
 * check in `accepts()`, because the failure modes here are silent and severe:
 * both providers fall back to the surrounding city when they do not know a
 * place, and both return that fallback AT THE BIAS POINT. Unchecked, "Malued"
 * and "Bonuan Boquig" both came back as "Dagupan City, Philippines" at
 * 16.043330, 120.334793 - a dozen barangays sharing one coordinate, and every
 * report in the city landing on the same pixel.
 *
 * USAGE
 * -----
 *   php database/fill_barangay_coordinates.php          # fill NULL rows only
 *   php database/fill_barangay_coordinates.php --all    # re-geocode everything
 *   php database/fill_barangay_coordinates.php --sql    # also print UPDATE stmts
 *
 * Requires PHP with pdo_mysql AND curl (XAMPP's PHP has both; note that a
 * separate PHP on PATH often lacks pdo_mysql).
 *
 * Idempotent: skips rows that already have coordinates unless `--all`.
 */

require_once __DIR__ . '/../config/db_connect.php';

// Dagupan City, Pangasinan - the centre every result is sanity-checked against.
const DAGUPAN_LAT = 16.0433;
const DAGUPAN_LNG = 120.3329;

// Generous box around the city; the urban core spans roughly 16.00-16.10 N,
// 120.25-120.40 E.
const BBOX = ['min_lat' => 15.98, 'max_lat' => 16.15, 'min_lng' => 120.22, 'max_lng' => 120.45];

// Dagupan is ~10 km across; 12 km catches a stray edge case without letting a
// neighbouring city through.
const MAX_DISTANCE_METERS = 12000;

const REQUEST_DELAY_SECONDS = 1; // Nominatim's usage policy: max 1 req/sec

const NOMINATIM_UA = 'PowerGuideDagupan-setup/1.0 (one-off barangay coordinate backfill)';

/**
 * Place types that can legitimately represent a barangay.
 *
 * `river`, `street`, `bus_stop` and friends are excluded on purpose: without
 * this, "Bolosan" resolves to Bolosan *River*, "Pantal" to Pantal *River*,
 * "Carael" to a waterway, and "Caranglaan" to Caranglaan-Tebeng *Road*.
 * `townhall` is allowed because a barangay hall/outpost is a real anchor point
 * inside the barangay, and is the only hit that exists for "Barangay I".
 */
const ACCEPTED_OSM_TYPES = [
    'village', 'quarter', 'town', 'suburb', 'hamlet', 'neighbourhood',
    'city', 'municipality', 'townhall',
];

/** Geoapify result types that can represent a populated place. */
const ACCEPTED_GEOAPIFY_TYPES = [
    'village', 'city', 'town', 'hamlet', 'county', 'state',
    'suburb', 'neighbourhood', 'district', 'locality',
];

function queryVariants($name)
{
    return [
        "$name, Dagupan, Pangasinan, Philippines",
        "$name Barangay, Dagupan City, Pangasinan",
        "$name, Dagupan City, Pangasinan, Philippines",
    ];
}

/** Lowercase, strip accents and punctuation: "Poblacion Oeste" -> "poblacionoeste". */
function normaliseName($value)
{
    $value = preg_replace('/\p{Mn}/u', '', (string) $value);
    return preg_replace('/[^a-z0-9]+/', '', strtolower($value));
}

function haversineMeters($lat1, $lon1, $lat2, $lon2)
{
    $radius = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $radius * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function httpGetJson($url, $headers = [])
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $body = curl_exec($ch);
    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['error' => 'request failed: ' . $error];
    }
    curl_close($ch);

    $data = json_decode($body, true);
    return is_array($data) ? $data : ['error' => 'unreadable response'];
}

/**
 * The acceptance test, shared by both providers.
 *
 * Returns null when the candidate is good, or a reason string when it is not.
 */
function rejects($label, $needle, $lat, $lng, $acceptedTypes)
{
    // 1. It must BE the barangay, not the city the provider fell back to.
    if (strpos(normaliseName($label), $needle) === false) {
        return 'closest match is "' . $label . '"';
    }

    // 2. A barangay is a place, not a river, road or bus stop.
    if ($acceptedTypes && !in_array($acceptedTypes, ACCEPTED_OSM_TYPES, true)) {
        return 'matched a ' . $acceptedTypes . ' ("' . $label . '")';
    }

    // 3. It has to be inside Dagupan. This is what rejects "Bacag, Villasis".
    if ($lat < BBOX['min_lat'] || $lat > BBOX['max_lat']
        || $lng < BBOX['min_lng'] || $lng > BBOX['max_lng']) {
        return sprintf('outside Dagupan bbox (%.5f, %.5f)', $lat, $lng);
    }

    // 4. And near enough to be the place and not a similarly named one.
    $distance = haversineMeters(DAGUPAN_LAT, DAGUPAN_LNG, $lat, $lng);
    if ($distance > MAX_DISTANCE_METERS) {
        return sprintf('%.1f km from the city centre', $distance / 1000);
    }

    return null;
}

function fromNominatim($name, $needle)
{
    $lastError = 'no match';

    foreach (queryVariants($name) as $text) {
        $url = 'https://nominatim.openstreetmap.org/search'
            . '?q=' . urlencode($text)
            . '&format=jsonv2&limit=1&countrycodes=ph&addressdetails=1';

        $data = httpGetJson($url, ['User-Agent: ' . NOMINATIM_UA]);
        if (isset($data['error'])) {
            $lastError = $data['error'];
            sleep(REQUEST_DELAY_SECONDS);
            continue;
        }
        if (empty($data[0]['lat'])) {
            $lastError = 'no match';
            sleep(REQUEST_DELAY_SECONDS);
            continue;
        }

        $hit = $data[0];
        $lat = (float) $hit['lat'];
        $lng = (float) $hit['lon'];
        $label = (string) ($hit['display_name'] ?? '');

        $reason = rejects($label, $needle, $lat, $lng, (string) ($hit['type'] ?? ''));
        sleep(REQUEST_DELAY_SECONDS);

        if ($reason !== null) {
            $lastError = $reason;
            continue;
        }

        return [
            'source' => 'osm',
            'lat' => round($lat, 8),
            'lng' => round($lng, 8),
            'distance' => haversineMeters(DAGUPAN_LAT, DAGUPAN_LNG, $lat, $lng),
            'label' => $label,
        ];
    }

    return ['error' => $lastError];
}

function fromGeoapify($name, $needle, $apiKey)
{
    $lastError = 'no match';

    foreach (queryVariants($name) as $text) {
        $url = 'https://api.geoapify.com/v1/geocode/search'
            . '?text=' . urlencode($text)
            . '&filter=countrycode:ph'
            . '&bias=proximity:' . DAGUPAN_LNG . ',' . DAGUPAN_LAT
            . '&limit=1&apiKey=' . $apiKey;

        $data = httpGetJson($url);
        sleep(REQUEST_DELAY_SECONDS);

        if (isset($data['error'])) {
            $lastError = $data['error'];
            continue;
        }
        if (!empty($data['error'])) {
            $lastError = $data['error']['message'] ?? 'Geoapify error';
            continue;
        }
        if (empty($data['features'][0]['geometry']['coordinates'])) {
            $lastError = 'no match';
            continue;
        }

        $feature = $data['features'][0];
        $coords = $feature['geometry']['coordinates'];
        $lat = (float) $coords[1];
        $lng = (float) $coords[0];
        $label = (string) ($feature['properties']['formatted'] ?? '');

        $reason = rejects(
            $label,
            $needle,
            $lat,
            $lng,
            (string) ($feature['properties']['result_type'] ?? '')
        );
        if ($reason !== null) {
            $lastError = $reason;
            continue;
        }

        return [
            'source' => 'geoapify',
            'lat' => round($lat, 8),
            'lng' => round($lng, 8),
            'distance' => haversineMeters(DAGUPAN_LAT, DAGUPAN_LNG, $lat, $lng),
            'label' => $label,
        ];
    }

    return ['error' => $lastError];
}

/* ------------------------------------------------------------------ */

$options = array_slice($argv ?? [], 1);
$force = in_array('--all', $options, true);
$printSql = in_array('--sql', $options, true);

$apiKey = trim($_ENV['GEOAPIFY_GEOCODING_API_KEY'] ?? '');
if ($apiKey === '') {
    fwrite(STDERR, "GEOAPIFY_GEOCODING_API_KEY is missing from .env - the Geoapify fallback is disabled.\n");
}

$conn = getConnection();

$sql = $force
    ? 'SELECT id, barangay_name FROM barangays ORDER BY barangay_name'
    : 'SELECT id, barangay_name FROM barangays
       WHERE latitude IS NULL OR longitude IS NULL
       ORDER BY barangay_name';

$rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);

echo 'Resolving coordinates for ' . count($rows) . " barangay(s)\n";
echo str_repeat('-', 96) . "\n";
printf("%-20s %-11s %-12s %-8s %-9s %s\n", 'BARANGAY', 'LAT', 'LNG', 'DIST', 'SOURCE', 'MATCHED');

$statements = [];
$failed = [];
$taken = []; // lat,lng already claimed in this run, to catch shared centroids

foreach ($rows as $row) {
    $name = $row['barangay_name'];
    $needle = normaliseName($name);

    $result = $apiKey !== ''
        ? fromNominatim($name, $needle)
        : ['error' => 'skipped'];

    if (isset($result['error']) && $apiKey !== '') {
        $geoapify = fromGeoapify($name, $needle, $apiKey);
        if (!isset($geoapify['error'])) {
            $result = $geoapify;
        } else {
            $result = $geoapify;
        }
    }

    if (isset($result['error'])) {
        $failed[] = ['name' => $name, 'reason' => $result['error']];
        printf("%-20s %-11s %-12s %-8s %-9s %s\n", $name, '-', '-', '-', '-', 'FAILED: ' . $result['error']);
        continue;
    }

    // Two barangays on the identical point is the exact degeneracy this whole
    // exercise exists to remove, so surface it rather than store it quietly.
    $key = round($result['lat'], 4) . ',' . round($result['lng'], 4);
    $note = isset($taken[$key]) ? '  !! SAME POINT AS ' . $taken[$key] : '';
    $taken[$key] = $name;

    $stmt = $conn->prepare('UPDATE barangays SET latitude = :lat, longitude = :lng WHERE id = :id');
    $stmt->execute([':lat' => $result['lat'], ':lng' => $result['lng'], ':id' => $row['id']]);

    $statements[] = sprintf(
        "UPDATE barangays SET latitude = %s, longitude = %s WHERE barangay_name = '%s';",
        rtrim(rtrim(number_format($result['lat'], 8, '.', ''), '0'), '.'),
        rtrim(rtrim(number_format($result['lng'], 8, '.', ''), '0'), '.'),
        str_replace("'", "''", $name)
    );

    printf(
        "%-20s %-11s %-12s %-8s %-9s %s%s\n",
        $name,
        number_format($result['lat'], 6),
        number_format($result['lng'], 6),
        round($result['distance']) . ' m',
        $result['source'],
        $result['label'],
        $note
    );
}

echo str_repeat('-', 96) . "\n";
echo 'resolved: ' . (count($rows) - count($failed)) . '   failed: ' . count($failed) . "\n";

if ($failed) {
    echo "\nNO COORDINATE FOUND - these need a value from a map, entered by hand:\n";
    echo "    UPDATE barangays SET latitude = <lat>, longitude = <lng> WHERE barangay_name = '<name>';\n\n";
    foreach ($failed as $item) {
        echo "  - {$item['name']}: {$item['reason']}\n";
    }
}

$stillNull = $conn->query(
    'SELECT COUNT(*) FROM barangays WHERE latitude IS NULL OR longitude IS NULL'
)->fetchColumn();
echo "\nbarangays still without coordinates: {$stillNull}\n";

if ($printSql && $statements) {
    echo "\n-- paste into database/003_barangay_coordinates.sql --\n";
    foreach ($statements as $statement) {
        echo $statement . "\n";
    }
}