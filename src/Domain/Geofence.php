<?php

declare(strict_types=1);

namespace DaVez\Domain;

use InvalidArgumentException;

final class Geofence
{
    private const EARTH_RADIUS_METERS = 6371000.0;

    /**
     * @return array{distance_m: float, within: bool}
     */
    public static function evaluate(
        float $baseLatitude,
        float $baseLongitude,
        float $radiusMeters,
        float $latitude,
        float $longitude
    ): array {
        self::assertCoordinate($baseLatitude, $baseLongitude);
        self::assertCoordinate($latitude, $longitude);

        if (
            ($baseLatitude === 0.0 && $baseLongitude === 0.0)
            || !is_finite($radiusMeters)
            || $radiusMeters <= 0.0
        ) {
            throw new InvalidArgumentException(
                'A configuração da área permitida é inválida.'
            );
        }

        $distance = self::haversineMeters(
            $baseLatitude,
            $baseLongitude,
            $latitude,
            $longitude
        );

        return [
            'distance_m' => $distance,
            'within' => $distance <= $radiusMeters,
        ];
    }

    public static function haversineMeters(
        float $latitudeA,
        float $longitudeA,
        float $latitudeB,
        float $longitudeB
    ): float {
        self::assertCoordinate($latitudeA, $longitudeA);
        self::assertCoordinate($latitudeB, $longitudeB);

        $latitudeARadians = deg2rad($latitudeA);
        $latitudeBRadians = deg2rad($latitudeB);
        $latitudeDelta = deg2rad($latitudeB - $latitudeA);
        $longitudeDelta = deg2rad($longitudeB - $longitudeA);

        $haversine = sin($latitudeDelta / 2) ** 2
            + cos($latitudeARadians)
                * cos($latitudeBRadians)
                * sin($longitudeDelta / 2) ** 2;

        // Protege atan2/sqrt contra pequenos erros de arredondamento.
        $haversine = max(0.0, min(1.0, $haversine));
        $centralAngle = 2 * atan2(
            sqrt($haversine),
            sqrt(1.0 - $haversine)
        );

        return self::EARTH_RADIUS_METERS * $centralAngle;
    }

    private static function assertCoordinate(
        float $latitude,
        float $longitude
    ): void {
        if (
            !is_finite($latitude)
            || !is_finite($longitude)
            || $latitude < -90.0
            || $latitude > 90.0
            || $longitude < -180.0
            || $longitude > 180.0
        ) {
            throw new InvalidArgumentException('Coordenadas inválidas.');
        }
    }
}
