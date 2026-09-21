<?php

class SensorHelper
{
    /**
     * Combina los datos anteriores del sensor
     * con los nuevos datos recibidos.
     *
     * Los datos nuevos tienen prioridad.
     */
    public static function mergeSensor(
        array $previousSensor,
        array $sensorData
    ): array
    {
        $sensor = array_merge(
            $previousSensor,
            $sensorData
        );

        /*
        |--------------------------------------------------------------------------
        | NOMBRE
        |--------------------------------------------------------------------------
        */

        if (
            !isset($sensor['name']) ||
            !is_string($sensor['name']) ||
            trim($sensor['name']) === ''
        )
        {
            $sensor['name'] =
                'Sensor ' . ($sensor['id'] ?? '0');
        }
        else
        {
            $sensor['name'] =
                trim($sensor['name']);
        }


        /*
        |--------------------------------------------------------------------------
        | TIPO
        |--------------------------------------------------------------------------
        */

        if (
            !isset($sensor['type']) ||
            !is_string($sensor['type']) ||
            trim($sensor['type']) === ''
        )
        {
            $sensor['type'] = 'generic';
        }
        else
        {
            $sensor['type'] =
                trim($sensor['type']);
        }


        /*
        |--------------------------------------------------------------------------
        | SIMULATED
        |--------------------------------------------------------------------------
        */

        if (isset($sensor['simulated']))
        {
            $sensor['simulated'] =
                (bool) $sensor['simulated'];
        }


        return $sensor;
    }
}
