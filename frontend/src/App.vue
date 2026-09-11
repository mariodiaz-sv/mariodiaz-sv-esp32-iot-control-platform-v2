<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { getHealth } from './services/api'

type DeviceStatus = 'online' | 'offline'

interface Actuator {
  id: number
  name: string
  type: string
  gpio: number
  state: boolean
}

interface Sensor {
  id: number
  name: string
  type: string
  gpio: number
  simulated?: boolean
  temperature?: number
}

interface Device {
  id: number
  name: string
  type: string
  location: string
  status: DeviceStatus
  registered: boolean
  actuators: Actuator[]
    sensors: Sensor[]
}

//sensor
interface WebSocketSensor {
  id: number
  name: string
  type: string
  gpio: number
  simulated?: boolean
  temperature?: number
}
//fin sensor

interface WebSocketActuator {
  id: number
  name: string
  type: string
  gpio: number
  state: 'ON' | 'OFF' | boolean
}

interface WebSocketDevice {
  id: number
  name: string
  registered?: boolean
  online?: boolean
  actuators?: WebSocketActuator[]
  sensors?: WebSocketSensor[]
}

interface WebSocketStatusMessage {
  type: 'status'
  devices: WebSocketDevice[]
}

/* =====================================================
   NAVIGATION
===================================================== */

const activeSection = ref('dashboard')
const mobileMenuOpen = ref(false)

/* =====================================================
   THEME
===================================================== */

const isDark = ref(
  localStorage.getItem('iot-theme') === 'dark'
)

/* =====================================================
   API / WEBSOCKET
===================================================== */

const apiOnline = ref(false)
const apiVersion = ref('')
const wsConnected = ref(false)
const ws = ref<WebSocket | null>(null)

let reconnectTimer: ReturnType<typeof setTimeout> | null = null
let componentUnmounted = false

/* =====================================================
   DEVICES
===================================================== */

const devices = ref<Device[]>([])
/* =====================================================
   HISTORIAL DE TEMPERATURA
   Guarda temporalmente las últimas 30 lecturas
   recibidas desde el WebSocket.
===================================================== */

interface TemperaturePoint {
  time: string
  temperature: number
}

const temperatureHistory = ref<
  Record<number, TemperaturePoint[]>
>({})

const MAX_TEMPERATURE_POINTS = 30

const totalSensors = ref(0)
/* =====================================================
   TEMPERATURA - DATOS PARA LA TARJETA Y GRÁFICO
===================================================== */

function getTemperatureSensor(
  device: Device
): Sensor | undefined {
  return device.sensors?.find(
    sensor =>
      sensor.type === 'temperature' &&
      sensor.temperature !== undefined
  )
}

function getTemperatureHistory(
  deviceId: number
): TemperaturePoint[] {
  return temperatureHistory.value[deviceId] ?? []
}

function getTemperatureMin(
  deviceId: number
): number {
  const history =
    getTemperatureHistory(deviceId)

  if (!history.length) {
    return 0
  }

  return Math.min(
    ...history.map(
      point => point.temperature
    )
  )
}

function getTemperatureMax(
  deviceId: number
): number {
  const history =
    getTemperatureHistory(deviceId)

  if (!history.length) {
    return 0
  }

  return Math.max(
    ...history.map(
      point => point.temperature
    )
  )
}

/* =====================================================
   ACTUATOR PENDING
===================================================== */

const pendingActuatorCommands = ref<Record<string, boolean>>({})

const pendingTimers = new Map<
  string,
  ReturnType<typeof setTimeout>
>()

function getActuatorKey(
  deviceId: number,
  actuatorId: number
) {
  return `${deviceId}-${actuatorId}`
}

function isActuatorChanging(
  deviceId: number,
  actuatorId: number
) {
  const key = getActuatorKey(deviceId, actuatorId)

  return Object.prototype.hasOwnProperty.call(
    pendingActuatorCommands.value,
    key
  )
}

function clearActuatorPending(
  deviceId: number,
  actuatorId: number
) {
  const key = getActuatorKey(deviceId, actuatorId)

  const next = {
    ...pendingActuatorCommands.value,
  }

  delete next[key]

  pendingActuatorCommands.value = next

  const timer = pendingTimers.get(key)

  if (timer) {
    clearTimeout(timer)
    pendingTimers.delete(key)
  }
}

function setActuatorPending(
  device: Device,
  actuator: Actuator,
  expectedState: boolean
) {
  const key = getActuatorKey(
    device.id,
    actuator.id
  )

  pendingActuatorCommands.value = {
    ...pendingActuatorCommands.value,
    [key]: expectedState,
  }

  const existingTimer = pendingTimers.get(key)

  if (existingTimer) {
    clearTimeout(existingTimer)
  }

  const timer = setTimeout(() => {
    const pending =
      pendingActuatorCommands.value[key]

    if (pending !== undefined) {
      console.warn(
        '[ACTUATOR] Timeout esperando confirmación:',
        {
          device_id: device.id,
          actuator_id: actuator.id,
          expected_state: expectedState,
        }
      )

      clearActuatorPending(
        device.id,
        actuator.id
      )
    }
  }, 8000)

  pendingTimers.set(key, timer)
}

/* =====================================================
   COMPUTED
===================================================== */

const onlineDevices = computed(() =>
  devices.value.filter(
    device => device.status === 'online'
  ).length
)

const offlineDevices = computed(() =>
  devices.value.filter(
    device => device.status === 'offline'
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
        actuator => actuator.state
      ).length,
    0
  )
)

