#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <ArduinoJson.h>
#include <math.h>
// =====================================================
// ENTORNO
// =====================================================
//
// 1 = DESARROLLO LOCAL
// 0 = PRODUCCION
//
// DESARROLLO:
// ESP32 -> ws://192.168.188.15:8080
//
// PRODUCCION:
// ESP32 -> wss://sistema-websocket.onrender.com:443
//
// =====================================================

#define DEVELOPMENT_MODE 1


// =====================================================
// WIFI
// =====================================================

//const char *ssid = "DESKTOP-8T2K0LU 2080";
//const char *password = "14i^804X";
const char *ssid = "CLARO_2.4GHz_4E8E03";
const char *password = "ptn9ZRhmf+EEcpX";

// =====================================================
// IDENTIDAD DEL DISPOSITIVO
// =====================================================

const int DEVICE_ID = 1;

const char *DEVICE_NAME = "ESP32 Principal";


// =====================================================
// CONFIGURACION DE ACTUADORES
// =====================================================

struct ActuatorConfig
{
  int id;
  const char *name;
  const char *type;
  int gpio;
};


// GPIO compatibles con ESP32 DevKit V1 clasico
ActuatorConfig actuators[] =
{
  {1, "Luz de cuarto", "light", 18},
  {2, "Luz de garage", "light", 19},
  {3, "Ventilador", "fan", 22},
  {4, "Portón", "gate", 23}
};


const size_t ACTUATOR_COUNT =
  sizeof(actuators) / sizeof(actuators[0]);


// =====================================================
// =====================================================
// SENSOR NTC 10K
// =====================================================
// =====================================================
//
// NTC conectado mediante divisor de tension:
//
//              3.3V
//                |
//              NTC 10K
//                |
//                +---------- GPIO34
//                |
//              10K ohm
//                |
//               GND
//
// GPIO34 = ADC1-6
//
// IMPORTANTE:
// GPIO34 es solamente entrada, por lo que es adecuado
// para leer el NTC.
//
// El ADC del ESP32 trabaja con 12 bits:
// 0 - 4095
//
// =====================================================

#define NTC_PIN 34

// Resistencia fija utilizada en el divisor
const double NTC_FIXED_RESISTOR = 10000.0;

// Resistencia nominal del NTC
const double NTC_NOMINAL_RESISTANCE = 10000.0;

// Temperatura nominal del NTC
const double NTC_NOMINAL_TEMPERATURE = 25.0;

// Coeficiente Beta.
// IMPORTANTE:
// Este valor debe coincidir con el NTC que estas usando.
//
// 3950 es un valor muy comun para NTC 10K.
// Si tu datasheet indica otro valor, cambialo aqui.
const double NTC_BETA = 3950.0;


// -----------------------------------------------------
// LEER TEMPERATURA DEL NTC 10K
// -----------------------------------------------------
//
// Utilizamos el ADC de 12 bits del ESP32.
//
// El divisor utilizado es:
//
//             3.3V
//               |
//             NTC
//               |
//               +------ ADC
//               |
//             10K
//               |
//              GND
//
// Por tanto:
//
// Rntc = Rfija * ADC / (4095 - ADC)
//
// Luego utilizamos la ecuacion Beta:
//
// 1/T = 1/T0 + (1/Beta) * ln(R/R0)
//
// El resultado de T esta en Kelvin y posteriormente
// se convierte a Celsius.
//
// -----------------------------------------------------

float readNTCTemperature()
{
  const int ADC_MAX = 4095;

  int rawADC =
    analogRead(NTC_PIN);


  // Evitar division entre cero
  if (
    rawADC <= 0 ||
    rawADC >= ADC_MAX
  )
  {
    return NAN;
  }


  // ---------------------------------------------------
  // CALCULAR RESISTENCIA DEL NTC
  // ---------------------------------------------------

  double resistance =
    NTC_FIXED_RESISTOR *
    ((double)rawADC /
    (double)(ADC_MAX - rawADC));


  // ---------------------------------------------------
  // ECUACION BETA
  // ---------------------------------------------------

  double temperatureKelvin =
    1.0 /
    (
      (1.0 /
       (NTC_NOMINAL_TEMPERATURE + 273.15))
      +
      (
        log(
          resistance /
          NTC_NOMINAL_RESISTANCE
        )
        /
        NTC_BETA
      )
    );


  // ---------------------------------------------------
  // KELVIN -> CELSIUS
  // ---------------------------------------------------

  double temperatureCelsius =
    temperatureKelvin - 273.15;


  return (float)temperatureCelsius;
}


// -----------------------------------------------------
// CONFIGURAR NTC
// -----------------------------------------------------

void configureNTC()
{
  Serial.println();

  Serial.println(
    "[NTC] ===================================="
  );

  Serial.println(
    "[NTC] Configurando sensor NTC 10K"
  );

  Serial.print(
    "[NTC] GPIO: "
  );

  Serial.println(
    NTC_PIN
  );

  Serial.println(
    "[NTC] ADC: 12 bits (0-4095)"
  );

  Serial.println(
    "[NTC] Resistencia fija: 10K"
  );

  Serial.println(
    "[NTC] Resistencia NTC nominal: 10K"
  );

  Serial.print(
    "[NTC] Beta: "
  );

  Serial.println(
    NTC_BETA
  );


  // Resolucion ADC del ESP32
  analogReadResolution(12);


  // Atenuacion para permitir un rango mayor
  // de tension en la entrada ADC.
  analogSetPinAttenuation(
    NTC_PIN,
    ADC_11db
  );


  pinMode(
    NTC_PIN,
    INPUT
  );


  Serial.println(
    "[NTC] Sensor configurado"
  );

  Serial.println(
    "[NTC] ===================================="
  );
}


// -----------------------------------------------------
// MOSTRAR LECTURA NTC POR SERIAL
// -----------------------------------------------------

void printNTCReading()
{
  int rawADC =
    analogRead(NTC_PIN);


  float temperature =
    readNTCTemperature();


  Serial.print(
    "[NTC] ADC: "
  );

  Serial.print(
    rawADC
  );


  Serial.print(
    " | Temperatura: "
  );


  if (
    isnan(temperature)
  )
  {
    Serial.println(
      "ERROR"
    );
  }
  else
  {
    Serial.print(
      temperature,
      2
    );

    Serial.println(
      " C"
    );
  }
}


// =====================================================
// =====================================================
// FIN SENSOR NTC 10K
// =====================================================
// =====================================================




// =====================================================
// INTERVALO SENSOR / ESTADO
// =====================================================

unsigned long lastSensorUpdate = 0;

const unsigned long SENSOR_INTERVAL = 5000;

