<?php

namespace App\Http\Controllers;

use App\Models\WeatherSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class WeatherController extends Controller
{
    public function location(): JsonResponse
    {
        $location = WeatherSetting::query()
            ->orderBy('id')
            ->first();

        if (!$location) {
            return response()->json([
                'message' => 'Weather location is not configured.',
            ], 404);
        }

        return response()->json($location);
    }

    public function updateLocation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'city' => ['nullable', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $location = WeatherSetting::query()
            ->orderBy('id')
            ->first();

        if ($location) {
            $location->update($validated);
        } else {
            $location = WeatherSetting::create($validated);
        }

        return response()->json($location);
    }

    public function index(): JsonResponse
    {
        $location = WeatherSetting::query()
            ->orderBy('id')
            ->first();

        if (!$location) {
            return response()->json([
                'message' => 'Weather location is not configured.',
            ], 404);
        }

        try {
            $response = Http::timeout(10)
                ->get('https://api.open-meteo.com/v1/forecast', [
                    'latitude' => $location->latitude,
                    'longitude' => $location->longitude,
                    'current' => implode(',', [
                        'temperature_2m',
                        'apparent_temperature',
                        'relative_humidity_2m',
                        'uv_index',
                        'wind_speed_10m',
                        'wind_direction_10m',
                        'precipitation',
                        'weather_code',
                        'is_day',
                    ]),
                    'hourly' => implode(',', [
                        'temperature_2m',
                        'weather_code',
                        'precipitation',
                        'precipitation_probability',
                        'uv_index',
                        'wind_speed_10m',
                        'wind_direction_10m',
                    ]),
                    'timezone' => 'auto',
                ]);

            if ($response->failed()) {
                return response()->json([
                    'message' => 'Unable to obtain weather data.',
                ], 502);
            }

            $data = $response->json();

            return response()->json([
                'location' => [
                    'city' => $location->city,
                    'country' => $location->country,
                    'latitude' => $data['latitude'] ?? $location->latitude,
                    'longitude' => $data['longitude'] ?? $location->longitude,
                    'timezone' => $data['timezone'] ?? $location->timezone,
                    'elevation' => $data['elevation'] ?? null,
                ],
                'current' => $data['current'] ?? null,
                'current_units' => $data['current_units'] ?? null,
                'forecast' => $data['hourly'] ?? null,
                'forecast_units' => $data['hourly_units'] ?? null,
            ]);
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'Unable to connect to the weather service.',
            ], 502);
        }
    }
}