/*const totalLeds = computed(() =>
  totalActuators.value
)

const activeLeds = computed(() =>
  activeActuators.value
)*/

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
   DASHBOARD PREFERENCES
===================================================== */

type DashboardPanel =
  | 'stats'
  | 'overview'
  | 'devices'
  | 'activity'

interface DashboardPreferences {
  stats: boolean
  overview: boolean
  devices: boolean
  activity: boolean
}

const defaultDashboardPreferences: DashboardPreferences = {
  stats: true,
  overview: true,
  devices: true,
  activity: true,
}

function loadDashboardPreferences(): DashboardPreferences {
  try {
    const saved = localStorage.getItem(
      'iot-dashboard-preferences'
    )

    if (!saved) {
      return {
        ...defaultDashboardPreferences,
      }
    }

    const parsed = JSON.parse(saved)

    return {
      ...defaultDashboardPreferences,
      ...parsed,
    }
  } catch (error) {
    console.warn(
      '[Dashboard] No se pudieron cargar las preferencias:',
      error
    )

    return {
      ...defaultDashboardPreferences,
    }
  }
}

const dashboardPreferences =
  ref<DashboardPreferences>(
    loadDashboardPreferences()
  )

const customizerOpen = ref(false)

function saveDashboardPreferences() {
  localStorage.setItem(
    'iot-dashboard-preferences',
    JSON.stringify(
      dashboardPreferences.value
    )
  )
}

function toggleDashboardPanel(
  panel: DashboardPanel
) {
  dashboardPreferences.value[panel] =
    !dashboardPreferences.value[panel]

  saveDashboardPreferences()
}

function resetDashboardPreferences() {
  dashboardPreferences.value = {
    ...defaultDashboardPreferences,
  }

  saveDashboardPreferences()
}

function closeCustomizer() {
  customizerOpen.value = false
}

/* =====================================================
   PAGE TITLE
===================================================== */

const pageTitle = computed(() => {
  const titles: Record<string, string> = {
    dashboard: 'Dashboard',
    devices: 'Dispositivos',
    sensors: 'Sensores',
    users: 'Usuarios',
  }

  return (
    titles[activeSection.value] ||
    'Dashboard'
  )
})

/* =====================================================
   THEME
===================================================== */

function applyTheme() {
  document.documentElement.setAttribute(
    'data-theme',
    isDark.value ? 'dark' : 'light'
  )
}

function toggleTheme() {
  isDark.value = !isDark.value

  localStorage.setItem(
    'iot-theme',
    isDark.value ? 'dark' : 'light'
  )

  applyTheme()
}

/* =====================================================
   NAVIGATION
===================================================== */

function selectSection(section: string) {
  activeSection.value = section
  mobileMenuOpen.value = false
}

function toggleMobileMenu() {
  mobileMenuOpen.value =
    !mobileMenuOpen.value
}

/* =====================================================
   API
===================================================== */

async function checkApi() {
  try {
    const health = await getHealth()

    apiOnline.value =
      health.status === 'ok'

    apiVersion.value =
      health.version
  } catch (error) {
    apiOnline.value = false
    apiVersion.value = ''

    console.error(
      '[API] No se pudo conectar con Laravel:',
      error
    )
  }
}

/* =====================================================
   WEBSOCKET MAPPING
===================================================== */

function normalizeActuatorState(
  state: WebSocketActuator['state']
): boolean {
  if (typeof state === 'boolean') {
    return state
  }

  return state.toUpperCase() === 'ON'
}

function mapWebSocketActuator(
  wsActuator: WebSocketActuator
): Actuator {
  return {
    id: Number(wsActuator.id),

    name: wsActuator.name,

    type: wsActuator.type,

    gpio: Number(wsActuator.gpio),

    state: normalizeActuatorState(
      wsActuator.state
    ),
  }
}

function mapWebSocketDevice(
  wsDevice: WebSocketDevice
): Device {
  const actuators =
    Array.isArray(wsDevice.actuators)
      ? wsDevice.actuators.map(
          mapWebSocketActuator
        )
      : []
const sensors =
  Array.isArray(wsDevice.sensors)
    ? wsDevice.sensors.map(
        (sensor): Sensor => ({
          id: Number(sensor.id),
          name: sensor.name,
          type: sensor.type,
          gpio: Number(sensor.gpio),
          simulated: sensor.simulated ?? false,
          temperature:
            sensor.temperature !== undefined
              ? Number(sensor.temperature)
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
      wsDevice.registered ?? true,

    status:
      wsDevice.online === false
        ? 'offline'
        : 'online',

    actuators,
    sensors,
  }
}

/* =====================================================
   ACTUATOR CONFIRMATIONS
===================================================== */

function processActuatorConfirmations(
  serverDevices: WebSocketDevice[]
) {
  for (const serverDevice of serverDevices) {
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
        pendingActuatorCommands.value[key]

      if (pending === undefined) {
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
          received: receivedState,
        }
      )

      /*
       * CONFIRMACIÓN CORRECTA
       *
       * El servidor confirmó exactamente
       * el estado que solicitamos.
       */
      if (receivedState === pending) {
        console.log(
          '[ACTUATOR] ✓ Confirmado:',
          {
            deviceId,
            actuatorId,
            state: receivedState,
          }
        )

        clearActuatorPending(
          deviceId,
          actuatorId
        )

        continue
      }

      /*
       * IMPORTANTE:
       *
       * Si recibimos un estado diferente,
       * NO quitamos CAMBIANDO...
       *
       * Esto significa que el ESP32/servidor
       * todavía no ejecutó correctamente
       * el comando.
       */
      console.warn(
        '[ACTUATOR] ✗ Estado no confirmado:',
        {
          deviceId,
          actuatorId,
          expected: pending,
          received: receivedState,
        }
      )
    }
  }
}

