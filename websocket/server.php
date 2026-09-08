<?php

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

require __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;


/*
|--------------------------------------------------------------------------
| IoT CONTROL WEBSOCKET SERVER
|--------------------------------------------------------------------------
|
| El servidor mantiene el estado de todos los dispositivos.
|
| El ESP32:
|
|   1. Se conecta
|   2. Hace handshake WebSocket
|   3. Envía register_device
|   4. Declara sus LEDs
|
| Vue:
|
|   1. Se conecta
|   2. Recibe status
|   3. Muestra exactamente lo que el servidor conoce
|   4. Envía led_toggle
|
| IMPORTANTE:
|
| El servidor NO inventa LEDs.
| El servidor NO asume GPIO 18, 19, etc.
| El ESP32 es quien declara sus LEDs.
|
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
    | DISPOSITIVOS
    |--------------------------------------------------------------------------
    |
    | Estructura:
    |
    | $devices[device_id] = [
    |     'id' => 1,
    |     'name' => 'ESP32 Principal',
    |     'registered' => true,
    |     'online' => true,
    |     'connection' => $connection,
    |     'leds' => [
    |         1 => [
    |             'id' => 1,
    |             'name' => 'LED 1',
    |             'gpio' => 18,
    |             'state' => false,
    |         ],
    |     ],
    | ];
    |
    |--------------------------------------------------------------------------
    */

    protected $devices;


    /*
    |--------------------------------------------------------------------------
    | CONSTRUCTOR
    |--------------------------------------------------------------------------
    */

    public function __construct()
    {
        $this->clients =
            new \SplObjectStorage;


        $this->devices = [];


        echo "\n";
        echo "==============================================\n";
        echo "       IoT CONTROL WEBSOCKET SERVER\n";
        echo "                    V5\n";
        echo "==============================================\n";
        echo "[WS] Servidor iniciado\n";
        echo "[WS] Esperando conexiones...\n";
        echo "==============================================\n";
    }


    /*
    |--------------------------------------------------------------------------
    | STATUS GLOBAL
    |--------------------------------------------------------------------------
    |
    | Este es el mensaje que Vue consume.
    |
    | Vue no necesita saber cuántos LEDs existen.
    | Vue no necesita saber qué GPIO existen.
    |
    | Todo sale de aquí.
    |
    |--------------------------------------------------------------------------
    */

    protected function getStatus()
    {
        $status = [
            'type' => 'status',
            'devices' => [],
        ];


        foreach ($this->devices as $deviceId => $device)
        {
            $deviceStatus = [
                'id' => (int) $deviceId,

                'name' =>
                    $device['name'],

                'registered' =>
                    (bool) $device['registered'],

                'online' =>
                    (bool) $device['online'],

                'leds' => [],
            ];


            /*
            |--------------------------------------------------------------------------
            | LEDS
            |--------------------------------------------------------------------------
            */

            if (
                isset($device['leds']) &&
                is_array($device['leds'])
            )
            {
                foreach (
                    $device['leds']
                    as $ledId => $led
                )
                {
                    $deviceStatus['leds'][] = [
                        'id' => (int) $ledId,

                        'name' =>
                            $led['name'],

                        'gpio' =>
                            (int) $led['gpio'],

                        'state' =>
                            $led['state']
                                ? 'ON'
                                : 'OFF',
                    ];
                }
            }


            $status['devices'][] =
                $deviceStatus;
        }


        return json_encode(
            $status,
            JSON_UNESCAPED_UNICODE
        );
    }


    /*
    |--------------------------------------------------------------------------
    | BROADCAST STATUS
    |--------------------------------------------------------------------------
    |
    | Envía el estado completo a TODOS:
    |
    | - Vue
    | - otros clientes
    |
    |--------------------------------------------------------------------------
    */

    protected function broadcastStatus()
    {
        $message =
            $this->getStatus();


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
    | BUSCAR CONEXIÓN DEL DEVICE
    |--------------------------------------------------------------------------
    */

    protected function getDeviceConnection(
        $deviceId
    )
    {
        if (
            !isset(
                $this->devices[$deviceId]
            )
        )
        {
            return null;
        }


        if (
            !isset(
                $this->devices[$deviceId]['connection']
            )
        )
        {
            return null;
        }


        return
            $this->devices[$deviceId]['connection'];
    }


    /*
    |--------------------------------------------------------------------------
    | VALIDAR GPIO
    |--------------------------------------------------------------------------
    */

    protected function isValidGpio(
        $gpio
    )
    {
        return
            is_numeric($gpio) &&
            (int) $gpio >= 0 &&
            (int) $gpio <= 39;
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
        /*
        |--------------------------------------------------------------------------
        | DEVICE ID
        |--------------------------------------------------------------------------
        */

        if (
            !isset($data['device_id'])
        )
        {
            echo "[WS] Registro rechazado: ";
            echo "device_id faltante\n";

            return false;
        }


        $deviceId =
            (int) $data['device_id'];


        if (
            $deviceId <= 0
        )
        {
            echo "[WS] Registro rechazado: ";
            echo "device_id invalido\n";

            return false;
        }


        /*
        |--------------------------------------------------------------------------
        | NOMBRE
        |--------------------------------------------------------------------------
        */

        $name =
            'ESP32 #' . $deviceId;


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
        | LEDS DECLARADOS POR EL ESP32
        |--------------------------------------------------------------------------
        */

        $leds = [];


        if (
            isset($data['leds']) &&
            is_array($data['leds'])
        )
        {
            foreach (
                $data['leds']
                as $ledData
            )
            {
                if (
                    !is_array($ledData)
                )
                {
                    continue;
                }


                /*
                |--------------------------------------------------------------------------
                | ID
                |--------------------------------------------------------------------------
                */

                if (
                    !isset($ledData['id'])
                )
                {
                    continue;
                }


                $ledId =
                    (int) $ledData['id'];


                if (
                    $ledId <= 0
                )
                {
                    continue;
                }


                /*
                |--------------------------------------------------------------------------
                | GPIO
                |--------------------------------------------------------------------------
                */

                if (
                    !isset($ledData['gpio'])
                )
                {
                    continue;
                }


                $gpio =
                    (int) $ledData['gpio'];


                if (
                    !$this->isValidGpio($gpio)
                )
                {
                    echo "[WS] LED rechazado: ";
                    echo "GPIO invalido\n";

                    continue;
                }


                /*
                |--------------------------------------------------------------------------
                | NOMBRE DEL LED
                |--------------------------------------------------------------------------
                */

                $ledName =
                    'LED ' . $ledId;


                if (
                    isset($ledData['name']) &&
                    is_string($ledData['name']) &&
                    trim($ledData['name']) !== ''
                )
                {
                    $ledName =
                        trim($ledData['name']);
                }


                /*
                |--------------------------------------------------------------------------
                | CONSERVAR ESTADO ANTERIOR
                |--------------------------------------------------------------------------
                |
                | Si el ESP32 se reconecta, no queremos
                | que el servidor cambie arbitrariamente
                | el estado almacenado.
                |
                |--------------------------------------------------------------------------
                */

                $previousState = false;


                if (
                    isset(
                        $this->devices[$deviceId]
                    ) &&
                    isset(
                        $this->devices[$deviceId]['leds'][$ledId]
                    )
                )
                {
                    $previousState =
                        (bool)
                        $this->devices[$deviceId]
                            ['leds'][$ledId]
                            ['state'];
                }


                /*
                |--------------------------------------------------------------------------
                | GUARDAR LED
                |--------------------------------------------------------------------------
                */

                $leds[$ledId] = [
                    'id' =>
                        $ledId,

                    'name' =>
                        $ledName,

                    'gpio' =>
                        $gpio,

                    'state' =>
                        $previousState,
                ];
            }
        }


        /*
        |--------------------------------------------------------------------------
        | DEVICE EXISTENTE
        |--------------------------------------------------------------------------
        */

        if (
            isset(
                $this->devices[$deviceId]
            )
        )
        {
            echo "[WS] Device #{$deviceId}";
            echo " reconectando\n";
        }
        else
        {
            echo "[WS] Device #{$deviceId}";
            echo " nuevo\n";
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

            'connection' =>
                $conn,

            'leds' =>
                $leds,
        ];


        /*
        |--------------------------------------------------------------------------
        | LOG DEL DEVICE
        |--------------------------------------------------------------------------
        */

        echo "\n";
        echo "==============================================\n";
        echo "[WS] DEVICE REGISTRADO\n";
        echo "----------------------------------------------\n";

        echo "[WS] Device ID: ";
        echo $deviceId;
        echo "\n";

        echo "[WS] Nombre: ";
        echo $name;
        echo "\n";

        echo "[WS] Online: SI\n";

        echo "[WS] LEDs: ";
        echo count($leds);
        echo "\n";


        foreach (
            $leds as $ledId => $led
        )
        {
            echo "[WS]   LED #";
            echo $ledId;

            echo " | ";
            echo $led['name'];

            echo " | GPIO ";
            echo $led['gpio'];

            echo " | ";

            echo $led['state']
                ? "ON"
                : "OFF";

            echo "\n";
        }


        echo "==============================================\n";


        /*
        |--------------------------------------------------------------------------
        | CONFIRMACIÓN AL ESP32
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
        | ACTUALIZAR VUE
        |--------------------------------------------------------------------------
        */

        $this->broadcastStatus();


        return true;
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
        $this->clients->attach(
            $conn
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
        | ENVIAR ESTADO ACTUAL
        |--------------------------------------------------------------------------
        |
        | Si Vue se conecta después de que un ESP32
        | ya está conectado, recibirá inmediatamente
        | el estado actual.
        |
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


        if (
            !is_array($data)
        )
        {
            echo "[WS] JSON INVALIDO\n";

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | REGISTRO
        |--------------------------------------------------------------------------
        */

        if (
            isset($data['command']) &&
            $data['command'] ===
                'register_device'
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
        | COMMAND
        |--------------------------------------------------------------------------
        */

        if (
            !isset($data['command'])
        )
        {
            echo "[WS] Comando no especificado\n";

            return;
        }


        $command =
            $data['command'];


        echo "[WS] Command: ";
        echo $command;
        echo "\n";


        /*
        |--------------------------------------------------------------------------
        | LED TOGGLE
        |--------------------------------------------------------------------------
        */

        if (
            $command ===
                'led_toggle'
        )
        {
            $this->toggleLed(
                $from,
                $data
            );

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | LED ON
        |--------------------------------------------------------------------------
        */

        if (
            $command ===
                'led_on'
        )
        {
            $this->setLedState(
                $from,
                $data,
                true
            );

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | LED OFF
        |--------------------------------------------------------------------------
        */

        if (
            $command ===
                'led_off'
        )
        {
            $this->setLedState(
                $from,
                $data,
                false
            );

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | COMANDO DESCONOCIDO
        |--------------------------------------------------------------------------
        */

        echo "[WS] Comando desconocido: ";
        echo $command;
        echo "\n";
    }


    /*
    |--------------------------------------------------------------------------
    | TOGGLE LED
    |--------------------------------------------------------------------------
    */

    protected function toggleLed(
        ConnectionInterface $from,
        array $data
    )
    {
        /*
        |--------------------------------------------------------------------------
        | VALIDAR DEVICE
        |--------------------------------------------------------------------------
        */

        if (
            !isset($data['device_id'])
        )
        {
            echo "[WS] device_id faltante\n";

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | VALIDAR LED
        |--------------------------------------------------------------------------
        */

        if (
            !isset($data['led_id'])
        )
        {
            echo "[WS] led_id faltante\n";

            return;
        }


        $deviceId =
            (int) $data['device_id'];


        $ledId =
            (int) $data['led_id'];


        /*
        |--------------------------------------------------------------------------
        | DEVICE EXISTE
        |--------------------------------------------------------------------------
        */

        if (
            !isset(
                $this->devices[$deviceId]
            )
        )
        {
            echo "[WS] Device #{$deviceId}";
            echo " no existe\n";

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | DEVICE ONLINE
        |--------------------------------------------------------------------------
        */

        if (
            !$this->devices[$deviceId]['online']
        )
        {
            echo "[WS] Device #{$deviceId}";
            echo " esta offline\n";

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | LED EXISTE
        |--------------------------------------------------------------------------
        */

        if (
            !isset(
                $this->devices[$deviceId]
                    ['leds'][$ledId]
            )
        )
        {
            echo "[WS] LED #{$ledId}";
            echo " no existe en Device #";
            echo $deviceId;
            echo "\n";

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
                ['leds'][$ledId]
                ['state'];


        $newState =
            !$currentState;


        /*
        |--------------------------------------------------------------------------
        | GUARDAR ESTADO
        |--------------------------------------------------------------------------
        */

        $this->devices[$deviceId]
            ['leds'][$ledId]
            ['state'] =
                $newState;


        $led =
            $this->devices[$deviceId]
                ['leds'][$ledId];


        echo "\n";
        echo "----------------------------------------------\n";
        echo "[WS] LED TOGGLE\n";
        echo "[WS] Device: ";
        echo $deviceId;
        echo "\n";

        echo "[WS] LED: ";
        echo $ledId;
        echo "\n";

        echo "[WS] GPIO: ";
        echo $led['gpio'];
        echo "\n";

        echo "[WS] Nuevo estado: ";
        echo $newState
            ? "ON"
            : "OFF";
        echo "\n";

        echo "----------------------------------------------\n";


        /*
        |--------------------------------------------------------------------------
        | ENVIAR AL ESP32
        |--------------------------------------------------------------------------
        */

        $deviceConnection =
            $this->getDeviceConnection(
                $deviceId
            );


        if (
            $deviceConnection !== null
        )
        {
            $commandMessage = [
                'type' =>
                    'command',

                'command' =>
                    'led_set',

                'device_id' =>
                    $deviceId,

                'led_id' =>
                    $ledId,

                'gpio' =>
                    $led['gpio'],

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
        | ACTUALIZAR TODOS LOS CLIENTES
        |--------------------------------------------------------------------------
        */

        $this->broadcastStatus();
    }


    /*
    |--------------------------------------------------------------------------
    | SET LED STATE
    |--------------------------------------------------------------------------
    */

    protected function setLedState(
        ConnectionInterface $from,
        array $data,
        bool $state
    )
    {
        /*
        |--------------------------------------------------------------------------
        | VALIDACIÓN
        |--------------------------------------------------------------------------
        */

        if (
            !isset($data['device_id']) ||
            !isset($data['led_id'])
        )
        {
            echo "[WS] device_id o led_id faltante\n";

            return;
        }


        $deviceId =
            (int) $data['device_id'];


        $ledId =
            (int) $data['led_id'];


        /*
        |--------------------------------------------------------------------------
        | DEVICE
        |--------------------------------------------------------------------------
        */

        if (
            !isset(
                $this->devices[$deviceId]
            )
        )
        {
            echo "[WS] Device #{$deviceId}";
            echo " no existe\n";

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
            echo "[WS] Device #{$deviceId}";
            echo " esta offline\n";

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | LED
        |--------------------------------------------------------------------------
        */

        if (
            !isset(
                $this->devices[$deviceId]
                    ['leds'][$ledId]
            )
        )
        {
            echo "[WS] LED #{$ledId}";
            echo " no existe\n";

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | GUARDAR
        |--------------------------------------------------------------------------
        */

        $this->devices[$deviceId]
            ['leds'][$ledId]
            ['state'] =
                $state;


        $led =
            $this->devices[$deviceId]
                ['leds'][$ledId];


        /*
        |--------------------------------------------------------------------------
        | COMANDO
        |--------------------------------------------------------------------------
        */

        $commandMessage = [
            'type' =>
                'command',

            'command' =>
                'led_set',

            'device_id' =>
                $deviceId,

            'led_id' =>
                $ledId,

            'gpio' =>
                $led['gpio'],

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

        $deviceConnection =
            $this->getDeviceConnection(
                $deviceId
            );


        if (
            $deviceConnection !== null
        )
        {
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
        | ACTUALIZAR VUE
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
            $this->devices
            as $deviceId => &$device
        )
        {
            if (
                isset($device['connection']) &&
                $device['connection'] === $conn
            )
            {
                /*
                |--------------------------------------------------------------------------
                | OFFLINE
                |--------------------------------------------------------------------------
                */

                $device['online'] =
                    false;


                $device['connection'] =
                    null;


                echo "[WS] Device #";
                echo $deviceId;
                echo " marcado OFFLINE\n";
            }
        }


        unset($device);


        echo "[WS] Clientes restantes: ";
        echo $this->clients->count();
        echo "\n";


        echo "==============================================\n";


        /*
        |--------------------------------------------------------------------------
        | AVISAR A VUE
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


$server =
    IoServer::factory(
        new HttpServer(
            new WsServer(
                new IoTWebSocketServer()
            )
        ),
        $port
    );


echo "[WS] Servidor listo\n";
echo "[WS] Esperando ESP32 / Vue...\n";
echo "==============================================\n";


$server->run();
