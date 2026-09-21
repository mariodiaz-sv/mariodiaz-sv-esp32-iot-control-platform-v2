<?php

/*
|--------------------------------------------------------------------------
| CONNECTION HELPER
|--------------------------------------------------------------------------
|
| Este archivo contiene funciones auxiliares para gestionar las conexiones
| WebSocket del sistema IoT.
|
| Su función principal es identificar cada conexión mediante su
| resourceId y asociarla con información legible, como:
|
| - Tipo de cliente: ESP32 / Vue
| - Nombre del cliente: ESP32 Cocina / Vue Dashboard
|
| Esto permite que los logs del servidor puedan mostrar información
| más clara que únicamente "Cliente #6" o "Cliente #10".
|
| Este archivo NO contiene la lógica principal del servidor WebSocket.
| Es utilizado como herramienta auxiliar por server_v8.php.
|
|--------------------------------------------------------------------------
*/

class ConnectionHelper
{
    protected $connections = [];


    /*
    |--------------------------------------------------------------------------
    | REGISTRAR CONEXIÓN
    |--------------------------------------------------------------------------
    */

    public function register(
        $connection,
        string $type,
        string $name
    )
    {
        $resourceId =
            $connection->resourceId;

        $this->connections[$resourceId] = [
            'connection' =>
                $connection,

            'type' =>
                $type,

            'name' =>
                $name,
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | OBTENER NOMBRE
    |--------------------------------------------------------------------------
    */

    public function getName($connection)
    {
        $resourceId =
            $connection->resourceId;

        if (
            isset(
                $this->connections[$resourceId]['name']
            )
        )
        {
            return
                $this->connections[$resourceId]['name'];
        }

        return
            'Cliente ' . $resourceId;
    }


    /*
    |--------------------------------------------------------------------------
    | OBTENER TIPO
    |--------------------------------------------------------------------------
    */

    public function getType($connection)
    {
        $resourceId =
            $connection->resourceId;

        if (
            isset(
                $this->connections[$resourceId]['type']
            )
        )
        {
            return
                $this->connections[$resourceId]['type'];
        }

        return 'unknown';
    }


    /*
    |--------------------------------------------------------------------------
    | ELIMINAR CONEXIÓN
    |--------------------------------------------------------------------------
    */

    public function remove($connection)
    {
        $resourceId =
            $connection->resourceId;

        unset(
            $this->connections[$resourceId]
        );
    }
}