/* =====================================================
   UPDATE DEVICES
===================================================== */

function updateDevicesFromServer(
  serverDevices: WebSocketDevice[]
) {
  processActuatorConfirmations(
    serverDevices
  )

  devices.value =
    serverDevices.map(
      mapWebSocketDevice
    )
  /* =====================================================
     REGISTRAR HISTORIAL DE TEMPERATURA
     Guarda cada lectura recibida desde el WebSocket.
  ===================================================== */

  for (const device of serverDevices) {
    if (!Array.isArray(device.sensors)) {
      continue
    }

    const temperatureSensor = device.sensors.find(
      sensor =>
        sensor.type === 'temperature' &&
        sensor.temperature !== undefined
    )

    if (!temperatureSensor) {
      continue
    }

    const temperature = Number(
      temperatureSensor.temperature
    )

    if (!Number.isFinite(temperature)) {
      continue
    }

    const deviceId = Number(device.id)

    if (!temperatureHistory.value[deviceId]) {
      temperatureHistory.value[deviceId] = []
    }

    temperatureHistory.value[deviceId].push({
      time: new Date().toLocaleTimeString(
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
      temperatureHistory.value[deviceId].length >
      MAX_TEMPERATURE_POINTS
    ) {
      temperatureHistory.value[deviceId] =
        temperatureHistory.value[deviceId].slice(
          -MAX_TEMPERATURE_POINTS
        )
    }
  }

  /*
   * Si un dispositivo está offline,
   * cancelamos sus comandos pendientes.
   */
  for (
    const device of devices.value
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
   WEBSOCKET CONNECTION
===================================================== */

function scheduleReconnect() {
  if (componentUnmounted) {
    return
  }

  if (reconnectTimer) {
    clearTimeout(reconnectTimer)
  }

  reconnectTimer = setTimeout(() => {
    reconnectTimer = null

    if (
      !componentUnmounted &&
      !wsConnected.value
    ) {
      connectWebSocket()
    }
  }, 3000)
}

function connectWebSocket() {
  if (componentUnmounted) {
    return
  }

  if (
    ws.value &&
    (
      ws.value.readyState ===
        WebSocket.OPEN ||
      ws.value.readyState ===
        WebSocket.CONNECTING
    )
  ) {
    return
  }

  console.log(
    '[WS] Conectando...'
  )

  const socket =
    new WebSocket(
      'ws://127.0.0.1:8080'
    )

  ws.value = socket

  socket.onopen = () => {
    if (componentUnmounted) {
      socket.close()
      return
    }

    wsConnected.value = true

    console.log(
      '[WS] ✓ Conectado correctamente'
    )
  }

  socket.onmessage = event => {
    try {
      const data =
        JSON.parse(
          event.data
        )

      console.log(
        '[WS] RX <- SERVER:',
        data
      )

      /*
       * STATUS
       */
      if (
        data.type ===
        'status'
      ) {
        const statusMessage =
          data as WebSocketStatusMessage

        if (
          Array.isArray(
            statusMessage.devices
          )
        ) {
          updateDevicesFromServer(
            statusMessage.devices
          )
        }

        return
      }

      /*
       * REGISTRATION
       */
      if (
        data.type ===
        'registration'
      ) {
        console.log(
          '[WS] Confirmación de registro:',
          data
        )

        return
      }

      /*
       * COMMAND
       */
      if (
        data.type ===
        'command'
      ) {
        console.log(
          '[WS] Comando recibido:',
          data
        )

        return
      }
    } catch (error) {
      console.error(
        '[WS] Error procesando mensaje:',
        error
      )
    }
  }

  socket.onerror = error => {
    wsConnected.value = false

    console.error(
      '[WS] Error:',
      error
    )
  }

  socket.onclose = () => {
    wsConnected.value = false

    console.log(
      '[WS] Conexión cerrada'
    )

    if (
      ws.value === socket
    ) {
      ws.value = null
    }

    scheduleReconnect()
  }
}

/* =====================================================
   ACTUATOR CONTROL
===================================================== */

function toggleActuator(
  device: Device,
  actuator: Actuator
) {
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
   * WebSocket desconectado.
   */
  if (
    !ws.value ||
    ws.value.readyState !==
      WebSocket.OPEN
  ) {
    console.warn(
      '[WS] WebSocket no está conectado'
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
    ws.value.send(
      JSON.stringify(message)
    )

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
   HELPERS
===================================================== */

function deviceIsOffline(
  device: Device
) {
  return (
    device.status ===
    'offline'
  )
}

function actuatorButtonLabel(
  device: Device,
  actuator: Actuator
) {
  if (
    deviceIsOffline(device)
  ) {
    return 'BLOQUEADO'
  }

  if (
    isActuatorChanging(
      device.id,
      actuator.id
    )
  ) {
    return 'CAMBIANDO...'
  }

  return actuator.state
    ? 'ON'
    : 'OFF'
}

/* =====================================================
   LIFECYCLE
===================================================== */

onMounted(() => {
  componentUnmounted = false

  applyTheme()
  checkApi()
  connectWebSocket()
})

onUnmounted(() => {
  componentUnmounted = true

  if (reconnectTimer) {
    clearTimeout(
      reconnectTimer
    )

    reconnectTimer = null
  }

  if (ws.value) {
    ws.value.close()
    ws.value = null
  }

  wsConnected.value = false

  for (
    const timer of
      pendingTimers.values()
  ) {
    clearTimeout(timer)
  }

  pendingTimers.clear()
})
</script>


<template>
  <div class="app-shell">

    <!-- MOBILE OVERLAY -->

    <div
      v-if="mobileMenuOpen"
      class="mobile-overlay"
      @click="
        mobileMenuOpen = false
      "
    ></div>

    <!-- SIDEBAR -->

    <aside
      class="sidebar"
      :class="{
        'sidebar-open':
          mobileMenuOpen
      }"
    >
      <div class="brand">

        <div class="brand-logo">
          <span>⚡</span>
        </div>

        <div class="brand-text">
          <strong>
            IoT Control
          </strong>

          <span>
            Platform V2
          </span>
        </div>

        <button
          class="sidebar-close"
          @click="
            mobileMenuOpen = false
          "
          aria-label="Cerrar menú"
        >
          ×
        </button>

      </div>

      <div class="sidebar-content">

        <nav>

          <div class="nav-section">

            <span class="nav-title">
              PLATAFORMA
            </span>

            <button
              class="nav-item"
              :class="{
                active:
                  activeSection ===
                  'dashboard'
              }"
              @click="
                selectSection(
                  'dashboard'
                )
              "
            >
              <span
                class="nav-item-icon"
              >
                ⌂
              </span>

              <span>
                Dashboard
              </span>
            </button>

            <button
              class="nav-item"
              :class="{
                active:
                  activeSection ===
                  'devices'
              }"
              @click="
                selectSection(
                  'devices'
                )
              "
            >
              <span
                class="nav-item-icon"
              >
                ▣
              </span>

              <span>
                Dispositivos
              </span>

              <span
                v-if="devices.length"
                class="nav-count"
              >
                {{ devices.length }}
              </span>
            </button>

            <button
              class="nav-item"
              :class="{
                active:
                  activeSection ===
                  'sensors'
              }"
              @click="
                selectSection(
                  'sensors'
                )
              "
            >
              <span
                class="nav-item-icon"
              >
                ◉
              </span>

              <span>
                Sensores
              </span>
            </button>

          </div>

          <div class="nav-section">

            <span class="nav-title">
              ADMINISTRACIÓN
            </span>

            <button
              class="nav-item"
              :class="{
                active:
                  activeSection ===
                  'users'
              }"
              @click="
                selectSection(
                  'users'
                )
              "
            >
              <span
                class="nav-item-icon"
              >
                ♙
              </span>

              <span>
                Usuarios
              </span>
            </button>

          </div>

        </nav>

      </div>

      <div class="sidebar-bottom">

        <div
          class="connection-box"
          :class="{
            offline:
              !wsConnected
          }"
        >

          <span
            class="connection-dot"
            :class="{
              connected:
                wsConnected
            }"
          ></span>

          <div>

            <strong>
              WebSocket
            </strong>

            <span>
              {{
                wsConnected
                  ? 'Conexión estable'
                  : 'Desconectado'
              }}
            </span>

          </div>

        </div>

        <div class="sidebar-version">
          IoT Platform V2
        </div>

      </div>

    </aside>

    <!-- MAIN -->

    <main class="main-content">

      <!-- TOPBAR -->

      <header class="topbar">

        <div class="topbar-left">

          <button
            class="hamburger"
            @click="
              toggleMobileMenu
            "
            aria-label="Abrir menú"
            :aria-expanded="
              mobileMenuOpen
            "
          >
            <span></span>
            <span></span>
            <span></span>
          </button>

          <div>

            <span
              class="topbar-breadcrumb"
            >
              IOT CONTROL PLATFORM
            </span>

            <h1>
              {{ pageTitle }}
            </h1>

          </div>

        </div>

        <div class="topbar-actions">

          <button
            class="theme-toggle"
            @click="
              toggleTheme
            "
            :aria-label="
              isDark
                ? 'Cambiar a tema claro'
                : 'Cambiar a tema oscuro'
            "
          >
            <span v-if="isDark">
              ☀
            </span>

            <span v-else>
              ☾
            </span>
          </button>

          <div
            class="api-pill"
            :class="{
              online: apiOnline
            }"
          >

            <span
              class="status-dot"
            ></span>

            <span>
              {{
                apiOnline
                  ? `API v${apiVersion}`
                  : 'API offline'
              }}
            </span>

          </div>

          <div class="user-profile">

            <div class="avatar">
              M
            </div>

            <div class="user-details">

              <strong>
                Administrador
              </strong>

              <span>
                Admin
              </span>

            </div>

          </div>

        </div>

      </header>

      <!-- SCROLL AREA -->

      <div class="content-scroll">

        <!-- DASHBOARD -->

        <section
          v-if="
            activeSection ===
            'dashboard'
          "
          class="page-content dashboard-page"
        >

          <!-- HERO -->

          <section
            class="dashboard-hero"
          >

            <div
              class="hero-content"
            >

              <span
                class="section-kicker"
              >
                SISTEMA EN TIEMPO REAL
              </span>

              <h2>
                Controla tu infraestructura
                <span>IoT.</span>
              </h2>

              <p>
                Supervisa dispositivos ESP32
                y controla tus actuadores
                desde un solo lugar.
              </p>

            </div>

            <div
              class="live-indicator"
              :class="{
                offline:
                  !wsConnected
              }"
            >

              <span
                class="live-dot"
                :class="{
                  active:
                    wsConnected
                }"
              ></span>

              {{
                wsConnected
                  ? 'Sistema en vivo'
                  : 'Sin conexión'
              }}

            </div>

          </section>

          <!-- TOOLBAR -->

          <div
            class="dashboard-toolbar"
          >

            <div
              class="dashboard-toolbar-content"
            >

              <span
                class="dashboard-toolbar-title"
              >
                Panel de control
              </span>

              <span
                class="dashboard-toolbar-description"
              >
                Personaliza la información
                que quieres visualizar.
              </span>

            </div>

            <button
              class="customize-button"
              type="button"
              @click="
                customizerOpen = true
              "
            >
              <span>⚙</span>
              Personalizar
            </button>

          </div>

          <!-- CUSTOMIZER -->

          <div
            v-if="customizerOpen"
            class="customizer-overlay"
            @click.self="
              closeCustomizer
            "
          >

            <aside
              class="dashboard-customizer"
              role="dialog"
              aria-modal="true"
              aria-labelledby="dashboard-customizer-title"
            >

              <div
                class="customizer-header"
              >

                <div>

                  <span
                    class="section-kicker"
                  >
                    CONFIGURACIÓN
                  </span>

                  <h3
                    id="dashboard-customizer-title"
                  >
                    Personalizar dashboard
                  </h3>

                  <p>
                    Selecciona los paneles
                    que quieres mostrar.
                  </p>

                </div>

                <button
                  class="customizer-close"
                  type="button"
                  aria-label="Cerrar configuración"
                  @click="
                    closeCustomizer
                  "
                >
                  ×
                </button>

              </div>

              <div
                class="customizer-options"
              >

                <label
                  class="customizer-option"
                >

                  <div>

                    <strong>
                      Estadísticas
                    </strong>

                    <span>
                      Resumen de dispositivos
                      y sensores.
                    </span>

                  </div>

                  <input
                    type="checkbox"
                    :checked="
                      dashboardPreferences
                        .stats
                    "
                    @change="
                      toggleDashboardPanel(
                        'stats'
                      )
                    "
                  />

                </label>

                <label
                  class="customizer-option"
                >

                  <div>

                    <strong>
                      Estado general
                    </strong>

                    <span>
                      Disponibilidad e
                      infraestructura.
                    </span>

                  </div>

                  <input
                    type="checkbox"
                    :checked="
                      dashboardPreferences
                        .overview
                    "
                    @change="
                      toggleDashboardPanel(
                        'overview'
                      )
                    "
                  />

                </label>

                <label
                  class="customizer-option"
                >

                  <div>

                    <strong>
                      Dispositivos
                    </strong>

                    <span>
                      ESP32 y actuadores
                      conectados.
                    </span>

                  </div>

                  <input
                    type="checkbox"
                    :checked="
                      dashboardPreferences
                        .devices
                    "
                    @change="
                      toggleDashboardPanel(
                        'devices'
                      )
                    "
                  />

                </label>

                <label
                  class="customizer-option"
                >

                  <div>

                    <strong>
                      Actividad reciente
                    </strong>

                    <span>
                      Estado y eventos
                      del sistema.
                    </span>

                  </div>

                  <input
                    type="checkbox"
                    :checked="
                      dashboardPreferences
                        .activity
                    "
                    @change="
                      toggleDashboardPanel(
                        'activity'
                      )
                    "
                  />

                </label>

              </div>

              <div
                class="customizer-footer"
              >

                <button
                  class="reset-dashboard-button"
                  type="button"
                  @click="
                    resetDashboardPreferences
                  "
                >
                  Restaurar
                </button>

                <button
                  class="customizer-done-button"
                  type="button"
                  @click="
                    closeCustomizer
                  "
                >
                  Listo
                </button>

              </div>

            </aside>

          </div>

          <!-- STATS -->

          <section
            v-if="
              dashboardPreferences
                .stats
            "
            class="stats-grid dashboard-panel"
          >

            <article class="stat-card">

              <div class="stat-top">

                <div
                  class="stat-icon blue"
                >
                  ▣
                </div>

                <span class="stat-tag">
                  TOTAL
                </span>

              </div>

              <div class="stat-value">
                {{ devices.length }}
              </div>

              <div class="stat-name">
                Dispositivos
              </div>

              <div
                class="stat-description"
              >
                Registrados en la plataforma
              </div>

            </article>

            <article class="stat-card">

              <div class="stat-top">

                <div
                  class="stat-icon green"
                >
                  ✓
                </div>

                <span
                  class="stat-tag success"
                >
                  ACTIVO
                </span>

              </div>

              <div class="stat-value">
                {{ onlineDevices }}
              </div>

              <div class="stat-name">
                En línea
              </div>

              <div
                class="stat-description"
              >
                Dispositivos disponibles
              </div>

            </article>

            <article class="stat-card">

              <div class="stat-top">

                <div
                  class="stat-icon orange"
                >
                  !
                </div>

                <span
                  class="stat-tag warning"
                >
                  ALERTA
                </span>

              </div>

              <div class="stat-value">
                {{ offlineDevices }}
              </div>

              <div class="stat-name">
                Fuera de línea
              </div>

              <div
                class="stat-description"
              >
                Requieren atención
              </div>

            </article>

            <article class="stat-card">

              <div class="stat-top">

                <div
                  class="stat-icon purple"
                >
                  ◉
                </div>

                <span class="stat-tag">
                  TOTAL
                </span>

              </div>

              <div class="stat-value">
                {{ totalSensors }}
              </div>

              <div class="stat-name">
                Sensores
              </div>

              <div
                class="stat-description"
              >
                Sensores registrados
              </div>

            </article>

          </section>

          <!-- OVERVIEW -->

          <section
            v-if="
              dashboardPreferences
                .overview
            "
            class="overview-grid dashboard-panel"
          >

            <article
              class="overview-card"
            >

              <div
                class="overview-header"
              >

                <div>

                  <span
                    class="section-kicker"
                  >
                    INFRAESTRUCTURA
                  </span>

                  <h3>
                    Estado general
                  </h3>

                </div>

                <span
                  class="online-badge"
                  :class="{
                    offline:
                      !wsConnected
                  }"
                >

                  <span></span>

                  {{
                    wsConnected
                      ? 'Operativo'
                      : 'Offline'
                  }}

                </span>

              </div>

              <div
                class="overview-body"
              >

                <div
                  class="health-ring"
                >

                  <div
                    class="health-ring-inner"
                  >

                    <strong>
                      {{ availability }}%
                    </strong>

                    <span>
                      disponibilidad
                    </span>

                  </div>

                </div>

                <div
                  class="health-details"
                >

                  <div
                    class="health-row"
                  >

                    <span>

                      <i
                        class="green-dot"
                      ></i>

                      En línea

                    </span>

                    <strong>
                      {{ onlineDevices }}
                    </strong>

                  </div>

                  <div
                    class="health-row"
                  >

                    <span>

                      <i
                        class="gray-dot"
                      ></i>

                      Fuera de línea

                    </span>

                    <strong>
                      {{ offlineDevices }}
                    </strong>

                  </div>

                  <div
                    class="health-row"
                  >

                    <span>

                      <i
                        class="blue-dot"
                      ></i>

                      Actuadores

                    </span>

                    <strong>
                      {{ totalActuators }}
                    </strong>

                  </div>

                </div>

              </div>

            </article>

            <article
              class="overview-card quick-card"
            >

              <div
                class="overview-header"
              >

                <div>

                  <span
                    class="section-kicker"
                  >
                    ACTIVIDAD
                  </span>

                  <h3>
                    Sistema
                  </h3>

                </div>

              </div>

              <div class="quick-list">

                <div
                  class="quick-item"
                >

                  <div
                    class="quick-icon green"
                  >
                    ✓
                  </div>

                  <div>

                    <strong>
                      API Laravel
                    </strong>

                    <small>
                      {{
                        apiOnline
                          ? 'Conectada correctamente'
                          : 'Sin conexión'
                      }}
                    </small>

                  </div>

                  <span
                    class="mini-status"
                    :class="{
                      active:
                        apiOnline
                    }"
                  ></span>

                </div>

                <div
                  class="quick-item"
                >

                  <div
                    class="quick-icon blue"
                  >
                    ↔
                  </div>

                  <div>

                    <strong>
                      WebSocket
                    </strong>

                    <small>
                      Comunicación en tiempo real
                    </small>

                  </div>

                  <span
                    class="mini-status"
                    :class="{
                      active:
                        wsConnected
                    }"
                  ></span>

                </div>

                <div
                  class="quick-item"
                >

                  <div
                    class="quick-icon purple"
                  >
                    ●
                  </div>

                  <div>

                    <strong>
                      Actuadores activos
                    </strong>

                    <small>
                      {{ activeActuators }}
                      actualmente encendidos
                    </small>

                  </div>

                </div>

              </div>

            </article>

          </section>

          <!-- DEVICES -->

          <section
            v-if="
              dashboardPreferences
                .devices
            "
            class="devices-section dashboard-panel"
          >

            <div
              class="section-heading"
            >

              <div>

                <span
                  class="section-kicker"
                >
                  HARDWARE
                </span>

                <h3>
                  Dispositivos
                </h3>

                <p>
                  Controla tus ESP32 conectados.
                </p>

              </div>

              <button
                class="outline-button"
                @click="
                  selectSection(
                    'devices'
                  )
                "
              >
                Ver todos
                <span>→</span>
              </button>

            </div>

            <div
              v-if="devices.length"
              class="devices-grid"
            >

              <article
                v-for="device in devices"
                :key="device.id"
                class="device-card"
                :class="{
                  'device-offline':
                    deviceIsOffline(
                      device
                    )
                }"
              >

                <div
                  class="device-card-top"
                >

                  <div
                    class="device-info"
                  >

                    <div
                      class="device-avatar"
                      :class="{
                        online:
                          device.status ===
                          'online',
                        offline:
                          device.status ===
                          'offline'
                      }"
                    >
                      ESP
                    </div>

                    <div>

                      <h4>
                        {{ device.name }}
                      </h4>

                      <span>
                        {{ device.type }}
                        <b>·</b>
                        {{ device.location }}
                      </span>

                    </div>

                  </div>

                  <div
                    class="device-status"
                    :class="
                      device.status
                    "
                  >

                    <span></span>

                    {{
                      device.status ===
                      'online'
                        ? 'ONLINE'
                        : 'OFFLINE'
                    }}

                  </div>

                </div>
              



                <div
                  v-if="
                    deviceIsOffline(
                      device
                    )
                  "
                  class="device-offline-message"
                >

                  <span
                    class="offline-message-icon"
                  >
                    !
                  </span>

                  <div>

                    <strong>
                      Sin comunicación
                    </strong>

                    <span>
                      Los controles están
                      temporalmente bloqueados.
                    </span>

                  </div>

                </div>

                <div
                  class="device-meta"
                >

                  <span>
                    {{ device.actuators.length }}
                    actuador{{
                      device.actuators.length ===
                      1
                        ? ''
                        : 'es'
                    }}
                  </span>

                  <span>
                    ID #{{ device.id }}
                  </span>

                </div>

                
                <!-- =================================================
     SENSORES