unsigned long lastDeviceStatus = 0;

const unsigned long DEVICE_STATUS_INTERVAL = 5000;


// =====================================================
// SERVIDOR WEBSOCKET
// =====================================================

#if DEVELOPMENT_MODE

const char *websocketHost = "192.168.1.40";

const uint16_t websocketPort = 8080;

const char *websocketPath = "/";

WiFiClient client;

#else

const char *websocketHost =
  "sistema-websocket.onrender.com";

const uint16_t websocketPort = 443;

const char *websocketPath = "/";

WiFiClientSecure client;

#endif


// =====================================================
// ESTADO WEBSOCKET
// =====================================================

bool websocketConnected = false;

unsigned long lastReconnect = 0;

const unsigned long RECONNECT_INTERVAL = 5000;
//prueba para ver se cae el wifi
bool lastWiFiConnected =false;

// =====================================================
// HEARTBEAT
// =====================================================
//
// El ping/pong WebSocket sirve para comprobar la conexion
// TCP/WebSocket.
//
// Adicionalmente enviamos un heartbeat JSON para que el
// backend pueda actualizar lastSeen del dispositivo.
//
// =====================================================

unsigned long lastHeartbeat = 0;

unsigned long lastPong = 0;

const unsigned long HEARTBEAT_INTERVAL = 15000;

// IMPORTANTE:
// Antes el ESP32 se desconectaba si no recibia PONG en
// 45 segundos.
//
// Ahora usamos un timeout mas tolerante.
// El heartbeat de aplicacion tambien mantiene activo
// al dispositivo.
//
// =====================================================

const unsigned long PONG_TIMEOUT = 90000;


// =====================================================
// TIMEOUT DE LECTURA WEBSOCKET
// =====================================================

const unsigned long WS_READ_TIMEOUT = 5000;


// =====================================================
// WIFI RECONNECT
// =====================================================

unsigned long lastWiFiReconnect = 0;

const unsigned long WIFI_RECONNECT_INTERVAL = 10000;


// =====================================================
// PROTOTIPOS
// =====================================================

bool connectWebSocket();

void processWebSocket();

void sendPing();

void sendPong(
  const uint8_t *payload,
  size_t payloadLength
);

String generateWebSocketKey();

void processCommand(
  JsonDocument &doc
);

void setActuator(
  int actuatorId,
  int gpio,
  const char *state
);

void sendRegistration();

void sendDeviceStatus();

void sendSensorStatus();

void sendHeartbeat();

void configureActuators();

void printActuatorConfiguration();

int findActuatorIndex(
  int actuatorId
);

bool sendWebSocketText(
  const String &message
);

void updateSimulatedDHT11();

bool readExact(
  uint8_t *buffer,
  size_t length,
  unsigned long timeout
);

bool discardBytes(
  uint64_t length,
  unsigned long timeout
);

void handleWiFiReconnect();

void disconnectWebSocket(
  const char *reason
);


// =====================================================
// DESCONECTAR WEBSOCKET
// =====================================================

void disconnectWebSocket(
  const char *reason
)
{
  Serial.println();

  Serial.println(
    "[WS] ===================================="
  );

  Serial.print(
    "[WS] DESCONECTANDO: "
  );

  Serial.println(
    reason
  );

  Serial.println(
    "[WS] ===================================="
  );


  websocketConnected =
    false;


  client.stop();


  lastReconnect =
    millis();
}


// =====================================================
// GENERAR CLAVE WEBSOCKET
// =====================================================

String generateWebSocketKey()
{
  uint8_t randomBytes[16];

  for (int i = 0; i < 16; i++)
  {
    randomBytes[i] =
      random(0, 256);
  }

  const char *base64 =
    "ABCDEFGHIJKLMNOPQRSTUVWXYZ"
    "abcdefghijklmnopqrstuvwxyz"
    "0123456789+/";

  String result = "";

  for (int i = 0; i < 16; i += 3)
  {
    uint32_t value = 0;

    value |=
      ((uint32_t)randomBytes[i]) << 16;

    if (i + 1 < 16)
    {
      value |=
        ((uint32_t)randomBytes[i + 1]) << 8;
    }

    if (i + 2 < 16)
    {
      value |=
        randomBytes[i + 2];
    }

    result +=
      base64[(value >> 18) & 0x3F];

    result +=
      base64[(value >> 12) & 0x3F];

    if (i + 1 < 16)
    {
      result +=
        base64[(value >> 6) & 0x3F];
    }
    else
    {
      result += "=";
    }

    if (i + 2 < 16)
    {
      result +=
        base64[value & 0x3F];
    }
    else
    {
      result += "=";
    }
  }

  return result;
}


// =====================================================
// ENVIAR TEXTO POR WEBSOCKET
// =====================================================

bool sendWebSocketText(
  const String &message
)
{
  if (!websocketConnected)
  {
    return false;
  }

  if (!client.connected())
  {
    Serial.println(
      "[WS] Socket no conectado al intentar enviar"
    );

    websocketConnected = false;

    return false;
  }

  uint8_t mask[4];

  for (int i = 0; i < 4; i++)
  {
    mask[i] =
      random(0, 256);
  }

  size_t length =
    message.length();


  // ===================================================
  // FRAME TEXTO
  // ===================================================

  if (
    client.write((uint8_t)0x81) != 1
  )
  {
    Serial.println(
      "[WS] Error escribiendo header"
    );

    websocketConnected = false;

    return false;
  }


  // ===================================================
  // LONGITUD
  // ===================================================

  if (length <= 125)
  {
    if (
      client.write(
        (uint8_t)(0x80 | length)
      ) != 1
    )
    {
      websocketConnected = false;
      return false;
    }
  }

  else if (length <= 65535)
  {
    if (
      client.write(
        (uint8_t)(0x80 | 126)
      ) != 1
    )
    {
      websocketConnected = false;
      return false;
    }

    if (
      client.write(
        (uint8_t)((length >> 8) & 0xFF)
      ) != 1
    )
    {
      websocketConnected = false;
      return false;
    }

    if (
      client.write(
        (uint8_t)(length & 0xFF)
      ) != 1
    )
    {
      websocketConnected = false;
      return false;
    }
  }

  else
  {
    Serial.println(
      "[WS] Mensaje demasiado grande"
    );

    return false;
  }


  // ===================================================
  // MASCARA
  // ===================================================

  if (
    client.write(
      mask,
      4
    ) != 4
  )
  {
    Serial.println(
      "[WS] Error escribiendo mascara"
    );

    websocketConnected = false;

    return false;
  }


  // ===================================================
  // PAYLOAD
  // ===================================================

  for (
    size_t i = 0;
    i < length;
    i++
  )
  {
    uint8_t c =
      message[i] ^
      mask[i % 4];

    if (
      client.write(c) != 1
    )
    {
      Serial.println(
        "[WS] Error escribiendo payload"
      );

      Serial.println();
  Serial.println("========================================");
  Serial.println("[WS] ERROR ESCRIBIENDO PAYLOAD");
  Serial.println("========================================");

  Serial.print("[WS] millis(): ");
  Serial.println(millis());

  Serial.print("[WS] WiFi status: ");
  Serial.println(WiFi.status());

  Serial.print("[WS] WiFi RSSI: ");
  Serial.print(WiFi.RSSI());
  Serial.println(" dBm");

  Serial.print("[WS] WiFi IP: ");
  Serial.println(WiFi.localIP());

  Serial.print("[WS] TCP connected: ");
  Serial.println(
    client.connected()
      ? "SI"
      : "NO"
  );

  Serial.print("[WS] Payload length: ");
  Serial.println(length);

  Serial.print("[WS] Byte donde fallo: ");
  Serial.println(i);

  Serial.print("[WS] Mensaje: ");
  Serial.println(message);

  Serial.println("========================================");

  websocketConnected = false;

  return false;
    }
  }


  return true;
}


