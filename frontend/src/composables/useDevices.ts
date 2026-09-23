/**

useDevices.ts

Composable encargado de centralizar toda la lógica relacionada

con los dispositivos IoT y sus actuadores/sensores.

Recibe los estados de dispositivos enviados por WebSocket,

los transforma al modelo interno de la aplicación y mantiene

la lista reactiva de dispositivos, estadísticas e historial

de temperatura.

También controla los comandos de actuadores, incluyendo:

Envío de comandos al servidor WebSocket.

Seguimiento de comandos pendientes.

Confirmación del nuevo estado mediante mensajes STATUS.

Timeout y limpieza de comandos no confirmados.

De esta forma, App.vue queda encargado principalmente de la

interfaz y la presentación, mientras que la lógica de dispositivos

permanece aislada y reutilizable en este composable.
*/
import {
  computed,
  ref,
} from 'vue'

/* =====================================================
   TIPOS DE COMANDOS
===================================================== */

export type SendCommand = (
  message: Record<string, unknown>
) => boolean

/* =====================================================
   TIPOS DE DISPOSITIVOS
===================================================== */

export type DeviceStatus =
  | 'online'
  | 'offline'

export interface Actuator {
  id: number
  name: string
  type: string
  gpio: number
  state: boolean
}

export interface Sensor {
  id: number
  name: string
  type: string
  gpio: number
  simulated?: boolean
  temperature?: number
  humidity?: number
}

/* =====================================================
   TIPOS RECIBIDOS DESDE WEBSOCKET
===================================================== */

export interface WebSocketSensor {
  id: number
  name: string
  type: string
  gpio: number
  simulated?: boolean
  temperature?: number
  humidity?: number
}

export interface WebSocketActuator {
  id: number
  name: string
  type: string
  gpio: number
  state: 'ON' | 'OFF' | boolean
}

export interface WebSocketDevice {
  id: number
  name: string
  registered?: boolean
  online?: boolean
  actuators?: WebSocketActuator[]
  sensors?: WebSocketSensor[]
}

export interface WebSocketStatusMessage {
  type: 'status'
  devices: WebSocketDevice[]
}

/* =====================================================
   MODELO INTERNO DEL DISPOSITIVO
===================================================== */

export interface Device {
  id: number
  name: string
  type: string
  location: string
  status: DeviceStatus
  registered: boolean
  actuators: Actuator[]
  sensors: Sensor[]
}

/* =====================================================
   HISTORIAL DE TEMPERATURA
===================================================== */

export interface TemperaturePoint {
  time: string
  temperature: number
}

const temperatureHistory = ref<
  Record<number, TemperaturePoint[]>
>({})

const MAX_TEMPERATURE_POINTS = 30

/* =====================================================
   DISPOSITIVOS
===================================================== */

const devices = ref<Device[]>([])

/* =====================================================
   ACTUATOR PENDING
===================================================== */

const pendingActuatorCommands =
  ref<Record<string, boolean>>({})

const pendingTimers = new Map<
  string,
  ReturnType<typeof setTimeout>
>()

function getActuatorKey(
  deviceId: number,
  actuatorId: number
): string {
  return `${deviceId}-${actuatorId}`
}

function isActuatorChanging(
  deviceId: number,
  actuatorId: number
): boolean {
  const key =
    getActuatorKey(
      deviceId,
      actuatorId
    )

  return Object.prototype.hasOwnProperty.call(
    pendingActuatorCommands.value,
    key
  )
}

function clearActuatorPending(
  deviceId: number,
  actuatorId: number
): void {
  const key =
    getActuatorKey(
      deviceId,
      actuatorId
    )

  const next = {
    ...pendingActuatorCommands.value,
  }

  delete next[key]

  pendingActuatorCommands.value =
    next

  const timer =
    pendingTimers.get(key)

  if (timer) {
    clearTimeout(timer)
    pendingTimers.delete(key)
  }
}

function setActuatorPending(
  device: Device,
  actuator: Actuator,
  expectedState: boolean
): void {
  const key =
    getActuatorKey(
      device.id,
      actuator.id
    )

  pendingActuatorCommands.value = {
    ...pendingActuatorCommands.value,
    [key]: expectedState,
  }

  const existingTimer =
    pendingTimers.get(key)

  if (existingTimer) {
    clearTimeout(existingTimer)
  }

  const timer = setTimeout(() => {
    const pending =
      pendingActuatorCommands.value[
        key
      ]

    if (pending !== undefined) {
      console.warn(
        '[ACTUATOR] Timeout esperando confirmación:',
        {
          device_id: device.id,
          actuator_id: actuator.id,
          expected_state:
            expectedState,
        }
      )

      clearActuatorPending(
        device.id,
        actuator.id
      )
    }
  }, 8000)

  pendingTimers.set(
    key,
    timer
  )
}

