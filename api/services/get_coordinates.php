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

    $url =
        "https://api.geoapify.com/v1/geocode/search?text="
        . urlencode($location)
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