// =====================================================
// CONFIGURAR ACTUADORES
// =====================================================

void configureActuators()
{
  Serial.println();

  Serial.println(
    "[ACTUATOR] Configurando GPIO..."
  );

  for (
    size_t i = 0;
    i < ACTUATOR_COUNT;
    i++
  )
  {
    int gpio =
      actuators[i].gpio;

    if (
      gpio < 0 ||
      gpio > 39
    )
    {
      Serial.print(
        "[ACTUATOR] GPIO invalido: "
      );

      Serial.println(
        gpio
      );

      continue;
    }

    pinMode(
      gpio,
      OUTPUT
    );

    digitalWrite(
      gpio,
      LOW
    );

    Serial.print(
      "[ACTUATOR] "
    );

    Serial.print(
      actuators[i].name
    );

    Serial.print(
      " ["
    );

    Serial.print(
      actuators[i].type
    );

    Serial.print(
      "] -> GPIO "
    );

    Serial.print(
      gpio
    );

    Serial.println(
      " -> OFF"
    );
  }

  Serial.println(
    "[ACTUATOR] Configuracion completada"
  );
}


// =====================================================
// MOSTRAR CONFIGURACION
// =====================================================

void printActuatorConfiguration()
{
  Serial.println();

  Serial.println(
    "[HARDWARE] ===================================="
  );

  Serial.print(
    "[HARDWARE] Total de actuadores: "
  );

  Serial.println(
    ACTUATOR_COUNT
  );

  for (
    size_t i = 0;
    i < ACTUATOR_COUNT;
    i++
  )
  {
    Serial.print(
      "[HARDWARE] "
    );

    Serial.print(
      actuators[i].name
    );

    Serial.print(
      " ["
    );

    Serial.print(
      actuators[i].type
    );

    Serial.print(
      "] -> GPIO "
    );

    Serial.println(
      actuators[i].gpio
    );
  }

  Serial.println(
    "[HARDWARE] ===================================="
  );

 // ===================================================
  // NTC
  // ===================================================

  Serial.println();

  Serial.println(
    "[SENSOR] *** NTC 10K ***"
  );

  Serial.println(
    "[SENSOR] Tipo: temperatura"
  );

  Serial.print(
    "[SENSOR] GPIO: "
  );

  Serial.println(
    NTC_PIN
  );

  Serial.println(
    "[SENSOR] ADC: 12 bits"
  );

}


// =====================================================
// CONECTAR WEBSOCKET
// =====================================================

bool connectWebSocket()
{
  Serial.println();

  Serial.println(
    "========================================"
  );

  Serial.println(
    "[WS] CONECTANDO..."
  );

  Serial.println(
    "========================================"
  );


#if DEVELOPMENT_MODE

  Serial.println(
    "[WS] Entorno: DESARROLLO LOCAL"
  );

#else

  Serial.println(
    "[WS] Entorno: PRODUCCION"
  );

#endif


  Serial.print(
    "[WS] Servidor: "
  );

  Serial.println(
    websocketHost
  );

  Serial.print(
    "[WS] Puerto: "
  );

  Serial.println(
    websocketPort
  );

  Serial.print(
    "[WS] Device ID: "
  );

  Serial.println(
    DEVICE_ID
  );

  Serial.print(
    "[WS] Device Name: "
  );

  Serial.println(
    DEVICE_NAME
  );


  websocketConnected =
    false;

  client.stop();


  // ===================================================
  // TCP / TLS
  // ===================================================

#if DEVELOPMENT_MODE

  Serial.println(
    "[LOCAL] Conectando por TCP..."
  );

#else

  client.setInsecure();

  Serial.println(
    "[TLS] Conectando por TLS..."
  );

#endif
//prueba
Serial.print("[TEST] TCP hacia ");
Serial.print(websocketHost);
Serial.print(":");
Serial.println(websocketPort);
//prueba

  if (
    !client.connect(
      websocketHost,
      websocketPort
    )
  )
  
  {
#if DEVELOPMENT_MODE

    Serial.println(
      "[LOCAL] ERROR DE CONEXION"
    );

#else

    Serial.println(
      "[TLS] ERROR DE CONEXION"
    );

#endif

    return false;
  }


#if DEVELOPMENT_MODE

  Serial.println(
    "[LOCAL] CONEXION TCP EXITOSA"
  );

#else

  Serial.println(
    "[TLS] CONEXION TLS EXITOSA"
  );

#endif


  // ===================================================
  // HANDSHAKE
  // ===================================================

  String wsKey =
    generateWebSocketKey();


#if DEVELOPMENT_MODE

  String origin =
    "http://" +
    String(websocketHost);

#else

  String origin =
    "https://" +
    String(websocketHost);

#endif


  String request =
    "GET " +
    String(websocketPath) +
    " HTTP/1.1\r\n"
    "Host: " +
    String(websocketHost) +
    "\r\n"
    "Upgrade: websocket\r\n"
    "Connection: Upgrade\r\n"
    "Sec-WebSocket-Key: " +
    wsKey +
    "\r\n"
    "Sec-WebSocket-Version: 13\r\n"
    "Origin: " +
    origin +
    "\r\n"
    "\r\n";


  Serial.println(
    "[WS] Enviando handshake..."
  );

  client.print(
    request
  );


  // ===================================================
  // RESPUESTA HTTP
  // ===================================================

  unsigned long timeout =
    millis() + 10000;

  String statusLine = "";

  while (
    client.connected() &&
    millis() < timeout
  )
  {
    if (
      client.available()
    )
    {
      String line =
        client.readStringUntil('\n');

      line.trim();

      if (
        statusLine.length() == 0
      )
      {
        statusLine =
          line;

        Serial.print(
          "[WS] Respuesta: "
        );

        Serial.println(
          statusLine
        );
      }

      if (
        line.length() == 0
      )
      {
        break;
      }
    }

    delay(5);
  }


  // ===================================================
  // COMPROBAR 101
  // ===================================================

  if (
    statusLine.indexOf("101") < 0
  )
  {
    Serial.println(
      "[WS] HANDSHAKE RECHAZADO"
    );

    client.stop();

    return false;
  }


  Serial.println(
    "[WS] 101 SWITCHING PROTOCOLS"
  );

  websocketConnected =
    true;


  // ===================================================
  // INICIALIZAR HEARTBEAT
  // ===================================================

  unsigned long now =
    millis();

  lastHeartbeat =
    now;

  lastPong =
    now;


  Serial.println(
    "[WS] WEBSOCKET CONECTADO"
  );


  // ===================================================
  // REGISTRO
  // ===================================================

  sendRegistration();


  // ===================================================
  // ESTADO INICIAL
  // ===================================================

  delay(100);

  sendDeviceStatus();


  Serial.println(
    "[WS] DISPOSITIVO REGISTRADO Y ACTIVO"
  );


  return true;
}


