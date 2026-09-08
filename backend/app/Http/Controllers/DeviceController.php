<?php

namespace App\Http\Controllers;

use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function index(): JsonResponse
    {
        $devices = Device::with(['controller', 'room'])
            ->orderBy('id')
            ->get();

        return response()->json($devices);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'controller_id' => ['required', 'integer', 'exists:controllers,id'],
            'room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'name' => ['required', 'string', 'max:255'],
            'identifier' => ['required', 'string', 'max:255', 'unique:devices,identifier'],
            'type' => ['required', 'string', 'max:255'],
            'gpio' => ['nullable', 'integer', 'min:0'],
            'state' => ['nullable', 'string', 'in:on,off'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $device = Device::create([
            ...$validated,
            'state' => $validated['state'] ?? 'off',
            'enabled' => $validated['enabled'] ?? true,
        ]);

        $device->load(['controller', 'room']);

        return response()->json($device, 201);
    }

    public function show(int $id): JsonResponse
    {
        $device = Device::with(['controller', 'room'])
            ->findOrFail($id);

        return response()->json($device);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $device = Device::findOrFail($id);

        $validated = $request->validate([
            'controller_id' => ['sometimes', 'required', 'integer', 'exists:controllers,id'],
            'room_id' => ['sometimes', 'nullable', 'integer', 'exists:rooms,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'identifier' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                'unique:devices,identifier,' . $device->id,
            ],
            'type' => ['sometimes', 'required', 'string', 'max:255'],
            'gpio' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'state' => ['sometimes', 'required', 'string', 'in:on,off'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        $device->update($validated);

        $device->load(['controller', 'room']);

        return response()->json($device);
    }

    public function destroy(int $id): JsonResponse
    {
        $device = Device::findOrFail($id);

        $device->delete();

        return response()->json([
            'message' => 'Dispositivo eliminado correctamente.',
        ]);
    }
}