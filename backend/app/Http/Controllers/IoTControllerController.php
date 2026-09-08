<?php

namespace App\Http\Controllers;

use App\Models\Controller as DeviceControllerModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IoTControllerController extends Controller
{
    public function index(): JsonResponse
    {
        $controllers = DeviceControllerModel::withCount('devices')
            ->orderBy('id')
            ->get();

        return response()->json($controllers);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'identifier' => ['required', 'string', 'max:255', 'unique:controllers,identifier'],
            'type' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:online,offline'],
        ]);

        $controller = DeviceControllerModel::create([
            'name' => $validated['name'],
            'identifier' => $validated['identifier'],
            'type' => $validated['type'] ?? 'esp32',
            'status' => $validated['status'] ?? 'offline',
        ]);

        return response()->json($controller, 201);
    }

    public function show(int $id): JsonResponse
    {
        $controller = DeviceControllerModel::withCount('devices')
            ->with('devices')
            ->findOrFail($id);

        return response()->json($controller);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $controller = DeviceControllerModel::findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'identifier' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                'unique:controllers,identifier,' . $controller->id,
            ],
            'type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', 'string', 'in:online,offline'],
            'last_seen' => ['sometimes', 'nullable', 'date'],
        ]);

        $controller->update($validated);

        return response()->json($controller->fresh());
    }

    public function destroy(int $id): JsonResponse
    {
        $controller = DeviceControllerModel::findOrFail($id);

        $controller->delete();

        return response()->json([
            'message' => 'Controller eliminado correctamente.',
        ]);
    }
}