// =====================================================
// REGISTRO DEL DISPOSITIVO
// =====================================================

void sendRegistration()
{
  if (
    !websocketConnected
  )
  {
    return;
  }


  JsonDocument doc;


  doc["command"] =
    "register_device";


  doc["device_id"] =
    DEVICE_ID;


  doc["name"] =
    DEVICE_NAME;


  // ===================================================
  // ACTUADORES
  // ===================================================

  JsonArray actuatorArray =
    doc["actuators"].to<JsonArray>();


  for (
    size_t i = 0;
    i < ACTUATOR_COUNT;
    i++
  )
  {
    JsonObject actuator =
      actuatorArray.add<JsonObject>();

    actuator["id"] =
      actuators[i].id;

    actuator["name"] =
      actuators[i].name;

    actuator["type"] =
      actuators[i].type;

    actuator["gpio"] =
      actuators[i].gpio;
  }


 // ===================================================
  // SENSOR NTC 10K
  // ===================================================

  JsonArray sensorArray =
    doc["sensors"].to<JsonArray>();


  JsonObject ntc =
    sensorArray.add<JsonObject>();


  ntc["id"] =
    1;


  ntc["name"] =
    "Sala: Temperatura";


  ntc["type"] =
    "temperature";


  ntc["gpio"] =
    NTC_PIN;


  ntc["simulated"] =
    false;



  // ===================================================
  // SERIALIZAR
  // ===================================================

  String message;


  serializeJson(
    doc,
    message
  );


  Serial.println(
    "[WS] REGISTRANDO DISPOSITIVO"
  );


  Serial.print(
    "[WS] TX: "
  );


  Serial.println(
    message
  );


  if (
    sendWebSocketText(
      message
    )
  )
  {
    Serial.println(
      "[WS] REGISTRO ENVIADO"
    );
  }
}


// =====================================================
// HEARTBEAT DE APLICACION
// =====================================================
//
// Este mensaje es independiente del PING/PONG WebSocket.
//
// Su funcion es permitir que el backend sepa que el
// dispositivo sigue activo y actualice su lastSeen.
//
// =====================================================

void sendHeartbeat()
{
  if (
    !websocketConnected
  )
  {
    return;
  }


  if (
    !client.connected()
  )
  {
    websocketConnected =
      false;

    return;
  }


  JsonDocument doc;


  doc["type"] =
    "heartbeat";


  doc["device_id"] =
    DEVICE_ID;


  doc["timestamp"] =
    millis();


  String message;


  serializeJson(
    doc,
    message
  );


  Serial.print(
    "[HEARTBEAT] TX: "
  );

  Serial.println(
    message
  );


  if (
    !sendWebSocketText(
      message
    )
  )
  {
    Serial.println(
      "[HEARTBEAT] ERROR enviando heartbeat"
    );
  }
  else
  {
    Serial.println(
      "[HEARTBEAT] OK"
    );
  }
}


// =====================================================
// ENVIAR ESTADO DE ACTUADORES
// =====================================================

void sendDeviceStatus()
{
  if (
    !websocketConnected
  )
  {
    return;
  }


  if (
    !client.connected()
  )
  {
    websocketConnected =
      false;

    return;
  }


  JsonDocument doc;


  doc["type"] =
    "device_state";


  doc["device_id"] =
    DEVICE_ID;


  doc["confirmed"] =
    true;


  JsonArray actuatorArray =
    doc["actuators"].to<JsonArray>();


  // ===================================================
  // LEER GPIO
  // ===================================================

  for (
    size_t i = 0;
    i < ACTUATOR_COUNT;
    i++
  )
  {
    int gpio =
      actuators[i].gpio;


    int gpioState =
      digitalRead(gpio);


    bool state =
      gpioState == HIGH;


    JsonObject actuator =
      actuatorArray.add<JsonObject>();


    actuator["id"] =
      actuators[i].id;


    actuator["gpio"] =
      gpio;


    actuator["state"] =
      state
        ? "ON"
        : "OFF";
  }


  // ===================================================
  // SENSOR NTC 10K
  // ===================================================

  float temperature =
    readNTCTemperature();


  JsonObject sensor =
    doc["sensor"].to<JsonObject>();


  sensor["id"] =
    1;


  sensor["name"] =
    "Sala: Temperatura";


  sensor["type"] =
    "temperature";


  sensor["gpio"] =
    NTC_PIN;


  sensor["simulated"] =
    false;


  if (
    !isnan(temperature)
  )
  {
    sensor["temperature"] =
      temperature;
  }




  // ===================================================
  // SERIALIZAR
  // ===================================================

  String message;


  serializeJson(
    doc,
    message
  );


  Serial.print(
    "[STATUS] TX: "
  );


  Serial.println(
    message
  );


  if (
    !sendWebSocketText(
      message
    )
  )
  {
    Serial.println(
      "[STATUS] Error enviando estado"
    );
  }
}


// =====================================================
// ENVIAR ESTADO DEL SENSOR NTC
// =====================================================