================================================== -->

<div
  v-if="
    device.sensors &&
    device.sensors.length
  "
  class="device-subsection"
>

  <div class="subsection-title">
    <span class="section-kicker">SENSORES</span>
  </div>

  <div class="led-grid">

    <div
      v-for="sensor in device.sensors"
      :key="sensor.id"
      class="led-control temperature-control"
    >

      <div class="led-info">

        <div class="led-indicator temperature-indicator">
          <span>🌡️</span>
        </div>

        <div>

          <strong>
            {{ sensor.name }}
          </strong>

          <small>
            {{ sensor.type }}
            · GPIO {{ sensor.gpio }}
          </small>

        </div>

      </div>

      <div class="temperature-value">
        {{
          sensor.temperature !== undefined
            ? sensor.temperature.toFixed(1)
            : '--'
        }}°C
      </div>

    </div>

  </div>

</div>


<!-- =================================================
     ACTUADORES
================================================== -->

<div class="device-subsection">

  <div class="subsection-title">
    <span class="section-kicker">ACTUADORES</span>
  </div>

  <div class="led-grid">

    <div
      v-for="actuator in device.actuators"
      :key="actuator.id"
      class="led-control"
      :class="{
        changing:
          isActuatorChanging(
            device.id,
            actuator.id
          ),
        disabled:
          device.status !== 'online'
      }"
    >

      <div class="led-info">

        <div
          class="led-indicator"
          :class="{
            on: actuator.state,
            changing:
              isActuatorChanging(
                device.id,
                actuator.id
              )
          }"
        >
          <span></span>
        </div>

        <div>

          <strong>
            {{ actuator.name }}
          </strong>

          <small>
            {{ actuator.type }}
            · GPIO {{ actuator.gpio }}
          </small>

        </div>

      </div>

      <button
        class="led-button"
        :class="{
          active: actuator.state,
          changing:
            isActuatorChanging(
              device.id,
              actuator.id
            ),
          offline:
            device.status !== 'online'
        }"
        :disabled="
          device.status !== 'online' ||
          isActuatorChanging(
            device.id,
            actuator.id
          )
        "
        @click="
          toggleActuator(
            device,
            actuator
          )
        "
      >

        <span
          class="led-button-dot"
        ></span>

        <span
          class="led-button-label"
        >
          {{
            actuatorButtonLabel(
              device,
              actuator
            )
          }}
        </span>

      </button>

    </div>

    <div
      v-if="device.actuators.length === 0"
      class="no-actuators"
    >
      Sin actuadores configurados
    </div>

  </div>

