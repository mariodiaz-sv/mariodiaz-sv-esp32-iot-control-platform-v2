<?php

namespace App\Http\Controllers;

use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoomController extends Controller
{
    public function index(): JsonResponse
    {
        $rooms = Room::withCount('devices')
            ->orderBy('id')
            ->get();

        return response()->json($rooms);
    }

   // public function store(Request $request): JsonResponse
    //{
      //  $validated = $request->validate([
      //      'name' => ['required', 'string', 'max:255'],
      //      'description' => ['nullable', 'string'],
      //  ]);

      //  $room = Room::create($validated);

      //  return response()->json($room, 201);
   // }
public function store(Request $request): JsonResponse
{
    $validated = $request->validate([
        'name' => ['required', 'string', 'max:255'],
        'description' => ['nullable', 'string'],
    ]);

    $room = Room::create($validated);

    return response()->json($room, 201);
}

    public function show(Room $room): JsonResponse
    {
        $room->load('devices');

        return response()->json($room);
    }

    public function update(Request $request, Room $room): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $room->update($validated);

        return response()->json($room);
    }

    public function destroy(Room $room): JsonResponse
    {
        $room->delete();

        return response()->json([
            'message' => 'Room deleted successfully',
        ]);
    }
}
