<?php

declare(strict_types=1);

namespace App\Services;

class GeofenceService
{
    // Philippine Christian University (PCU) Dasmariñas coordinates
    public const DEFAULT_STORE_LAT = 14.2919444444;

    public const DEFAULT_STORE_LNG = 120.9586111111;

    public const DEFAULT_RADIUS_METERS = 500.0;

    /**
     * Calculate great-circle distance between two coordinates in meters using the Haversine formula.
     */
    public static function calculateDistanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusMeters = 6371000.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusMeters * $c;
    }

    /**
     * Verify if coordinates fall within the store geofence.
     *
     * @return array{inside: bool, distance: float, radius: float}
     */
    public static function verifyGeofence(
        float $clientLat,
        float $clientLng,
        float $storeLat = self::DEFAULT_STORE_LAT,
        float $storeLng = self::DEFAULT_STORE_LNG,
        float $radiusMeters = self::DEFAULT_RADIUS_METERS
    ): array {
        $distance = self::calculateDistanceMeters($clientLat, $clientLng, $storeLat, $storeLng);

        return [
            'inside' => $distance <= $radiusMeters,
            'distance' => round($distance, 2),
            'radius' => $radiusMeters,
        ];
    }
}