void sendSensorStatus()
{
  if (
    !websocketConnected
  )
  {
    return;
  }


  // ===================================================
  // LEER NTC
  // ===================================================

  float temperature =
    readNTCTemperature();


  // Mostrar tambien por Serial
  printNTCReading();


  // ===================================================
  // CREAR JSON
  // ===================================================

  JsonDocument doc;


  doc["type"] =
    "sensor_state";


  doc["device_id"] =
    DEVICE_ID;


  JsonObject sensor =
    doc["sensor"].to<JsonObject>();


  sensor["id"] =
    1;


  sensor["name"] =
    "Sala: Temperatura";


  sensor["type"] =
    "temperature";


  sensor["gpio"] =
    NTC_PIN;


  sensor["simulated"] =
    false;


  if (
    !isnan(temperature)
  )
  {
    sensor["temperature"] =
      temperature;
  }


  // ===================================================
  // SERIALIZAR
  // ===================================================

  String message;


  serializeJson(
    doc,
    message
  );


  Serial.print(
    "[SENSOR] *** NTC 10K *** TX: "
  );


  Serial.println(
    message
  );


  if (
    !sendWebSocketText(
      message
    )
  )
  {
    Serial.println(
      "[SENSOR] Error enviando sensor"
    );
  }
}

// =====================================================
// ENVIAR PING
// =====================================================

void sendPing()
{
  if (
    !websocketConnected
  )
  {
    return;
  }


  if (
    !client.connected()
  )
  {
    websocketConnected =
      false;

    return;
  }


  uint8_t mask[4];


  for (
    int i = 0;
    i < 4;
    i++
  )
  {
    mask[i] =
      random(0, 256);
  }


  // ===================================================
  // FIN + PING
  // ===================================================

  if (
    client.write(
      (uint8_t)0x89
    ) != 1
  )
  {
    Serial.println(
      "[WS] ERROR enviando PING"
    );

    websocketConnected =
      false;

    return;
  }


  // ===================================================
  // MASK + PAYLOAD LENGTH = 0
  // ===================================================

  if (
    client.write(
      (uint8_t)0x80
    ) != 1
  )
  {
    Serial.println(
      "[WS] ERROR enviando header PING"
    );

    websocketConnected =
      false;

    return;
  }


  // ===================================================
  // MASCARA
  // ===================================================

  if (
    client.write(
      mask,
      4
    ) != 4
  )
  {
    Serial.println(
      "[WS] ERROR enviando mascara PING"
    );

    websocketConnected =
      false;

    return;
  }


  Serial.println(
    "[WS] PING enviado"
  );
}


// =====================================================
// ENVIAR PONG
// =====================================================

void sendPong(
  const uint8_t *payload,
  size_t payloadLength
)
{
  if (
    !client.connected()
  )
  {
    websocketConnected =
      false;

    return;
  }


  uint8_t mask[4];


  for (
    int i = 0;
    i < 4;
    i++
  )
  {
    mask[i] =
      random(0, 256);
  }


  // ===================================================
  // FIN + PONG
  // ===================================================

  if (
    client.write(
      (uint8_t)0x8A
    ) != 1
  )
  {
    websocketConnected =
      false;

    return;
  }


  // ===================================================
  // LONGITUD
  // ===================================================

  if (
    payloadLength <= 125
  )
  {
    if (
      client.write(
        (uint8_t)(0x80 | payloadLength)
      ) != 1
    )
    {
      websocketConnected =
        false;

      return;
    }
  }
  else
  {
    Serial.println(
      "[WS] PING con payload demasiado grande"
    );

    return;
  }


  // ===================================================
  // MASCARA
  // ===================================================

  if (
    client.write(
      mask,
      4
    ) != 4
  )
  {
    websocketConnected =
      false;

    return;
  }


  // ===================================================
  // PAYLOAD
  // ===================================================

  for (
    size_t i = 0;
    i < payloadLength;
    i++
  )
  {
    uint8_t c =
      payload[i] ^
      mask[i % 4];

    if (
      client.write(c) != 1
    )
    {
      websocketConnected =
        false;

      return;
    }
  }


  Serial.println(
    "[WS] PONG enviado"
  );
}


// =====================================================
// BUSCAR ACTUADOR
// =====================================================

int findActuatorIndex(
  int actuatorId
)
{
  for (
    size_t i = 0;
    i < ACTUATOR_COUNT;
    i++
  )
  {
    if (
      actuators[i].id ==
      actuatorId
    )
    {
      return i;
    }
  }


  return -1;
}


// =====================================================
// CONTROL DE ACTUADOR
// =====================================================

void setActuator(
  int actuatorId,
  int gpio,
  const char *state
)
{
  if (
    state == nullptr ||
    strlen(state) == 0
  )
  {
    Serial.println(
      "[ACTUATOR] Estado invalido"
    );

    return;
  }


  bool on =
    strcmp(
      state,
      "ON"
    ) == 0;


  bool off =
    strcmp(
      state,
      "OFF"
    ) == 0;


  if (
    !on &&
    !off
  )
  {
    Serial.print(
      "[ACTUATOR] Estado desconocido: "
    );

    Serial.println(
      state
    );

    return;
  }


  int actuatorIndex =
    findActuatorIndex(
      actuatorId
    );


  if (
    actuatorIndex < 0
  )
  {
    Serial.print(
      "[ACTUATOR] ID no configurado: "
    );

    Serial.println(
      actuatorId
    );

    return;
  }


  int configuredGpio =
    actuators[
      actuatorIndex
    ].gpio;


  if (
    gpio != configuredGpio
  )
  {
    Serial.print(
      "[ACTUATOR] Advertencia: GPIO recibido "
    );

    Serial.print(
      gpio
    );

    Serial.print(
      " pero configurado es "
    );

    Serial.println(
      configuredGpio
    );

    Serial.println(
      "[ACTUATOR] Se utilizara el GPIO local"
    );
  }


  pinMode(
    configuredGpio,
    OUTPUT
  );


  digitalWrite(
    configuredGpio,
    on
      ? HIGH
      : LOW
  );


  int confirmedGpioState =
    digitalRead(
      configuredGpio
    );


  bool confirmedState =
    confirmedGpioState == HIGH;


  Serial.println();

  Serial.println(
    "[ACTUATOR] ===================================="
  );


  Serial.print(
    "[ACTUATOR] ID: "
  );

  Serial.println(
    actuatorId
  );


  Serial.print(
    "[ACTUATOR] Nombre: "
  );

  Serial.println(
    actuators[
      actuatorIndex
    ].name
  );


  Serial.print(
    "[ACTUATOR] Tipo: "
  );

  Serial.println(
    actuators[
      actuatorIndex
    ].type
  );


  Serial.print(
    "[ACTUATOR] GPIO: "
  );

  Serial.println(
    configuredGpio
  );


  Serial.print(
    "[ACTUATOR] Comando: "
  );

  Serial.println(
    on
      ? "ON"
      : "OFF"
  );


  Serial.print(
    "[ACTUATOR] CONFIRMADO POR GPIO: "
  );

  Serial.println(
    confirmedState
      ? "ON"
      : "OFF"
  );


  Serial.println(
    "[ACTUATOR] ===================================="
  );


  // ===================================================
  // CONFIRMACION
  // ===================================================

  sendDeviceStatus();
}


