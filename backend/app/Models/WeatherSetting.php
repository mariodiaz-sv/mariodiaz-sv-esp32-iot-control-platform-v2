<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WeatherSetting extends Model
{
    protected $fillable = [
        'city',
        'country',
        'latitude',
        'longitude',
        'timezone',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
    ];
}