</div>


              </article>

            </div>

            <div
              v-else
              class="empty-state"
            >

              <div
                class="empty-state-icon"
              >
                ▣
              </div>

              <h3>
                Esperando dispositivos
              </h3>

              <p>
                Cuando un ESP32 se registre,
                aparecerá automáticamente aquí.
              </p>

              <div
                class="waiting-status"
              >

                <span
                  class="status-dot"
                  :class="{
                    online:
                      wsConnected
                  }"
                ></span>

                {{
                  wsConnected
                    ? 'WebSocket conectado'
                    : 'Esperando conexión WebSocket'
                }}

              </div>

            </div>

          </section>

          <!-- ACTIVITY -->

          <section
            v-if="
              dashboardPreferences
                .activity
            "
            class="activity-heading dashboard-panel"
          >

            <div
              class="section-heading"
            >

              <div>

                <span
                  class="section-kicker"
                >
                  MONITOREO
                </span>

                <h3>
                  Actividad reciente
                </h3>

              </div>

            </div>

            <div
              class="system-status"
            >

              <div
                class="system-item"
              >

                <div
                  class="system-icon green"
                >
                  ✓
                </div>

                <div>

                  <strong>
                    Sistema IoT activo
                  </strong>

                  <span>
                    Comunicación WebSocket
                    en tiempo real
                  </span>

                </div>

                <span
                  class="system-state active"
                >
                  Ahora
                </span>

              </div>

              <div
                class="system-item"
              >

                <div
                  class="system-icon blue"
                >
                  ↔
                </div>

                <div>

                  <strong>
                    Estado sincronizado
                  </strong>

                  <span>
                    Los dispositivos son
                    administrados por el servidor
                  </span>

                </div>

                <span
                  class="system-state active"
                >
                  Ahora
                </span>

              </div>

            </div>

          </section>

        </section>

        <!-- DEVICES PAGE -->

        <section
          v-else-if="
            activeSection ===
            'devices'
          "
          class="page-content"
        >

          <div
            class="page-intro"
          >

            <span
              class="section-kicker"
            >
              HARDWARE
            </span>

            <h2>
              Dispositivos
            </h2>

            <p>
              Administra los dispositivos
              ESP32 registrados.
            </p>

          </div>

          <div
            v-if="devices.length"
            class="devices-grid"
          >

            <article
              v-for="device in devices"
              :key="device.id"
              class="device-card"
              :class="{
                'device-offline':
                  deviceIsOffline(
                    device
                  )
              }"
            >

              <div
                v-if="
                  deviceIsOffline(
                    device
                  )
                "
                class="device-offline-banner"
              >
                <span>●</span>
                DISPOSITIVO OFFLINE
              </div>

              <div
                class="device-card-top"
              >

                <div
                  class="device-info"
                >

                  <div
                    class="device-avatar"
                    :class="{
                      online:
                        device.status ===
                        'online',
                      offline:
                        device.status ===
                        'offline'
                    }"
                  >
                    ESP
                  </div>

                  <div>

                    <h4>
                      {{ device.name }}
                    </h4>

                    <span>
                      {{ device.type }}
                      <b>·</b>
                      {{ device.location }}
                    </span>

                  </div>

                </div>

                <div
                  class="device-status"
                  :class="
                    device.status
                  "
                >

                  <span></span>

                  {{
                    device.status ===
                    'online'
                      ? 'ONLINE'
                      : 'OFFLINE'
                  }}

                </div>

              </div>

              <div
                v-if="
                  deviceIsOffline(
                    device
                  )
                "
                class="offline-message"
              >

                <span
                  class="offline-message-icon"
                >
                  !
                </span>

                <div>

                  <strong>
                    Sin comunicación
                  </strong>

                  <small>
                    Los controles están
                    temporalmente bloqueados.
                  </small>

                </div>

              </div>

              <div
                class="device-meta"
              >

                <span>
                  {{ device.actuators.length }}
                  actuador{{
                    device.actuators.length ===
                    1
                      ? ''
                      : 'es'
                  }}
                </span>

                <span>
                  ID #{{ device.id }}
                </span>

              </div>

              <!-- =================================================
     SENSORES