/* =====================================================
   ACTUATOR CONFIRMATIONS
===================================================== */

function processActuatorConfirmations(
  serverDevices: WebSocketDevice[]
): void {
  for (
    const serverDevice of serverDevices
  ) {
    if (
      !Array.isArray(
        serverDevice.actuators
      )
    ) {
      continue
    }

    for (
      const serverActuator of
        serverDevice.actuators
    ) {
      const deviceId =
        Number(serverDevice.id)

      const actuatorId =
        Number(serverActuator.id)

      const key =
        getActuatorKey(
          deviceId,
          actuatorId
        )

      const pending =
        pendingActuatorCommands.value[
          key
        ]

      if (
        pending === undefined
      ) {
        continue
      }

      const receivedState =
        normalizeActuatorState(
          serverActuator.state
        )

      console.log(
        '[ACTUATOR] Confirmación STATUS:',
        {
          deviceId,
          actuatorId,
          expected: pending,
          received:
            receivedState,
        }
      )

      if (
        receivedState ===
        pending
      ) {
        console.log(
          '[ACTUATOR] ✓ Confirmado:',
          {
            deviceId,
            actuatorId,
            state:
              receivedState,
          }
        )

        clearActuatorPending(
          deviceId,
          actuatorId
        )

        continue
      }

      console.warn(
        '[ACTUATOR] ✗ Estado no confirmado:',
        {
          deviceId,
          actuatorId,
          expected: pending,
          received:
            receivedState,
        }
      )
    }
  }
}

/* =====================================================
   ESTADÍSTICAS
===================================================== */

const totalSensors = computed(() =>
  devices.value.reduce(
    (total, device) =>
      total + device.sensors.length,
    0
  )
)

const onlineDevices = computed(() =>
  devices.value.filter(
    device =>
      device.status === 'online'
  ).length
)

const offlineDevices = computed(() =>
  devices.value.filter(
    device =>
      device.status === 'offline'
  ).length
)

const totalActuators = computed(() =>
  devices.value.reduce(
    (total, device) =>
      total + device.actuators.length,
    0
  )
)

const activeActuators = computed(() =>
  devices.value.reduce(
    (total, device) =>
      total +
      device.actuators.filter(
        actuator =>
          actuator.state
      ).length,
    0
  )
)

const availability = computed(() => {
  if (!devices.value.length) {
    return 0
  }

  return Math.round(
    (onlineDevices.value /
      devices.value.length) *
      100
  )
})

/* =====================================================
   NORMALIZAR ACTUADOR
===================================================== */

function normalizeActuatorState(
  state: WebSocketActuator['state']
): boolean {
  if (typeof state === 'boolean') {
    return state
  }

  return state.toUpperCase() === 'ON'
}

/* =====================================================
   MAPEAR ACTUADOR
===================================================== */

function mapWebSocketActuator(
  wsActuator: WebSocketActuator
): Actuator {
  return {
    id: Number(wsActuator.id),

    name: wsActuator.name,

    type: wsActuator.type,

    gpio: Number(wsActuator.gpio),

    state:
      normalizeActuatorState(
        wsActuator.state
      ),
  }
}

/* =====================================================
   MAPEAR DISPOSITIVO
===================================================== */

function mapWebSocketDevice(
  wsDevice: WebSocketDevice
): Device {
  const actuators =
    Array.isArray(
      wsDevice.actuators
    )
      ? wsDevice.actuators.map(
          mapWebSocketActuator
        )
      : []

  const sensors =
    Array.isArray(
      wsDevice.sensors
    )
      ? wsDevice.sensors.map(
          (sensor): Sensor => ({
            id: Number(sensor.id),

            name: sensor.name,

            type: sensor.type,

            gpio: Number(sensor.gpio),

            simulated:
              sensor.simulated ??
              false,

            temperature:
              sensor.temperature !==
              undefined
                ? Number(
                    sensor.temperature
                  )
                : undefined,

            humidity:
              sensor.humidity !==
              undefined
                ? Number(
                    sensor.humidity
                  )
                : undefined,
          })
        )
      : []

  return {
    id: Number(wsDevice.id),

    name: wsDevice.name,

    type: 'ESP32',

    location: 'Sin ubicación',

    registered:
      wsDevice.registered ??
      true,

    status:
      wsDevice.online === false
        ? 'offline'
        : 'online',

    actuators,

    sensors,
  }
}

/* =====================================================
   ACTUALIZAR DISPOSITIVOS
===================================================== */