// =====================================================
// PROCESAR COMANDO
// =====================================================

void processCommand(
  JsonDocument &doc
)
{
  const char *type =
    doc["type"];


  const char *command =
    doc["command"];


  // ===================================================
  // REGISTRATION
  // ===================================================

  if (
    type &&
    strcmp(
      type,
      "registration"
    ) == 0
  )
  {
    Serial.println(
      "[WS] REGISTRO CONFIRMADO POR SERVIDOR"
    );

    return;
  }


  // ===================================================
  // HELLO
  // ===================================================

  if (
    type &&
    strcmp(
      type,
      "hello"
    ) == 0
  )
  {
    Serial.println(
      "[WS] HELLO recibido"
    );

    return;
  }


  // ===================================================
  // HEARTBEAT ACK
  // ===================================================

  if (
    type &&
    strcmp(
      type,
      "heartbeat_ack"
    ) == 0
  )
  {
    Serial.println(
      "[HEARTBEAT] ACK recibido del servidor"
    );

    return;
  }


  // ===================================================
  // ACTUATOR_SET
  // ===================================================

  if (
    type &&
    strcmp(
      type,
      "command"
    ) == 0 &&
    command &&
    strcmp(
      command,
      "actuator_set"
    ) == 0
  )
  {
    int deviceId =
      doc["device_id"] | -1;


    int actuatorId =
      doc["actuator_id"] | -1;


    int gpio =
      doc["gpio"] | -1;


    const char *state =
      doc["state"];


    Serial.println();

    Serial.println(
      "[CMD] ===================================="
    );

    Serial.println(
      "[CMD] ACTUATOR_SET recibido"
    );


    Serial.print(
      "[CMD] Device ID: "
    );

    Serial.println(
      deviceId
    );


    Serial.print(
      "[CMD] Actuator ID: "
    );

    Serial.println(
      actuatorId
    );


    Serial.print(
      "[CMD] GPIO: "
    );

    Serial.println(
      gpio
    );


    Serial.print(
      "[CMD] Estado: "
    );


    if (
      state
    )
    {
      Serial.println(
        state
      );
    }
    else
    {
      Serial.println(
        "NULL"
      );
    }


    // =================================================
    // VALIDAR DEVICE
    // =================================================

    if (
      deviceId != DEVICE_ID
    )
    {
      Serial.println(
        "[CMD] Comando para otro dispositivo"
      );

      Serial.println(
        "[CMD] Ignorado"
      );

      return;
    }


    // =================================================
    // VALIDAR ACTUADOR
    // =================================================

    if (
      actuatorId < 1
    )
    {
      Serial.println(
        "[CMD] Actuator ID invalido"
      );

      return;
    }


    // =================================================
    // VALIDAR ESTADO
    // =================================================

    if (
      state == nullptr
    )
    {
      Serial.println(
        "[CMD] Estado inexistente"
      );

      return;
    }


    // =================================================
    // APLICAR
    // =================================================

    setActuator(
      actuatorId,
      gpio,
      state
    );


    Serial.println(
      "[CMD] ===================================="
    );

    return;
  }


  // ===================================================
  // STATUS
  // ===================================================

  if (
    type &&
    strcmp(
      type,
      "status"
    ) == 0
  )
  {
    Serial.println(
      "[WS] STATUS recibido"
    );

    return;
  }


  // ===================================================
  // COMANDO DESCONOCIDO
  // ===================================================

  Serial.println(
    "[WS] Tipo/comando no reconocido"
  );


  if (
    type
  )
  {
    Serial.print(
      "[WS] type: "
    );

    Serial.println(
      type
    );
  }


  if (
    command
  )
  {
    Serial.print(
      "[WS] command: "
    );

    Serial.println(
      command
    );
  }
}


// =====================================================
// LEER EXACTAMENTE N BYTES CON TIMEOUT
// =====================================================

bool readExact(
  uint8_t *buffer,
  size_t length,
  unsigned long timeout
)
{
  size_t received = 0;

  unsigned long start =
    millis();


  while (
    received < length
  )
  {
    if (
      client.available()
    )
    {
      int value =
        client.read();


      if (
        value < 0
      )
      {
        continue;
      }


      buffer[received] =
        (uint8_t)value;


      received++;

      start =
        millis();
    }
    else
    {
      if (
        millis() - start >=
        timeout
      )
      {
        return false;
      }

      delay(1);
    }
  }


  return true;
}


// =====================================================
// DESCARTAR BYTES CON TIMEOUT
// =====================================================

bool discardBytes(
  uint64_t length,
  unsigned long timeout
)
{
  uint8_t buffer[64];

  while (
    length > 0
  )
  {
    size_t chunk =
      length > sizeof(buffer)
        ? sizeof(buffer)
        : (size_t)length;


    if (
      !readExact(
        buffer,
        chunk,
        timeout
      )
    )
    {
      return false;
    }


    length -=
      chunk;
  }


  return true;
}


// =====================================================
// PROCESAR WEBSOCKET
// =====================================================