================================================== -->

<div
  v-if="
    device.sensors &&
    device.sensors.length
  "
  class="device-subsection"
>

  <div class="subsection-title">
    <span class="section-kicker">SENSORES</span>
  </div>

  <div class="led-grid">

    <div
      v-for="sensor in device.sensors"
      :key="sensor.id"
      class="led-control temperature-control"
    >

      <div class="led-info">

        <div class="led-indicator temperature-indicator">
          <span>🌡️</span>
        </div>

        <div>

          <strong>
            {{ sensor.name }}
          </strong>

          <small>
            {{ sensor.type }}
            · GPIO {{ sensor.gpio }}
          </small>

        </div>

      </div>

      <div class="temperature-value">
        {{
          sensor.temperature !== undefined
            ? sensor.temperature.toFixed(1)
            : '--'
        }}°C
      </div>

    </div>

  </div>

</div>


<!-- =================================================
     ACTUADORES
================================================== -->

<div class="device-subsection">

  <div class="subsection-title">
    <span class="section-kicker">ACTUADORES</span>
  </div>

  <div class="led-grid">

    <div
      v-for="actuator in device.actuators"
      :key="actuator.id"
      class="led-control"
      :class="{
        changing:
          isActuatorChanging(
            device.id,
            actuator.id
          ),
        disabled:
          device.status !== 'online'
      }"
    >

      <div class="led-info">

        <div
          class="led-indicator"
          :class="{
            on: actuator.state,
            changing:
              isActuatorChanging(
                device.id,
                actuator.id
              )
          }"
        >
          <span></span>
        </div>

        <div>

          <strong>
            {{ actuator.name }}
          </strong>

          <small>
            {{ actuator.type }}
            · GPIO {{ actuator.gpio }}
          </small>

        </div>

      </div>

      <button
        class="led-button"
        :class="{
          active: actuator.state,
          changing:
            isActuatorChanging(
              device.id,
              actuator.id
            ),
          offline:
            device.status !== 'online'
        }"
        :disabled="
          device.status !== 'online' ||
          isActuatorChanging(
            device.id,
            actuator.id
          )
        "
        @click="
          toggleActuator(
            device,
            actuator
          )
        "
      >

        <span
          class="led-button-dot"
        ></span>

        <span
          class="led-button-label"
        >
          {{
            actuatorButtonLabel(
              device,
              actuator
            )
          }}
        </span>

      </button>

    </div>

    <div
      v-if="device.actuators.length === 0"
      class="no-actuators"
    >
      Sin actuadores configurados
    </div>

  </div>

