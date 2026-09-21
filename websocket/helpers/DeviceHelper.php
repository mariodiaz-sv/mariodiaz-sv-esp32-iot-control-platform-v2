<?php

/*
|--------------------------------------------------------------------------
| DEVICE HELPER
|--------------------------------------------------------------------------
|
| Funciones auxiliares relacionadas con dispositivos IoT.
|
|--------------------------------------------------------------------------
*/

class DeviceHelper
{
    /*
    |--------------------------------------------------------------------------
    | OBTENER CONEXIÓN DEL DEVICE
    |--------------------------------------------------------------------------
    */

    public static function getConnection(
        array $devices,
        $deviceId
    )
    {
        if (
            !isset($devices[$deviceId])
        )
        {
            return null;
        }

        if (
            !isset(
                $devices[$deviceId]['connection']
            )
        )
        {
            return null;
        }

        return $devices[$deviceId]['connection'];
    }


    /*
    |--------------------------------------------------------------------------
    | VALIDAR GPIO
    |--------------------------------------------------------------------------
    */

    public static function isValidGpio($gpio)
    {
        return
            is_numeric($gpio) &&
            (int) $gpio >= 0 &&
            (int) $gpio <= 39;
    }
}