function updateDevicesFromServer(
  serverDevices: WebSocketDevice[]
): void {
  /*
   * Primero procesamos las confirmaciones
   * de actuadores.
   */
  processActuatorConfirmations(
    serverDevices
  )

  /*
   * Actualizamos la lista principal.
   */
  devices.value =
    serverDevices.map(
      mapWebSocketDevice
    )

  /* ===================================================
     HISTORIAL DE TEMPERATURA
  =================================================== */

  for (
    const device of serverDevices
  ) {
    if (
      !Array.isArray(
        device.sensors
      )
    ) {
      continue
    }

    const temperatureSensor =
      device.sensors.find(
        sensor =>
          sensor.type ===
            'temperature' &&
          sensor.temperature !==
            undefined
      )

    if (
      !temperatureSensor
    ) {
      continue
    }

    const temperature =
      Number(
        temperatureSensor.temperature
      )

    if (
      !Number.isFinite(
        temperature
      )
    ) {
      continue
    }

    const deviceId =
      Number(device.id)

    if (
      !temperatureHistory
        .value[deviceId]
    ) {
      temperatureHistory.value[
        deviceId
      ] = []
    }

    temperatureHistory.value[
      deviceId
    ].push({
      time:
        new Date().toLocaleTimeString(
          'es-SV',
          {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
          }
        ),

      temperature,
    })

    if (
      temperatureHistory.value[
        deviceId
      ].length >
      MAX_TEMPERATURE_POINTS
    ) {
      temperatureHistory.value[
        deviceId
      ] =
        temperatureHistory.value[
          deviceId
        ].slice(
          -MAX_TEMPERATURE_POINTS
        )
    }
  }

  /*
   * Si un dispositivo está offline,
   * cancelamos sus comandos pendientes.
   */
  for (
    const device of
      devices.value
  ) {
    if (
      device.status !==
      'offline'
    ) {
      continue
    }

    for (
      const actuator of
        device.actuators
    ) {
      clearActuatorPending(
        device.id,
        actuator.id
      )
    }
  }
}

/* =====================================================
   ACTUATOR CONTROL
===================================================== */

function toggleActuator(
  device: Device,
  actuator: Actuator,
  send: SendCommand
): void {
  /*
   * Dispositivo offline.
   */
  if (
    device.status !==
    'online'
  ) {
    console.warn(
      '[ACTUATOR] Dispositivo offline:',
      device.id
    )

    return
  }

  /*
   * Ya existe un comando pendiente.
   */
  if (
    isActuatorChanging(
      device.id,
      actuator.id
    )
  ) {
    console.warn(
      '[ACTUATOR] Comando ya pendiente:',
      {
        device_id: device.id,
        actuator_id: actuator.id,
      }
    )

    return
  }

  /*
   * Estado que esperamos recibir
   * posteriormente en STATUS.
   */
  const expectedState =
    !actuator.state

  /*
   * Protocolo actual.
   */
  const message = {
    command:
      'actuator_toggle',

    device_id:
      Number(device.id),

    actuator_id:
      Number(actuator.id),
  }

  /*
   * Primero marcamos el comando
   * como pendiente.
   */
  setActuatorPending(
    device,
    actuator,
    expectedState
  )

  try {
    const sent =
      send(message)

    if (!sent) {
      clearActuatorPending(
        device.id,
        actuator.id
      )

      return
    }

    console.log(
      '[WS] TX -> SERVER:',
      message
    )

    console.log(
      '[ACTUATOR] Esperando STATUS:',
      {
        device_id: device.id,
        actuator_id: actuator.id,
        expected_state:
          expectedState,
      }
    )
  } catch (error) {
    console.error(
      '[WS] Error enviando comando:',
      error
    )

    clearActuatorPending(
      device.id,
      actuator.id
    )
  }
}

/* =====================================================
   ACTUATOR HELPERS
===================================================== */

function deviceIsOffline(
  device: Device
): boolean {
  return (
    device.status ===
    'offline'
  )
}

function actuatorButtonLabel(
  device: Device,
  actuator: Actuator
): string {
  if (deviceIsOffline(device)) {
    return 'BLOQUEADO'
  }

  return actuator.state
    ? 'ON'
    : 'OFF'
}

/* =====================================================
   COMPOSABLE
===================================================== */

export function useDevices(
  send: SendCommand
) {
  return {
    /*
     * Estado
     */
    devices,
    temperatureHistory,

    /*
     * Estadísticas
     */
    totalSensors,
    onlineDevices,
    offlineDevices,
    totalActuators,
    activeActuators,
    availability,

    /*
     * WebSocket / mapping
     */
    normalizeActuatorState,
    mapWebSocketActuator,
    mapWebSocketDevice,
    updateDevicesFromServer,

    /*
     * Actuadores
     */
    isActuatorChanging,

    toggleActuator: (
      device: Device,
      actuator: Actuator
    ) =>
      toggleActuator(
        device,
        actuator,
        send
      ),

    deviceIsOffline,
    actuatorButtonLabel,
  }
}
