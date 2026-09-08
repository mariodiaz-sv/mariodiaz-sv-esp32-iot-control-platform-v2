<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Device extends Model
{
    protected $fillable = [
        'controller_id',
        'room_id',
        'name',
        'identifier',
        'type',
        'gpio',
        'state',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'gpio' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    public function controller(): BelongsTo
    {
        return $this->belongsTo(Controller::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