void processWebSocket()
{
  // ===================================================
  // CONEXION
  // ===================================================

  if (
    !client.connected()
  )
  {
    if (
      websocketConnected
    )
    {
      Serial.println(
        "[WS] CONEXION PERDIDA"
      );
    }

    websocketConnected =
      false;

    client.stop();

    return;
  }


  // ===================================================
  // HEADER
  // ===================================================

  if (
    client.available() < 2
  )
  {
    return;
  }


  uint8_t b1 =
    client.read();


  uint8_t b2 =
    client.read();


  uint8_t opcode =
    b1 & 0x0F;


  bool masked =
    b2 & 0x80;


  uint64_t payloadLength =
    b2 & 0x7F;


  // ===================================================
  // LONGITUD EXTENDIDA
  // ===================================================

  if (
    payloadLength == 126
  )
  {
    uint8_t ext[2];


    if (
      !readExact(
        ext,
        2,
        WS_READ_TIMEOUT
      )
    )
    {
      Serial.println(
        "[WS] TIMEOUT LEYENDO LONGITUD"
      );

      disconnectWebSocket(
        "Timeout leyendo longitud"
      );

      return;
    }


    payloadLength =
      ((uint16_t)ext[0] << 8) |
      ext[1];
  }


  else if (
    payloadLength == 127
  )
  {
    uint8_t ext[8];


    if (
      !readExact(
        ext,
        8,
        WS_READ_TIMEOUT
      )
    )
    {
      Serial.println(
        "[WS] TIMEOUT LEYENDO LONGITUD EXTENDIDA"
      );

      disconnectWebSocket(
        "Timeout leyendo longitud extendida"
      );

      return;
    }


    payloadLength =
      0;


    for (
      int i = 0;
      i < 8;
      i++
    )
    {
      payloadLength =
        (payloadLength << 8) |
        ext[i];
    }
  }


  // ===================================================
  // LIMITE DE SEGURIDAD
  // ===================================================

  if (
    payloadLength > 4096
  )
  {
    Serial.println(
      "[WS] FRAME DEMASIADO GRANDE"
    );


    discardBytes(
      payloadLength,
      WS_READ_TIMEOUT
    );


    disconnectWebSocket(
      "Frame demasiado grande"
    );


    return;
  }


  // ===================================================
  // MASCARA
  // ===================================================

  uint8_t mask[4] =
  {
    0,
    0,
    0,
    0
  };


  if (
    masked
  )
  {
    if (
      !readExact(
        mask,
        4,
        WS_READ_TIMEOUT
      )
    )
    {
      Serial.println(
        "[WS] TIMEOUT LEYENDO MASCARA"
      );

      disconnectWebSocket(
        "Timeout leyendo mascara"
      );

      return;
    }
  }


  // ===================================================
  // CLOSE
  // ===================================================

  if (
    opcode == 0x8
  )
  {
    Serial.println(
      "[WS] CLOSE recibido"
    );


    discardBytes(
      payloadLength,
      WS_READ_TIMEOUT
    );


    disconnectWebSocket(
      "Servidor cerro WebSocket"
    );


    return;
  }


  // ===================================================
  // PING
  // ===================================================

  if (
    opcode == 0x9
  )
  {
    Serial.println(
      "[WS] PING recibido"
    );


    uint8_t pingPayload[126];


    if (
      payloadLength > sizeof(pingPayload)
    )
    {
      disconnectWebSocket(
        "PING demasiado grande"
      );

      return;
    }


    if (
      payloadLength > 0
    )
    {
      if (
        !readExact(
          pingPayload,
          payloadLength,
          WS_READ_TIMEOUT
        )
      )
      {
        disconnectWebSocket(
          "Timeout leyendo PING"
        );

        return;
      }


      // =================================================
      // DESENMASCARAR
      // =================================================

      if (
        masked
      )
      {
        for (
          size_t i = 0;
          i < payloadLength;
          i++
        )
        {
          pingPayload[i] ^=
            mask[i % 4];
        }
      }
    }


    sendPong(
      pingPayload,
      payloadLength
    );


    return;
  }


  // ===================================================
  // PONG
  // ===================================================

  if (
    opcode == 0xA
  )
  {
    Serial.println(
      "[WS] PONG recibido"
    );
//prueba
      Serial.print(
          "[WS] PONG millis(): "
        );

        Serial.println(
          millis()
        );
        //prueba
    if (
      !discardBytes(
        payloadLength,
        WS_READ_TIMEOUT
      )
    )
    {
      disconnectWebSocket(
        "Error leyendo PONG"
      );

      return;
    }


    // =================================================
    // HEARTBEAT CONFIRMADO
    // =================================================

    lastPong =
      millis();


    return;
  }


  // ===================================================
  // PAYLOAD
  // ===================================================

  uint8_t buffer[4097];


  if (
    payloadLength > 0
  )
  {
    if (
      !readExact(
        buffer,
        payloadLength,
        WS_READ_TIMEOUT
      )
    )
    {
      Serial.println(
        "[WS] TIMEOUT LEYENDO PAYLOAD"
      );


      disconnectWebSocket(
        "Timeout leyendo payload"
      );


      return;
    }
  }


  // ===================================================
  // DESENMASCARAR
  // ===================================================

  if (
    masked
  )
  {
    for (
      size_t i = 0;
      i < payloadLength;
      i++
    )
    {
      buffer[i] ^=
        mask[i % 4];
    }
  }


  buffer[payloadLength] =
    '\0';


  // ===================================================
  // TEXTO
  // ===================================================

  if (
    opcode == 0x1
  )
  {
    Serial.print(
      "[WS] RX: "
    );


    Serial.write(
      buffer,
      payloadLength
    );


    Serial.println();


    // =================================================
    // JSON
    // =================================================

    JsonDocument doc;


    DeserializationError error =
      deserializeJson(
        doc,
        buffer,
        payloadLength
      );


    if (
      error
    )
    {
      Serial.print(
        "[JSON] Error: "
      );


      Serial.println(
        error.c_str()
      );


      return;
    }


    // =================================================
    // PROCESAR
    // =================================================

    processCommand(
      doc
    );
  }
}


// =====================================================
// RECONEXION WIFI
// =====================================================

void handleWiFiReconnect()
{
  if (
    WiFi.status() == WL_CONNECTED
  )
  {
    return;
  }


  websocketConnected =
    false;


  if (
    millis() - lastWiFiReconnect <
    WIFI_RECONNECT_INTERVAL
  )
  {
    return;
  }


  lastWiFiReconnect =
    millis();


  Serial.println();

  Serial.println(
    "[WiFi] ===================================="
  );

  Serial.println(
    "[WiFi] CONEXION PERDIDA"
  );

  Serial.println(
    "[WiFi] INTENTANDO RECONECTAR..."
  );

  Serial.println(
    "[WiFi] ===================================="
  );


  WiFi.disconnect();

  delay(100);


  WiFi.begin(
    ssid,
    password
  );


  unsigned long start =
    millis();


  while (
    WiFi.status() != WL_CONNECTED &&
    millis() - start < 8000
  )
  {
    delay(250);

    Serial.print(
      "."
    );
  }


  Serial.println();


  if (
    WiFi.status() == WL_CONNECTED
  )
  {
    Serial.println(
      "[WiFi] WIFI RECONECTADO"
    );


    Serial.print(
      "[WiFi] IP: "
    );


    Serial.println(
      WiFi.localIP()
    );
  }
  else
  {
    Serial.println(
      "[WiFi] NO SE PUDO RECONECTAR"
    );
  }
}


// =====================================================
// SETUP
// =====================================================

