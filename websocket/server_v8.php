<?php

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

require __DIR__ . '/vendor/autoload.php';
$deviceConfig = require __DIR__ . '/config/devices.php';//para cargar los nombres del arduinos
require __DIR__ . '/helpers/ConnectionHelper.php';//identificar cada conexión mediante su resourceId y asociarla con información legible
require __DIR__ . '/helpers/DeviceHelper.php';
require __DIR__ . '/helpers/StatusHelper.php';
require __DIR__ . '/helpers/SensorHelper.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

use React\EventLoop\LoopInterface;

/*
|--------------------------------------------------------------------------
| IoT CONTROL WEBSOCKET SERVER
|--------------------------------------------------------------------------
*/

class IoTWebSocketServer implements MessageComponentInterface
{
    /*
    |--------------------------------------------------------------------------
    | CLIENTES
    |--------------------------------------------------------------------------
    */

    protected $clients;


    /*
    |--------------------------------------------------------------------------
    | CONNECTION HELPER
    |--------------------------------------------------------------------------
    |
    | Gestiona la identificación de las conexiones WebSocket,
    | permitiendo asociar cada resourceId con un tipo y nombre.
    |
    |--------------------------------------------------------------------------
    */

    protected $connectionHelper;



    /*
    |--------------------------------------------------------------------------
    | DISPOSITIVOS
    |--------------------------------------------------------------------------
    */

    protected $devices;
    /*
    |--------------------------------------------------------------------------
    | CONFIGURACIÓN DE DISPOSITIVOS
    |--------------------------------------------------------------------------
    */

    protected $deviceConfig;

    /*
    |--------------------------------------------------------------------------
    | CONSTRUCTOR
    |--------------------------------------------------------------------------
    */

    public function __construct()
{
    echo "[DEBUG] Entrando al constructor IoTWebSocketServer\n";

    $this->clients = new \SplObjectStorage;

        $this->connectionHelper =
            new ConnectionHelper;

        $this->devices = [];

        $this->deviceConfig =
            require __DIR__ . '/config/devices.php';

        echo "\n";
        echo "==============================================\n";
        echo "       IoT CONTROL WEBSOCKET SERVER\n";
        echo "                    V8\n";
        echo "==============================================\n";
        echo "[WS] Servidor iniciado\n";
        echo "[WS] Arquitectura: ACTUATORS + SENSORS\n";
        echo "[WS] Heartbeat: ACTIVADO\n";
        echo "[WS] Esperando conexiones...\n";
        echo "==============================================\n";
    }


    /*
    |--------------------------------------------------------------------------
    | STATUS GLOBAL
    |--------------------------------------------------------------------------
    */


    protected function getStatus()
    {
        return StatusHelper::build(
            $this->devices
        );
    }


    /*
    |--------------------------------------------------------------------------
    | BROADCAST STATUS
    |--------------------------------------------------------------------------
    */

    protected function broadcastStatus()
    {
        $message = $this->getStatus();

        echo "\n";
        echo "[WS] ===== BROADCAST STATUS =====\n";
        echo "[WS] {$message}\n";
        echo "[WS] Clientes: {$this->clients->count()}\n";

        foreach ($this->clients as $client)
        {
            try
            {
                $client->send($message);

                echo "[WS] Status enviado -> ";
                echo "cliente {$client->resourceId}\n";
            }
            catch (\Throwable $e)
            {
                echo "[WS] Error enviando status -> ";
                echo $e->getMessage();
                echo "\n";
            }
        }

        echo "[WS] ===== FIN BROADCAST =====\n";
    }
    /*
    |--------------------------------------------------------------------------
    | ACTUALIZAR LAST SEEN
    |--------------------------------------------------------------------------
    */

