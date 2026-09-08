<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Controller extends Model
{
    protected $fillable = [
        'name',
        'identifier',
        'type',
        'status',
        'last_seen',
    ];

    protected function casts(): array
    {
        return [
            'last_seen' => 'datetime',
        ];
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }
}