void setup()
{
  Serial.begin(
    115200
  );


  delay(1000);


  // ===================================================
  // RANDOM
  // ===================================================

  randomSeed(
    esp_random()
  );


  // ===================================================
  // TITULO
  // ===================================================

  Serial.println();

  Serial.println(
    "========================================"
  );

  Serial.println(
    "       ESP32 IoT CONTROL PLATFORM"
  );

  Serial.println(
    "                  V8"
  );

  Serial.println(
    "========================================"
  );

  Serial.println();


#if DEVELOPMENT_MODE

  Serial.println(
    "ENTORNO: DESARROLLO LOCAL"
  );

  Serial.println(
    "WebSocket: ws://192.168.188.15:8080"
  );

#else

  Serial.println(
    "ENTORNO: PRODUCCION"
  );

  Serial.println(
    "WebSocket: wss://sistema-websocket.onrender.com"
  );

#endif


  Serial.println();


  Serial.print(
    "DEVICE ID: "
  );

  Serial.println(
    DEVICE_ID
  );


  Serial.print(
    "DEVICE NAME: "
  );

  Serial.println(
    DEVICE_NAME
  );


  // ===================================================
  // HARDWARE
  // ===================================================

  printActuatorConfiguration();

  configureActuators();

  // ===================================================
  // ===================================================
  // INICIALIZAR SENSOR NTC 10K
  // ===================================================
  // ===================================================

  configureNTC();


  // ===================================================
  // WIFI
  // ===================================================

  Serial.println(
    "[WiFi] Configurando..."
  );


  WiFi.mode(
    WIFI_STA
  );


  WiFi.setAutoReconnect(
    true
  );


  WiFi.persistent(
    false
  );


  Serial.println(
    "[WiFi] Conectando..."
  );


  WiFi.begin(
    ssid,
    password
  );


  unsigned long wifiStart =
    millis();


  while (
    WiFi.status() != WL_CONNECTED &&
    millis() - wifiStart < 20000
  )
  {
    delay(500);

    Serial.print(
      "."
    );
  }


  Serial.println();


  if (
    WiFi.status() == WL_CONNECTED
  )
  {
    Serial.println(
      "[WiFi] CONECTADO"
    );
lastWiFiConnected =  true;//agregue para ver si falla el wifi


    Serial.print(
      "[WiFi] IP: "
    );


    Serial.println(
      WiFi.localIP()
    );
  }
  else
  {
    Serial.println(
      "[WiFi] NO SE PUDO CONECTAR"
    );


    Serial.println(
      "[WiFi] El sistema continuara intentando..."
    );
  }


  // ===================================================
  // WEBSOCKET
  // ===================================================

  if (
    WiFi.status() == WL_CONNECTED
  )
  {
    connectWebSocket();
  }
}


// =====================================================
// LOOP
// =====================================================

void loop()
{
  // ===================================================
  // WIFI
  // ===================================================

 /* if (
    WiFi.status() != WL_CONNECTED
  )
  {
    websocketConnected =
      false;
    client.stop();
    handleWiFiReconnect();
    delay(10);
    return;
  }*/

bool wifiConnected =
  WiFi.status() == WL_CONNECTED;


// ===================================================
// CAMBIO DE ESTADO WIFI
// ===================================================

if (
  wifiConnected != lastWiFiConnected
)
{
  Serial.print(
    "[WIFI] CAMBIO DE ESTADO | millis="
  );

  Serial.print(
    millis()
  );

  Serial.print(
    " | estado="
  );

  if (
    wifiConnected
  )
  {
    Serial.println(
      "CONECTADO"
    );
  }
  else
  {
    Serial.println(
      "DESCONECTADO"
    );
  }

  lastWiFiConnected =
    wifiConnected;
}


// ===================================================
// WIFI DESCONECTADO
// ===================================================

if (
  !wifiConnected
)
{
  websocketConnected =
    false;

  client.stop();

  handleWiFiReconnect();

  delay(10);
}
//fin prueba
  // ===================================================
  // WEBSOCKET DESCONECTADO
  // ===================================================

  if (
    !websocketConnected
  )
  {
    if (
      millis() - lastReconnect >=
      RECONNECT_INTERVAL
    )
    {
      lastReconnect =
        millis();


      Serial.println(
        "[WS] Intentando reconectar..."
      );


      if (
        connectWebSocket()
      )
      {
        Serial.println(
          "[WS] RECONEXION EXITOSA"
        );
      }
      else
      {
        Serial.println(
          "[WS] RECONEXION FALLIDA"
        );
      }
    }


    delay(10);


    return;
  }


  // ===================================================
  // PROCESAR WEBSOCKET
  // ===================================================

  processWebSocket();


  // ===================================================
  // SI processWebSocket DETECTO FALLA
  // ===================================================

  if (
    !websocketConnected
  )
  {
    client.stop();

    lastReconnect =
      millis();

    delay(10);

    return;
  }


  // ===================================================
  // HEARTBEAT
  // ===================================================

  if (
    millis() - lastHeartbeat >=
    HEARTBEAT_INTERVAL
  )
  {
    lastHeartbeat =
      millis();


    // -------------------------------------------------
    // 1. PING WEBSOCKET
    // -------------------------------------------------

    sendPing();


    // -------------------------------------------------
    // 2. HEARTBEAT DE APLICACION
    // -------------------------------------------------

    if (
      websocketConnected
    )
    {
      sendHeartbeat();
    }
  }


  // ===================================================
  // DETECTAR WEBSOCKET MUERTO
  // ===================================================
//
// IMPORTANTE:
//
// No desconectamos inmediatamente si no llega un PONG.
//
// El PONG puede no llegar aunque el canal siga
// funcionando para mensajes normales.
//
// Solo consideramos muerto el socket despues de 90s.
//
// Ademas, si el envio de heartbeat falla,
// sendWebSocketText() marca websocketConnected=false.
//
// ===================================================

  if (
    millis() - lastPong >=
    PONG_TIMEOUT
  )
  {
    Serial.println();

    Serial.println(
      "[WS] ===================================="
    );

    Serial.println(
      "[WS] TIMEOUT DE HEARTBEAT"
    );

    Serial.println(
      "[WS] NO SE RECIBE PONG DEL SERVIDOR"
    );

    Serial.println(
      "[WS] WEBSOCKET CONSIDERADO MUERTO"
    );

    Serial.println(
      "[WS] CERRANDO SOCKET..."
    );

    Serial.println(
      "[WS] ===================================="
    );


    websocketConnected =
      false;


    client.stop();


    lastReconnect =
      millis();
  }


  // ===================================================
  // ESTADO + SENSOR
  // ===================================================

  if (
    millis() - lastDeviceStatus >=
    DEVICE_STATUS_INTERVAL
  )
  {
    lastDeviceStatus =
      millis();


    // =================================================
    // Estado confirmado
    // =================================================

    sendDeviceStatus();


    // =================================================
    // Sensor NTC
    // =================================================

    sendSensorStatus();
  }


  // ===================================================
  // PEQUEÑA PAUSA
  // ===================================================

  delay(2);
}
