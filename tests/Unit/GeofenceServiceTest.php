<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\GeofenceService;
use PHPUnit\Framework\TestCase;

class GeofenceServiceTest extends TestCase
{
    public function test_inside_store_geofence(): void
    {
        // Coordinates identical to PCU Dasmariñas store
        $lat = 14.2919444444;
        $lng = 120.9586111111;

        $res = GeofenceService::verifyGeofence($lat, $lng);

        $this->assertTrue($res['inside']);
        $this->assertEquals(0.0, $res['distance']);
    }

    public function test_outside_store_geofence_breach(): void
    {
        // Coordinates 5 kilometers away in Imus, Cavite
        $lat = 14.330000;
        $lng = 120.940000;

        $res = GeofenceService::verifyGeofence($lat, $lng);

        $this->assertFalse($res['inside']);
        $this->assertGreaterThan(500.0, $res['distance']);
    }

    public function test_custom_radius_boundary(): void
    {
        // 100 meters away with 200m radius
        $lat = 14.292500;
        $lng = 120.958611;

        $res = GeofenceService::verifyGeofence($lat, $lng, radiusMeters: 200.0);

        $this->assertTrue($res['inside']);
    }
}
