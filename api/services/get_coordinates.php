<?php

require_once __DIR__ . '/../../config/env.php';

function getCoordinates($location) {

    $location = trim($location);

    if (empty($location)) {

        return [
            "success" => false,
            "message" => "Location is required"
        ];
    }

    $apiKey = $_ENV['GEOAPIFY_GEOCODING_API_KEY'];

    // This app only covers Dagupan City, Pangasinan. Without a country filter
    // Geoapify returns the best GLOBAL match, which lands somewhere else
    // entirely: "San Carlos Cathedral, Dagupan City" resolved to a cathedral in
    // Carmel, California (36.60, -121.89). Pinning the search to the Philippines
    // keeps saved locations and report coordinates inside the service area.
    $url =
        "https://api.geoapify.com/v1/geocode/search?text="
        . urlencode($location)
        . "&filter=countrycode:ph"
        . "&apiKey="
        . $apiKey;

    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);

    if ($response === false) {

        $error = curl_error($ch);

        curl_close($ch);

        return [
            "success" => false,
            "message" => $error
        ];
    }

    curl_close($ch);


    $data = json_decode($response, true);

    if (empty($data['features'])) {

        return [
            "success" => false,
            "message" => "Location not found"
        ];
    }

    $coordinates =
        $data['features'][0]['geometry']['coordinates'];

    return [

        "success" => true,

        "latitude" => $coordinates[1],

        "longitude" => $coordinates[0]

    ];
}