</div>


            </article>

          </div>

          <div
            v-else
            class="empty-state"
          >

            <div
              class="empty-state-icon"
            >
              ▣
            </div>

            <h3>
              No hay dispositivos
            </h3>

            <p>
              Esperando el registro
              de dispositivos ESP32.
            </p>

          </div>

        </section>

        <!-- SENSORS -->

        <section
          v-else-if="
            activeSection ===
            'sensors'
          "
          class="page-content"
        >

          <div
            class="page-intro"
          >

            <span
              class="section-kicker"
            >
              MONITOREO
            </span>

            <h2>
              Sensores
            </h2>

            <p>
              Administra los sensores
              asociados a tus dispositivos.
            </p>

          </div>

          <div
            class="module-placeholder"
          >

            <div
              class="placeholder-icon purple"
            >
              ◉
            </div>

            <h3>
              Módulo de sensores
            </h3>

            <p>
              Aquí conectaremos el sistema
              de sensores con Laravel
              y WebSocket.
            </p>

            <span
              class="coming-soon"
            >
              PRÓXIMAMENTE
            </span>

          </div>

        </section>

        <!-- USERS -->

        <section
          v-else
          class="page-content"
        >

          <div
            class="page-intro"
          >

            <span
              class="section-kicker"
            >
              ADMINISTRACIÓN
            </span>

            <h2>
              Usuarios
            </h2>

            <p>
              Administra usuarios y permisos
              de la plataforma.
            </p>

          </div>

          <div
            class="module-placeholder"
          >

            <div
              class="placeholder-icon blue"
            >
              ♙
            </div>

            <h3>
              Gestión de usuarios
            </h3>

            <p>
              Aquí conectaremos el sistema
              de autenticación y permisos.
            </p>

            <span
              class="coming-soon"
            >
              PRÓXIMAMENTE
            </span>

          </div>

        </section>

      </div>

    </main>

  </div>
</template>
