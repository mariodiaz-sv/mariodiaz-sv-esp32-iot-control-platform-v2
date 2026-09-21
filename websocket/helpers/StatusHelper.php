<?php

/*
|--------------------------------------------------------------------------
| STATUS HELPER
|--------------------------------------------------------------------------
|
| Construye el estado global de los dispositivos IoT.
|
|--------------------------------------------------------------------------
*/

class StatusHelper
{
    /*
    |--------------------------------------------------------------------------
    | CONSTRUIR STATUS
    |--------------------------------------------------------------------------
    */

    public static function build(array $devices)
    {
        $status = [
            'type' => 'status',
            'devices' => [],
        ];

        foreach ($devices as $deviceId => $device)
        {
            $deviceStatus = [
                'id' =>
                    (int) $deviceId,

                'name' =>
                    isset($device['name'])
                        ? $device['name']
                        : 'ESP32 #' . $deviceId,

                'registered' =>
                    isset($device['registered'])
                        ? (bool) $device['registered']
                        : false,

                'online' =>
                    isset($device['online'])
                        ? (bool) $device['online']
                        : false,

                'lastSeen' =>
                    isset($device['lastSeen'])
                        ? (int) $device['lastSeen']
                        : 0,

                'actuators' => [],

                'sensors' => [],
            ];


            /*
            |--------------------------------------------------------------------------
            | ACTUADORES
            |--------------------------------------------------------------------------
            */

            if (
                isset($device['actuators']) &&
                is_array($device['actuators'])
            )
            {
                foreach (
                    $device['actuators']
                    as $actuatorId => $actuator
                )
                {
                    $deviceStatus['actuators'][] = [
                        'id' =>
                            (int) $actuatorId,

                        'name' =>
                            isset($actuator['name'])
                                ? $actuator['name']
                                : 'Actuador ' . $actuatorId,

                        'type' =>
                            isset($actuator['type'])
                                ? $actuator['type']
                                : 'generic',

                        'gpio' =>
                            isset($actuator['gpio'])
                                ? (int) $actuator['gpio']
                                : 0,

                        'state' =>
                            !empty($actuator['state'])
                                ? 'ON'
                                : 'OFF',
                    ];
                }
            }


            /*
            |--------------------------------------------------------------------------
            | SENSORES
            |--------------------------------------------------------------------------
            */

            if (
                isset($device['sensors']) &&
                is_array($device['sensors'])
            )
            {
                foreach (
                    $device['sensors']
                    as $sensorId => $sensor
                )
                {
                    $deviceStatus['sensors'][] = $sensor;
                }
            }


            $status['devices'][] = $deviceStatus;
        }


        return json_encode(
            $status,
            JSON_UNESCAPED_UNICODE
        );
    }
}