    protected function touchDevice(
    $deviceId,
    ?ConnectionInterface $connection = null
)
    {
        if (!isset($this->devices[$deviceId]))
        {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | SI SE PROPORCIONA CONEXIÓN, VALIDARLA
        |--------------------------------------------------------------------------
        */

        if (
            $connection !== null &&
            isset($this->devices[$deviceId]['connection']) &&
            $this->devices[$deviceId]['connection'] !== $connection
        )
        {
            return false;
        }


        $this->devices[$deviceId]['online'] = true;
        $this->devices[$deviceId]['registered'] = true;
        $this->devices[$deviceId]['lastSeen'] = time();

        if ($connection !== null)
        {
            $this->devices[$deviceId]['connection'] = $connection;
        }

        return true;
   
    }

/*
|--------------------------------------------------------------------------
| WATCHDOG DE DISPOSITIVOS
|--------------------------------------------------------------------------
|
| Comprueba periódicamente si un ESP32 dejó de enviar heartbeat.
|
|--------------------------------------------------------------------------
*/

public function checkDeviceTimeouts()

{
    $now = time();

    /*
    |--------------------------------------------------------------------------
    | TIMEOUT
    |--------------------------------------------------------------------------
    |
    | Si pasan 90 segundos sin heartbeat,
    | consideramos el dispositivo offline.
    |
    |--------------------------------------------------------------------------
    */

    $timeout = 90;

    $changed = false;


    foreach (
        $this->devices as $deviceId => &$device
    )
    {
        /*
        |--------------------------------------------------------------------------
        | SOLO REVISAR DISPOSITIVOS ONLINE
        |--------------------------------------------------------------------------
        */

        if (
            !isset($device['online']) ||
            !$device['online']
        )
        {
            continue;
        }


        /*
        |--------------------------------------------------------------------------
        | LAST SEEN
        |--------------------------------------------------------------------------
        */

        $lastSeen =
            isset($device['lastSeen'])
                ? (int) $device['lastSeen']
                : 0;


        if ($lastSeen <= 0)
        {
            continue;
        }


        /*
        |--------------------------------------------------------------------------
        | TIMEOUT
        |--------------------------------------------------------------------------
        */

        if (
            ($now - $lastSeen) >= $timeout
        )
        {
            echo "\n";
            echo "[WS] ====================================\n";
            echo "[WS] DEVICE TIMEOUT\n";
            echo "[WS] Device #{$deviceId}\n";
            echo "[WS] LastSeen: {$lastSeen}\n";
            echo "[WS] Ahora: {$now}\n";
            echo "[WS] Sin heartbeat durante {$timeout}s\n";
            echo "[WS] Marcando OFFLINE\n";
            echo "[WS] ====================================\n";


            /*
            |--------------------------------------------------------------------------
            | MARCAR OFFLINE
            |--------------------------------------------------------------------------
            */

            $device['online'] = false;


            /*
            |--------------------------------------------------------------------------
            | IMPORTANTE
            |--------------------------------------------------------------------------
            |
            | NO eliminamos la información del dispositivo.
            |
            | Conservamos:
            |
            | - actuadores
            | - sensores
            | - nombre
            | - lastSeen
            |
            |--------------------------------------------------------------------------
            */

            $changed = true;
        }
    }


    unset($device);


    /*
    |--------------------------------------------------------------------------
    | ACTUALIZAR DASHBOARD
    |--------------------------------------------------------------------------
    */

    if ($changed)
    {
        $this->broadcastStatus();
    }
}

    
    /*
|--------------------------------------------------------------------------
| REGISTRAR CLIENTE
|--------------------------------------------------------------------------
|
| Registra clientes que no son dispositivos IoT,
| por ejemplo Vue Dashboard.
|
|--------------------------------------------------------------------------
*/

protected function registerClient(
    ConnectionInterface $conn,
    array $data
)
{
    /*
    |--------------------------------------------------------------------------
    | TIPO DE CLIENTE
    |--------------------------------------------------------------------------
    */

    $clientType = 'unknown';

    if (
        isset($data['client_type']) &&
        is_string($data['client_type']) &&
        trim($data['client_type']) !== ''
    )
    {
        $clientType =
            trim($data['client_type']);
    }


    /*
    |--------------------------------------------------------------------------
    | NOMBRE
    |--------------------------------------------------------------------------
    */

    $name =
        'Cliente ' . $conn->resourceId;

    if (
        isset($data['name']) &&
        is_string($data['name']) &&
        trim($data['name']) !== ''
    )
    {
        $name =
            trim($data['name']);
    }


    /*
    |--------------------------------------------------------------------------
    | REGISTRAR EN CONNECTION HELPER
    |--------------------------------------------------------------------------
    */

    $this->connectionHelper->register(
        $conn,
        $clientType,
        $name
    );


    /*
    |--------------------------------------------------------------------------
    | LOG
    |--------------------------------------------------------------------------
    */

    echo "\n";
    echo "----------------------------------------------\n";
    echo "[WS] CLIENTE REGISTRADO\n";
    echo "[WS] Resource ID: ";
    echo $conn->resourceId;
    echo "\n";

    echo "[WS] Tipo: ";
    echo $clientType;
    echo "\n";

    echo "[WS] Nombre: ";
    echo $name;
    echo "\n";

    echo "----------------------------------------------\n";


    /*
    |--------------------------------------------------------------------------
    | CONFIRMACIÓN
    |--------------------------------------------------------------------------
    */

    $response = [
        'type' =>
            'client_registration',

        'status' =>
            'ok',

        'client_type' =>
            $clientType,

        'name' =>
            $name,
    ];


    try
    {
        $conn->send(
            json_encode(
                $response,
                JSON_UNESCAPED_UNICODE
            )
        );
    }
    catch (\Throwable $e)
    {
        echo "[WS] Error enviando registro de cliente: ";
        echo $e->getMessage();
        echo "\n";
    }
}


    /*
    |--------------------------------------------------------------------------
    | REGISTRAR DEVICE
    |--------------------------------------------------------------------------
    */

    protected function registerDevice(
        ConnectionInterface $conn,
        array $data
    )
    {
        if (!isset($data['device_id']))
        {
            echo "[WS] Registro rechazado: device_id faltante\n";
            return false;
        }

        $deviceId = (int) $data['device_id'];

        if ($deviceId <= 0)
        {
            echo "[WS] Registro rechazado: device_id invalido\n";
            return false;
        }


        /*
        |--------------------------------------------------------------------------
        | NOMBRE
        |--------------------------------------------------------------------------
        */

        $name = 'ESP32 #' . $deviceId;

        if (
            isset($data['name']) &&
            is_string($data['name']) &&
            trim($data['name']) !== ''
        )
        {
            $name = trim($data['name']);
        }
        /*
        |--------------------------------------------------------------------------
        | IDENTIFICAR CONEXIÓN
        |--------------------------------------------------------------------------
        |
        | La conexión WebSocket queda asociada al nombre del ESP32.
        |
        |--------------------------------------------------------------------------
        */

        $this->connectionHelper->register(
            $conn,
            'esp32',
            $name
        );

        /*
        |--------------------------------------------------------------------------
        | ACTUADORES
        |--------------------------------------------------------------------------
        */

        $actuators = [];

        if (
            isset($data['actuators']) &&
            is_array($data['actuators'])
        )
        {
            foreach (
                $data['actuators'] as $actuatorData
            )
            {
                if (!is_array($actuatorData))
                {
                    continue;
                }

                if (!isset($actuatorData['id']))
                {
                    continue;
                }

                $actuatorId = (int) $actuatorData['id'];

                if ($actuatorId <= 0)
                {
                    continue;
                }

                if (!isset($actuatorData['gpio']))
                {
                    continue;
                }

                $gpio = (int) $actuatorData['gpio'];

                if (!DeviceHelper::isValidGpio($gpio)
                )
                    
                {
                    echo "[WS] Actuador rechazado: GPIO invalido\n";
                    continue;
                }


                /*
                |--------------------------------------------------------------------------
                | NOMBRE
                |--------------------------------------------------------------------------
                */

                $actuatorName =
                    'Actuador ' . $actuatorId;

                if (
                    isset($actuatorData['name']) &&
                    is_string($actuatorData['name']) &&
                    trim($actuatorData['name']) !== ''
                )
                {
                    $actuatorName =
                        trim($actuatorData['name']);
                }


                /*
                |--------------------------------------------------------------------------
                | TIPO
                |--------------------------------------------------------------------------
                */

                $actuatorType = 'generic';

                if (
                    isset($actuatorData['type']) &&
                    is_string($actuatorData['type']) &&
                    trim($actuatorData['type']) !== ''
                )
                {
                    $actuatorType =
                        trim($actuatorData['type']);
                }


                /*
                |--------------------------------------------------------------------------
                | CONSERVAR ESTADO ANTERIOR
                |--------------------------------------------------------------------------
                */

                $previousState = false;

                if (
                    isset($this->devices[$deviceId]) &&
                    isset(
                        $this->devices[$deviceId]
                            ['actuators'][$actuatorId]
                    )
                )
                {
                    $previousState =
                        (bool)
                        $this->devices[$deviceId]
                            ['actuators'][$actuatorId]
                            ['state'];
                }


                $actuators[$actuatorId] = [
                    'id' =>
                        $actuatorId,

                    'name' =>
                        $actuatorName,

                    'type' =>
                        $actuatorType,

                    'gpio' =>
                        $gpio,

                    'state' =>
                        $previousState,
                ];
            }
        }


        /*
|--------------------------------------------------------------------------
| SENSORES
|--------------------------------------------------------------------------
|
| El ESP32 puede enviar sensors[] durante register_device.
|
| Aquí:
|
| 1. Conservamos los sensores anteriores.
| 2. Procesamos los sensores nuevos enviados por el ESP32.
| 3. Conservamos las lecturas anteriores cuando el nuevo registro
|    no las contiene.
| 4. Permitimos campos adicionales enviados por el ESP32.
|
|--------------------------------------------------------------------------
*/

$sensors = [];


/*
|--------------------------------------------------------------------------
| CONSERVAR SENSORES ANTERIORES
|--------------------------------------------------------------------------
*/

if (
    isset($this->devices[$deviceId]) &&
    isset($this->devices[$deviceId]['sensors']) &&
    is_array($this->devices[$deviceId]['sensors'])
)
{
    $sensors =
        $this->devices[$deviceId]['sensors'];
}


/*
|--------------------------------------------------------------------------
| PROCESAR SENSORES RECIBIDOS
|--------------------------------------------------------------------------
*/

if (
    isset($data['sensors']) &&
    is_array($data['sensors'])
)
{
    foreach (
        $data['sensors'] as $sensorData
    )
    {
        if (!is_array($sensorData))
        {
            continue;
        }

        /*
        |--------------------------------------------------------------------------
        | ID
        |--------------------------------------------------------------------------
        */

        if (!isset($sensorData['id']))
        {
            echo "[WS] Sensor rechazado: id faltante\n";
            continue;
        }

        $sensorId =
            (int) $sensorData['id'];

        if ($sensorId <= 0)
        {
            echo "[WS] Sensor rechazado: id invalido\n";
            continue;
        }


        /*
        |--------------------------------------------------------------------------
        | SENSOR ANTERIOR
        |--------------------------------------------------------------------------
        */

        $previousSensor = [];

        if (
            isset($sensors[$sensorId]) &&
            is_array($sensors[$sensorId])
        )
        {
            $previousSensor =
                $sensors[$sensorId];
        }


        /*
|--------------------------------------------------------------------------
| CONSERVAR Y NORMALIZAR DATOS DEL SENSOR
|--------------------------------------------------------------------------
*/

$sensor =
    SensorHelper::mergeSensor(
        $previousSensor,
        $sensorData
    );


/*
|--------------------------------------------------------------------------
| GPIO
|--------------------------------------------------------------------------
*/

if (isset($sensor['gpio']))
{
    $gpio =
        (int) $sensor['gpio'];

    if (DeviceHelper::isValidGpio($gpio))
    {
        $sensor['gpio'] =
            $gpio;
    }
    else
    {
        /*
        |--------------------------------------------------------------------------
        | Si el GPIO nuevo es inválido, conservar el anterior
        |--------------------------------------------------------------------------
        */

        if (isset($previousSensor['gpio']))
        {
            $sensor['gpio'] =
                $previousSensor['gpio'];
        }
        else
        {
            unset($sensor['gpio']);
        }
    }
}

        /*
        |--------------------------------------------------------------------------
        | GUARDAR SENSOR
        |--------------------------------------------------------------------------
        */

        $sensors[$sensorId] =
            $sensor;


        /*
        |--------------------------------------------------------------------------
        | LOG
        |--------------------------------------------------------------------------
        */

        echo "[WS] Sensor registrado";
        echo " #{$sensorId}";

        if (isset($sensor['name']))
        {
            echo " | {$sensor['name']}";
        }

        if (isset($sensor['type']))
        {
            echo " | tipo {$sensor['type']}";
        }

        if (isset($sensor['gpio']))
        {
            echo " | GPIO {$sensor['gpio']}";
        }

        echo "\n";
    }
}



     


        /*
        |--------------------------------------------------------------------------
        | DEVICE EXISTENTE
        |--------------------------------------------------------------------------
        */

        if (
            isset($this->devices[$deviceId])
        )
        {
            echo "[WS] Device #{$deviceId} reconectando\n";
        }
        else
        {
            echo "[WS] Device #{$deviceId} nuevo\n";
        }


        /*
        |--------------------------------------------------------------------------
        | GUARDAR DEVICE
        |--------------------------------------------------------------------------
        */

        $this->devices[$deviceId] = [
            'id' =>
                $deviceId,

            'name' =>
                $name,

            'registered' =>
                true,

            'online' =>
                true,

            'lastSeen' =>
                time(),

            'connection' =>
                $conn,

            'actuators' =>
                $actuators,

            'sensors' =>
                $sensors,
        ];


        /*
        |--------------------------------------------------------------------------
        | LOG
        |--------------------------------------------------------------------------
        */

        echo "\n";
        echo "==============================================\n";
        echo "[WS] DEVICE REGISTRADO\n";
        echo "----------------------------------------------\n";

        echo "[WS] Device ID: {$deviceId}\n";
        echo "[WS] Nombre: {$name}\n";
        echo "[WS] Online: SI\n";
        echo "[WS] LastSeen: ";
        echo $this->devices[$deviceId]['lastSeen'];
        echo "\n";

        echo "[WS] Actuadores: ";
        echo count($actuators);
        echo "\n";

        foreach (
            $actuators as $actuatorId => $actuator
        )
        {
            echo "[WS]   ACTUATOR #{$actuatorId}";
            echo " | {$actuator['name']}";
            echo " | tipo {$actuator['type']}";
            echo " | GPIO {$actuator['gpio']}";
            echo " | ";
            echo $actuator['state'] ? "ON" : "OFF";
            echo "\n";
        }

        echo "[WS] Sensores: ";
echo count($sensors);
echo "\n";

foreach (
    $sensors as $sensorId => $sensor
)
{
    echo "[WS]   SENSOR #{$sensorId}";

    if (isset($sensor['name']))
    {
        echo " | {$sensor['name']}";
    }

    if (isset($sensor['type']))
    {
        echo " | tipo {$sensor['type']}";
    }

    if (isset($sensor['gpio']))
    {
        echo " | GPIO {$sensor['gpio']}";
    }

    if (isset($sensor['temperature']))
    {
        echo " | ";
        echo $sensor['temperature'];
        echo " °C";
    }

    echo "\n";
}

echo "===================****===========================\n";



        /*
        |--------------------------------------------------------------------------
        | CONFIRMACIÓN
        |--------------------------------------------------------------------------
        */

        $response = [
            'type' =>
                'registration',

            'status' =>
                'ok',

            'device_id' =>
                $deviceId,
        ];

        try
        {
            $conn->send(
                json_encode(
                    $response,
                    JSON_UNESCAPED_UNICODE
                )
            );
        }
        catch (\Throwable $e)
        {
            echo "[WS] Error enviando registro: ";
            echo $e->getMessage();
            echo "\n";
        }


        /*
        |--------------------------------------------------------------------------
        | ACTUALIZAR DASHBOARD
        |--------------------------------------------------------------------------
        */

        $this->broadcastStatus();

        return true;
    }


    /*
    |--------------------------------------------------------------------------
    | HEARTBEAT
    |--------------------------------------------------------------------------
    |
    | Recibe:
    |
    | {
    |     "type": "heartbeat",
    |     "device_id": 1
    | }
    |
    |--------------------------------------------------------------------------
    */

    protected function handleHeartbeat(
        ConnectionInterface $from,
        array $data
    )
    {
        if (!isset($data['device_id']))
        {
            echo "[WS] heartbeat rechazado: device_id faltante\n";
            return;
        }

        $deviceId = (int) $data['device_id'];

        if ($deviceId <= 0)
        {
            echo "[WS] heartbeat rechazado: device_id invalido\n";
            return;
        }


        /*
        |--------------------------------------------------------------------------
        | DEVICE NO REGISTRADO
        |--------------------------------------------------------------------------
        */

        if (!isset($this->devices[$deviceId]))
        {
            echo "[WS] Heartbeat recibido de Device #{$deviceId}";
            echo " pero todavía no está registrado\n";

            /*
            |--------------------------------------------------------------------------
            | RESPUESTA AL CLIENTE
            |--------------------------------------------------------------------------
            */

            try
            {
                $from->send(
                    json_encode(
                        [
                            'type' =>
                                'heartbeat_ack',

                            'status' =>
                                'not_registered',

                            'device_id' =>
                                $deviceId,
                        ],
                        JSON_UNESCAPED_UNICODE
                    )
                );
            }
            catch (\Throwable $e)
            {
                echo "[WS] Error enviando heartbeat_ack: ";
                echo $e->getMessage();
                echo "\n";
            }

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | VALIDAR CONEXIÓN
        |--------------------------------------------------------------------------
        |
        | Esto evita que una conexión vieja actualice el estado de una
        | conexión nueva del mismo dispositivo.
        |
        |--------------------------------------------------------------------------
        */

        if (
            isset($this->devices[$deviceId]['connection']) &&
            $this->devices[$deviceId]['connection'] !== $from
        )
        {
            echo "[WS] Heartbeat ignorado: conexión antigua";
            echo " para Device #{$deviceId}\n";

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | ACTUALIZAR ONLINE + LAST SEEN
        |--------------------------------------------------------------------------
        */
/*
        $this->devices[$deviceId]['online'] = true;
        $this->devices[$deviceId]['registered'] = true;
        $this->devices[$deviceId]['connection'] = $from;
        $this->devices[$deviceId]['lastSeen'] = time();*/
        $this->touchDevice(
            $deviceId,
            $from
        );

        /*
        |--------------------------------------------------------------------------
        | LOG
        |--------------------------------------------------------------------------
        */

        echo "[WS] HEARTBEAT <- Device #{$deviceId}";
        echo " | lastSeen=";
        echo $this->devices[$deviceId]['lastSeen'];
        echo "\n";


        /*
        |--------------------------------------------------------------------------
        | ACK
        |--------------------------------------------------------------------------
        */

        $response = [
            'type' =>
                'heartbeat_ack',

            'status' =>
                'ok',

            'device_id' =>
                $deviceId,

            'lastSeen' =>
                $this->devices[$deviceId]['lastSeen'],
        ];

        try
        {
            $from->send(
                json_encode(
                    $response,
                    JSON_UNESCAPED_UNICODE
                )
            );
        }
        catch (\Throwable $e)
        {
            echo "[WS] Error enviando heartbeat_ack: ";
            echo $e->getMessage();
            echo "\n";
        }


        /*
        |--------------------------------------------------------------------------
        | ACTUALIZAR DASHBOARD
        |--------------------------------------------------------------------------
        */

        $this->broadcastStatus();
    }


    /*
    |--------------------------------------------------------------------------
    | DEVICE STATE
    |--------------------------------------------------------------------------
    */

    protected function handleDeviceState(
        ConnectionInterface $from,
        array $data
    )
    {
        if (!isset($data['device_id']))
        {
            echo "[WS] device_state rechazado: device_id faltante\n";
            return;
        }

        $deviceId = (int) $data['device_id'];

        if ($deviceId <= 0)
        {
            echo "[WS] device_state rechazado: device_id invalido\n";
            return;
        }


        /*
        |--------------------------------------------------------------------------
        | DEVICE NO REGISTRADO
        |--------------------------------------------------------------------------
        */

        if (!isset($this->devices[$deviceId]))
        {
            echo "[WS] device_state recibido de Device #{$deviceId}";
            echo " pero todavía no está registrado\n";
            return;
        }


        /*
        |--------------------------------------------------------------------------
        | ACTUALIZAR CONEXIÓN
        |--------------------------------------------------------------------------
        */
        $this->touchDevice(
            $deviceId,
            $from
        );

        /*
        |--------------------------------------------------------------------------
        | ACTUADORES
        |--------------------------------------------------------------------------
        */

        if (
            isset($data['actuators']) &&
            is_array($data['actuators'])
        )
        {
            foreach (
                $data['actuators'] as $actuatorData
            )
            {
                if (!is_array($actuatorData))
                {
                    continue;
                }

                if (!isset($actuatorData['id']))
                {
                    continue;
                }

                $actuatorId =
                    (int) $actuatorData['id'];

                if ($actuatorId <= 0)
                {
                    continue;
                }


                /*
                |--------------------------------------------------------------------------
                | EL ACTUADOR DEBE EXISTIR
                |--------------------------------------------------------------------------
                */

                if (
                    !isset(
                        $this->devices[$deviceId]
                            ['actuators'][$actuatorId]
                    )
                )
                {
                    echo "[WS] Actuator #{$actuatorId}";
                    echo " no existe en Device #{$deviceId}";
                    echo " - ignorado\n";

                    continue;
                }


                /*
                |--------------------------------------------------------------------------
                | ESTADO
                |--------------------------------------------------------------------------
                */

                if (isset($actuatorData['state']))
                {
                    $state =
                        strtoupper(
                            trim(
                                (string)
                                $actuatorData['state']
                            )
                        );

                    if ($state === 'ON')
                    {
                        $this->devices[$deviceId]
                            ['actuators'][$actuatorId]
                            ['state'] = true;
                    }
                    elseif ($state === 'OFF')
                    {
                        $this->devices[$deviceId]
                            ['actuators'][$actuatorId]
                            ['state'] = false;
                    }
                }


                /*
                |--------------------------------------------------------------------------
                | GPIO
                |--------------------------------------------------------------------------
                */

                if (isset($actuatorData['gpio']))
                {
                    $gpio =
                        (int) $actuatorData['gpio'];

                    if (DeviceHelper::isValidGpio($gpio))
                    {
                        $this->devices[$deviceId]
                            ['actuators'][$actuatorId]
                            ['gpio'] = $gpio;
                    }
                }
            }
        }


        /*
|--------------------------------------------------------------------------
| SENSOR DENTRO DE DEVICE STATE
|--------------------------------------------------------------------------
*/

    if (
    isset($data['sensor']) &&
    is_array($data['sensor'])
    )
    {
        $sensor = $data['sensor'];

        if (isset($sensor['id']))
        {
            $sensorId = (int) $sensor['id'];

            if ($sensorId > 0)
            {
                $previousSensor = [];

                if (
                    isset(
                        $this->devices[$deviceId]
                            ['sensors'][$sensorId]
                    ) &&
                    is_array(
                        $this->devices[$deviceId]
                            ['sensors'][$sensorId]
                    )
                )
                {
                    $previousSensor =
                        $this->devices[$deviceId]
                            ['sensors'][$sensorId];
                }

                $this->devices[$deviceId]
                    ['sensors'][$sensorId] =
                        SensorHelper::mergeSensor(
                            $previousSensor,
                            $sensor
                        );
            }
        }
    }



        /*
        |--------------------------------------------------------------------------
        | LOG
        |--------------------------------------------------------------------------
        */

        echo "\n";
        echo "----------------------------------------------\n";
        echo "[WS] DEVICE STATE\n";
        echo "[WS] Device: {$deviceId}\n";
        echo "[WS] LastSeen: ";
        echo $this->devices[$deviceId]['lastSeen'];
        echo "\n";

        if (isset($data['confirmed']))
        {
            echo "[WS] Confirmado: ";
            echo $data['confirmed'] ? "SI" : "NO";
            echo "\n";
        }

        if (
            isset($data['actuators']) &&
            is_array($data['actuators'])
        )
        {
            echo "[WS] Actuadores recibidos: ";
            echo count($data['actuators']);
            echo "\n";
        }

        if (
            isset($data['sensor']) &&
            is_array($data['sensor'])
        )
        {
            echo "[WS] Sensor recibido\n";

            if (isset($data['sensor']['temperature']))
            {
                echo "[WS] Temperatura: ";
                echo $data['sensor']['temperature'];
                echo " °C\n";
            }

            if (isset($data['sensor']['humidity']))
            {
                echo "[WS] Humedad: ";
                echo $data['sensor']['humidity'];
                echo " %\n";
            }
        }

        echo "----------------------------------------------\n";


        /*
        |--------------------------------------------------------------------------
        | ACTUALIZAR VUE
        |--------------------------------------------------------------------------
        */

        $this->broadcastStatus();
    }


    /*
    |--------------------------------------------------------------------------
    | SENSOR STATE
    |--------------------------------------------------------------------------
    */

    protected function handleSensorState(
        ConnectionInterface $from,
        array $data
    )
    {
        if (!isset($data['device_id']))
        {
            echo "[WS] sensor_state rechazado: device_id faltante\n";
            return;
        }

        $deviceId = (int) $data['device_id'];

        if ($deviceId <= 0)
        {
            echo "[WS] sensor_state rechazado: device_id invalido\n";
            return;
        }

        if (!isset($this->devices[$deviceId]))
        {
            echo "[WS] sensor_state recibido de Device #{$deviceId}";
            echo " pero todavía no está registrado\n";
            return;
        }


        /*
        |--------------------------------------------------------------------------
        | ACTUALIZAR CONEXIÓN
        |--------------------------------------------------------------------------
        */
/*
        $this->devices[$deviceId]['online'] = true;
        $this->devices[$deviceId]['registered'] = true;
        $this->devices[$deviceId]['connection'] = $from;
        $this->devices[$deviceId]['lastSeen'] = time();*/
        $this->touchDevice(
            $deviceId,
            $from
        );

        /*
        |--------------------------------------------------------------------------
        | SENSOR
        |--------------------------------------------------------------------------
        */

        if (
            !isset($data['sensor']) ||
            !is_array($data['sensor'])
        )
        {
            echo "[WS] sensor_state sin objeto sensor\n";
            return;
        }

        $sensor = $data['sensor'];

        if (!isset($sensor['id']))
        {
            echo "[WS] sensor_state rechazado: sensor.id faltante\n";
            return;
        }

        $sensorId = (int) $sensor['id'];

        if ($sensorId <= 0)
        {
            echo "[WS] sensor_state rechazado: sensor.id invalido\n";
            return;
        }


        /*
        |--------------------------------------------------------------------------
        | GUARDAR SENSOR
        |--------------------------------------------------------------------------
        */
/*
        $this->devices[$deviceId]
            ['sensors'][$sensorId] = $sensor;

*/$previousSensor = [];

if (
    isset($this->devices[$deviceId]['sensors'][$sensorId]) &&
    is_array($this->devices[$deviceId]['sensors'][$sensorId])
)
{
    $previousSensor =
        $this->devices[$deviceId]['sensors'][$sensorId];
}

$this->devices[$deviceId]['sensors'][$sensorId] =
    SensorHelper::mergeSensor(
        $previousSensor,
        $sensor
    );


        /*
        |--------------------------------------------------------------------------
        | LOG
        |--------------------------------------------------------------------------
        */

        echo "\n";
        echo "----------------------------------------------\n";
        echo "[WS] SENSOR STATE\n";
        echo "[WS] Device: {$deviceId}\n";
        echo "[WS] Sensor: {$sensorId}\n";
        echo "[WS] LastSeen: ";
        echo $this->devices[$deviceId]['lastSeen'];
        echo "\n";

        if (isset($sensor['name']))
        {
            echo "[WS] Nombre: ";
            echo $sensor['name'];
            echo "\n";
        }

        if (isset($sensor['type']))
        {
            echo "[WS] Tipo: ";
            echo $sensor['type'];
            echo "\n";
        }

        if (isset($sensor['temperature']))
        {
            echo "[WS] Temperatura: ";
            echo $sensor['temperature'];
            echo " °C\n";
        }

        if (isset($sensor['humidity']))
        {
            echo "[WS] Humedad: ";
            echo $sensor['humidity'];
            echo " %\n";
        }

        echo "----------------------------------------------\n";


        /*
        |--------------------------------------------------------------------------
        | ACTUALIZAR VUE
        |--------------------------------------------------------------------------
        */

        $this->broadcastStatus();
    }


    /*
    |--------------------------------------------------------------------------
    | ON OPEN
    |--------------------------------------------------------------------------
    */

    public function onOpen(
        ConnectionInterface $conn
    )
    {
        $this->clients->attach($conn);
         $this->connectionHelper->register(
        $conn,
        'unknown',
        'Cliente ' . $conn->resourceId
        );
        echo "\n";
        echo "==============================================\n";
        echo "[WS] NUEVA CONEXION\n";
        echo "----------------------------------------------\n";

        echo "[WS] Resource ID: ";
        echo $conn->resourceId;
        echo "\n";

        echo "[WS] Clientes: ";
        echo $this->clients->count();
        echo "\n";

        echo "==============================================\n";


        /*
        |--------------------------------------------------------------------------
        | ESTADO INICIAL
        |--------------------------------------------------------------------------
        */

        try
        {
            $conn->send(
                $this->getStatus()
            );
        }
        catch (\Throwable $e)
        {
            echo "[WS] Error enviando estado inicial: ";
            echo $e->getMessage();
            echo "\n";
        }
    }


    /*
    |--------------------------------------------------------------------------
    | ON MESSAGE
    |--------------------------------------------------------------------------
    */

    public function onMessage(
        ConnectionInterface $from,
        $msg
    )
    {
        echo "\n";
        echo "==============================================\n";
        echo "[WS] MENSAJE RECIBIDO\n";
        echo "----------------------------------------------\n";

        echo "[WS] Cliente: ";
        echo $from->resourceId;
        echo " | ";
        echo $this->connectionHelper->getName($from);
        echo " | tipo=";
        echo $this->connectionHelper->getType($from);
        echo "\n";

        echo "[WS] Mensaje: ";
        echo $msg;
        echo "\n";

        echo "==============================================\n";


        /*
        |--------------------------------------------------------------------------
        | JSON
        |--------------------------------------------------------------------------
        */

        $data =
            json_decode(
                $msg,
                true
            );

        if (!is_array($data))
        {
            echo "[WS] JSON INVALIDO\n";
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | REGISTRO DE CLIENTE
        |--------------------------------------------------------------------------
        */

        if (
            isset($data['type']) &&
            $data['type'] === 'register_client'
        )
        {
            $this->registerClient(
                $from,
                $data
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | REGISTRO
        |--------------------------------------------------------------------------
        */

        if (
            isset($data['command']) &&
            $data['command'] === 'register_device'
        )
        {
            $this->registerDevice(
                $from,
                $data
            );

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | HEARTBEAT
        |--------------------------------------------------------------------------
        |
        | IMPORTANTE:
        | Debe revisarse ANTES de exigir command.
        |
        |--------------------------------------------------------------------------
        */

        if (
            isset($data['type']) &&
            $data['type'] === 'heartbeat'
        )
        {
            $this->handleHeartbeat(
                $from,
                $data
            );

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | DEVICE STATE
        |--------------------------------------------------------------------------
        */

        if (
            isset($data['type']) &&
            $data['type'] === 'device_state'
        )
        {
            $this->handleDeviceState(
                $from,
                $data
            );

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | SENSOR STATE
        |--------------------------------------------------------------------------
        */

        if (
            isset($data['type']) &&
            $data['type'] === 'sensor_state'
        )
        {
            $this->handleSensorState(
                $from,
                $data
            );

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | COMMAND
        |--------------------------------------------------------------------------
        */

        if (!isset($data['command']))
        {
            echo "[WS] Mensaje sin command/type reconocido\n";
            return;
        }


        /*
        |--------------------------------------------------------------------------
        | COMMAND
        |--------------------------------------------------------------------------
        */

        $command =
            $data['command'];

        echo "[WS] Command: ";
        echo $command;
        echo "\n";


        /*
        |--------------------------------------------------------------------------
        | ACTUATOR TOGGLE
        |--------------------------------------------------------------------------
        */

        if (
            $command === 'actuator_toggle'
        )
        {
            $this->toggleActuator(
                $from,
                $data
            );

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | ACTUATOR ON
        |--------------------------------------------------------------------------
        */

        if (
            $command === 'actuator_on'
        )
        {
            $this->setActuatorState(
                $from,
                $data,
                true
            );

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | ACTUATOR OFF
        |--------------------------------------------------------------------------
        */

        if (
            $command === 'actuator_off'
        )
        {
            $this->setActuatorState(
                $from,
                $data,
                false
            );

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | COMPATIBILIDAD LEGACY
        |--------------------------------------------------------------------------
        */

        if (
            $command === 'led_toggle'
        )
        {
            $this->toggleActuator(
                $from,
                $data,
                true
            );

            return;
        }


        if (
            $command === 'led_on'
        )
        {
            $this->setActuatorState(
                $from,
                $data,
                true,
                true
            );

            return;
        }


        if (
            $command === 'led_off'
        )
        {
            $this->setActuatorState(
                $from,
                $data,
                false,
                true
            );

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | DESCONOCIDO
        |--------------------------------------------------------------------------
        */

        echo "[WS] Comando desconocido: ";
        echo $command;
        echo "\n";
    }


    /*
    |--------------------------------------------------------------------------
    | TOGGLE ACTUATOR
    |--------------------------------------------------------------------------
    */

    protected function toggleActuator(
        ConnectionInterface $from,
        array $data,
        bool $legacy = false
    )
    {
        if (!isset($data['device_id']))
        {
            echo "[WS] device_id faltante\n";
            return;
        }


        /*
        |--------------------------------------------------------------------------
        | ACTUATOR ID
        |--------------------------------------------------------------------------
        */

        $actuatorId = null;

        if (isset($data['actuator_id']))
        {
            $actuatorId =
                (int) $data['actuator_id'];
        }
        elseif (
            $legacy &&
            isset($data['led_id'])
        )
        {
            $actuatorId =
                (int) $data['led_id'];
        }

        if (
            $actuatorId === null ||
            $actuatorId <= 0
        )
        {
            echo "[WS] actuator_id faltante\n";
            return;
        }


        $deviceId =
            (int) $data['device_id'];


        /*
        |--------------------------------------------------------------------------
        | DEVICE
        |--------------------------------------------------------------------------
        */

        if (!isset($this->devices[$deviceId]))
        {
            echo "[WS] Device #{$deviceId} no existe\n";
            return;
        }


        /*
|--------------------------------------------------------------------------
| VALIDAR CONEXIÓN DEL DEVICE
|--------------------------------------------------------------------------
|
| El comando puede venir desde Vue.
| $from representa al cliente que envió
| el comando, no necesariamente al ESP32.
|
| La conexión del ESP32 se obtiene mediante
| getDeviceConnection().
|
*/

if (
    !isset(
        $this->devices[$deviceId]['connection']
    )
)
{
    echo "[WS] Device #{$deviceId} no tiene conexión\n";
    return;
}



        /*
        |--------------------------------------------------------------------------
        | ONLINE
        |--------------------------------------------------------------------------
        */

        if (
            !$this->devices[$deviceId]['online']
        )
        {
            echo "[WS] Device #{$deviceId} esta offline\n";
            return;
        }


        /*
        |--------------------------------------------------------------------------
        | ACTUATOR
        |--------------------------------------------------------------------------
        */

        if (
            !isset(
                $this->devices[$deviceId]
                    ['actuators'][$actuatorId]
            )
        )
        {
            echo "[WS] Actuator #{$actuatorId}";
            echo " no existe en Device #{$deviceId}\n";
            return;
        }


        /*
        |--------------------------------------------------------------------------
        | CAMBIAR ESTADO
        |--------------------------------------------------------------------------
        */

        $currentState =
            (bool)
            $this->devices[$deviceId]
                ['actuators'][$actuatorId]
                ['state'];

        $newState =
            !$currentState;


        $this->devices[$deviceId]
            ['actuators'][$actuatorId]
            ['state'] =
                $newState;


        $actuator =
            $this->devices[$deviceId]
                ['actuators'][$actuatorId];


        echo "\n";
        echo "----------------------------------------------\n";
        echo "[WS] ACTUATOR TOGGLE\n";
        echo "[WS] Device: {$deviceId}\n";
        echo "[WS] Actuator: {$actuatorId}\n";
        echo "[WS] Nombre: {$actuator['name']}\n";
        echo "[WS] Tipo: {$actuator['type']}\n";
        echo "[WS] GPIO: {$actuator['gpio']}\n";
        echo "[WS] Nuevo estado: ";
        echo $newState ? "ON" : "OFF";
        echo "\n";
        echo "----------------------------------------------\n";


        /*
        |--------------------------------------------------------------------------
        | ENVIAR AL ESP32
        |--------------------------------------------------------------------------
        */
        
        $deviceConnection =
        DeviceHelper::getConnection(
            $this->devices,
            $deviceId
        );

           

        if ($deviceConnection !== null)
        {
            $commandMessage = [
                'type' =>
                    'command',

                'command' =>
                    'actuator_set',

                'device_id' =>
                    $deviceId,

                'actuator_id' =>
                    $actuatorId,

                'gpio' =>
                    $actuator['gpio'],

                'state' =>
                    $newState
                        ? 'ON'
                        : 'OFF',
            ];

            $jsonCommand =
                json_encode(
                    $commandMessage,
                    JSON_UNESCAPED_UNICODE
                );

            echo "[WS] TX -> ESP32: ";
            echo $jsonCommand;
            echo "\n";

            try
            {
                $deviceConnection->send(
                    $jsonCommand
                );
            }
            catch (\Throwable $e)
            {
                echo "[WS] Error enviando comando: ";
                echo $e->getMessage();
                echo "\n";
            }
        }


        /*
        |--------------------------------------------------------------------------
        | ACTUALIZAR DASHBOARD
        |--------------------------------------------------------------------------
        */

        $this->broadcastStatus();
    }


    /*
    |--------------------------------------------------------------------------
    | SET ACTUATOR STATE
    |--------------------------------------------------------------------------
    */

    protected function setActuatorState(
        ConnectionInterface $from,
        array $data,
        bool $state,
        bool $legacy = false
    )
    {
        if (!isset($data['device_id']))
        {
            echo "[WS] device_id faltante\n";
            return;
        }


        $actuatorId = null;

        if (isset($data['actuator_id']))
        {
            $actuatorId =
                (int) $data['actuator_id'];
        }
        elseif (
            $legacy &&
            isset($data['led_id'])
        )
        {
            $actuatorId =
                (int) $data['led_id'];
        }

        if (
            $actuatorId === null ||
            $actuatorId <= 0
        )
        {
            echo "[WS] actuator_id faltante\n";
            return;
        }


        $deviceId =
            (int) $data['device_id'];


        /*
        |--------------------------------------------------------------------------
        | DEVICE
        |--------------------------------------------------------------------------
        */

        if (!isset($this->devices[$deviceId]))
        {
            echo "[WS] Device #{$deviceId} no existe\n";
            return;
        }
/*
|--------------------------------------------------------------------------
| VALIDAR CONEXIÓN DEL ESP32
|--------------------------------------------------------------------------
*/

$deviceConnection =
    DeviceHelper::getConnection(
        $this->devices,
        $deviceId
    );


if ($deviceConnection === null)
{
    echo "[WS] Device #{$deviceId} no tiene conexión ESP32\n";
    return;
}
        /*
        |--------------------------------------------------------------------------
        | ONLINE
        |--------------------------------------------------------------------------
        */

        if (
            !$this->devices[$deviceId]['online']
        )
        {
            echo "[WS] Device #{$deviceId} esta offline\n";
            return;
        }


        /*
        |--------------------------------------------------------------------------
        | ACTUATOR
        |--------------------------------------------------------------------------
        */

        if (
            !isset(
                $this->devices[$deviceId]
                    ['actuators'][$actuatorId]
            )
        )
        {
            echo "[WS] Actuator #{$actuatorId} no existe\n";
            return;
        }


        /*
        |--------------------------------------------------------------------------
        | GUARDAR ESTADO
        |--------------------------------------------------------------------------
        */

        $this->devices[$deviceId]
            ['actuators'][$actuatorId]
            ['state'] =
                $state;


        $actuator =
            $this->devices[$deviceId]
                ['actuators'][$actuatorId];


        /*
        |--------------------------------------------------------------------------
        | COMANDO PARA ESP32
        |--------------------------------------------------------------------------
        */

        $commandMessage = [
            'type' =>
                'command',

            'command' =>
                'actuator_set',

            'device_id' =>
                $deviceId,

            'actuator_id' =>
                $actuatorId,

            'gpio' =>
                $actuator['gpio'],

            'state' =>
                $state
                    ? 'ON'
                    : 'OFF',
        ];


        $jsonCommand =
            json_encode(
                $commandMessage,
                JSON_UNESCAPED_UNICODE
            );


       /*
|--------------------------------------------------------------------------
| ENVIAR AL ESP32
|--------------------------------------------------------------------------
*/

echo "[WS] TX -> ESP32: ";
echo $jsonCommand;
echo "\n";

try
{
    $deviceConnection->send(
        $jsonCommand
    );
}
catch (\Throwable $e)
{
    echo "[WS] Error enviando comando: ";
    echo $e->getMessage();
    echo "\n";
}


        /*
        |--------------------------------------------------------------------------
        | ACTUALIZAR DASHBOARD
        |--------------------------------------------------------------------------
        */

        $this->broadcastStatus();
    }


    /*
    |--------------------------------------------------------------------------
    | ON CLOSE
    |--------------------------------------------------------------------------
    */

    public function onClose(
        ConnectionInterface $conn
    )
    {
        echo "\n";
        echo "==============================================\n";
        echo "[WS] CONEXION CERRADA\n";
        echo "----------------------------------------------\n";

        echo "[WS] Resource ID: ";
        echo $conn->resourceId;
        echo "\n";


        /*
        |--------------------------------------------------------------------------
        | QUITAR CLIENTE
        |--------------------------------------------------------------------------
        */

        $this->clients->detach(
            $conn
        );


        /*
        |--------------------------------------------------------------------------
        | BUSCAR DEVICE
        |--------------------------------------------------------------------------
        */

        foreach (
            $this->devices as $deviceId => &$device
        )
        {
            /*
            |--------------------------------------------------------------------------
            | IMPORTANTE
            |--------------------------------------------------------------------------
            |
            | Solo marcar offline si ESTA conexión sigue siendo la conexión
            | actual del dispositivo.
            |
            | Si el ESP32 se reconectó y tiene otra conexión, no debemos
            | marcarlo offline por el cierre de la conexión anterior.
            |
            |--------------------------------------------------------------------------
            */

            if (
                    isset($device['connection']) &&
                    $device['connection'] === $conn
                )
                {
                    $device['online'] = false;
                    $device['connection'] = null;

                    echo "[WS] Device #{$deviceId}";
                    echo " marcado OFFLINE\n";

                    /*
                    |--------------------------------------------------------------------------
                    | LAST SEEN
                    |--------------------------------------------------------------------------
                    |
                    | NO modificamos lastSeen aquí.
                    |
                    | lastSeen representa el último momento en que
                    | el servidor recibió actividad válida del ESP32.
                    |
                    |--------------------------------------------------------------------------
                    */

                    echo "[WS] LastSeen conservado: ";
                    echo $device['lastSeen'] ?? 0;
                    echo "\n";
                }

        }

        unset($device);


        echo "[WS] Clientes restantes: ";
        echo $this->clients->count();
        echo "\n";

        echo "==============================================\n";


        /*
        |--------------------------------------------------------------------------
        | AVISAR AL DASHBOARD
        |--------------------------------------------------------------------------
        */

        $this->broadcastStatus();
    }


    /*
    |--------------------------------------------------------------------------
    | ON ERROR
    |--------------------------------------------------------------------------
    */

    public function onError(
        ConnectionInterface $conn,
        \Exception $e
    )
    {
        echo "\n";
        echo "[WS] ======================================\n";
        echo "[WS] ERROR WEBSOCKET\n";
        echo "[WS] {$e->getMessage()}\n";
        echo "[WS] ======================================\n";

        $conn->close();
    }
}


/*
|--------------------------------------------------------------------------
| SERVIDOR
|--------------------------------------------------------------------------
*/

$port =
    getenv('PORT') ?: 8080;


echo "\n";
echo "==============================================\n";
echo "[WS] PUERTO: {$port}\n";
echo "[WS] HOST: 0.0.0.0\n";
echo "==============================================\n";

$iotServer =
    new IoTWebSocketServer();



$wsServer =
    new WsServer(
        $iotServer
    );



$httpServer =
    new HttpServer(
        $wsServer
    );



$server =
    IoServer::factory(
        $httpServer,
        $port
    );


//prueba

echo "[WS] Servidor listo\n";
echo "[WS] Esperando ESP32 / Vue...\n";
echo "==============================================\n";


// =====================================================
// WATCHDOG DE DISPOSITIVOS
// =====================================================

$server->loop->addPeriodicTimer(
    5,
    function () use ($iotServer)
    {
        $iotServer->checkDeviceTimeouts();
    }
);
$server->run();
