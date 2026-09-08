<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { getHealth } from './services/api'

type DeviceStatus = 'online' | 'offline'

interface Led {
  id: number
  name: string
  gpio: number
  state: boolean
}

interface Device {
  id: number
  name: string
  type: string
  location: string
  status: DeviceStatus
  registered: boolean
  leds: Led[]
}

interface WebSocketLed {
  id: number
  name: string
  gpio: number
  state: 'ON' | 'OFF'
}

interface WebSocketDevice {
  id: number
  name: string
  registered?: boolean
  online?: boolean
  leds?: WebSocketLed[]
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

/* =====================================================
   DEVICES
===================================================== */

const devices = ref<Device[]>([])
const totalSensors = ref(0)

/* =====================================================
   LED PENDING / CONFIRMATION
===================================================== */

/*
 * Guarda temporalmente los LEDs que tienen un comando
 * enviado pero todavía no confirmado por el servidor.
 *
 * key:
 *   deviceId-ledId
 *
 * value:
 *   estado que estamos esperando recibir.
 */

const pendingLedCommands = ref<
  Record<string, boolean>
>({})

const pendingTimers = new Map<
  string,
  ReturnType<typeof setTimeout>
>()

function getLedKey(
  deviceId: number,
  ledId: number
) {
  return `${deviceId}-${ledId}`
}

/*function isLedChanging(
  deviceId: number,
  ledId: number
) {
  return Boolean(
    pendingLedCommands.value[
      getLedKey(deviceId, ledId)
    ]
  )
}*/
function isLedChanging(
  deviceId: number,
  ledId: number
) {
  const key = getLedKey(
    deviceId,
    ledId
  )

  return (
    Object.prototype.hasOwnProperty.call(
      pendingLedCommands.value,
      key
    )
  )
}


function clearLedPending(
  deviceId: number,
  ledId: number
) {
  const key = getLedKey(
    deviceId,
    ledId
  )

  const next = {
    ...pendingLedCommands.value,
  }

  delete next[key]

  pendingLedCommands.value = next

  const timer =
    pendingTimers.get(key)

  if (timer) {
    clearTimeout(timer)
    pendingTimers.delete(key)
  }
}

function setLedPending(
  device: Device,
  led: Led,
  expectedState: boolean
) {
  const key = getLedKey(
    device.id,
    led.id
  )

  pendingLedCommands.value = {
    ...pendingLedCommands.value,
    [key]: expectedState,
  }

  /*
   * Timeout de seguridad.
   *
   * Si el servidor no confirma el cambio,
   * dejamos de mostrar CAMBIANDO... después
   * de unos segundos.
   */
  const existingTimer =
    pendingTimers.get(key)

  if (existingTimer) {
    clearTimeout(existingTimer)
  }

  const timer = setTimeout(() => {
    clearLedPending(
      device.id,
      led.id
    )
  }, 8000)

  pendingTimers.set(key, timer)
}

/* =====================================================
   COMPUTED
===================================================== */

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

const totalLeds = computed(() =>
  devices.value.reduce(
    (total, device) =>
      total + device.leds.length,
    0
  )
)

const activeLeds = computed(() =>
  devices.value.reduce(
    (total, device) =>
      total +
      device.leds.filter(
        led => led.state
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
    const saved =
      localStorage.getItem(
        'iot-dashboard-preferences'
      )

    if (!saved) {
      return {
        ...defaultDashboardPreferences,
      }
    }

    const parsed =
      JSON.parse(saved)

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

const customizerOpen =
  ref(false)

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
  dashboardPreferences.value[
    panel
  ] =
    !dashboardPreferences.value[
      panel
    ]

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
  const titles: Record<
    string,
    string
  > = {
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
   THEME FUNCTIONS
===================================================== */

function applyTheme() {
  document.documentElement.setAttribute(
    'data-theme',
    isDark.value
      ? 'dark'
      : 'light'
  )
}

function toggleTheme() {
  isDark.value =
    !isDark.value

  localStorage.setItem(
    'iot-theme',
    isDark.value
      ? 'dark'
      : 'light'
  )

  applyTheme()
}

/* =====================================================
   NAVIGATION FUNCTIONS
===================================================== */

function selectSection(
  section: string
) {
  activeSection.value =
    section

  mobileMenuOpen.value =
    false
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
    const health =
      await getHealth()

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

function mapWebSocketLed(
  wsLed: WebSocketLed
): Led {
  return {
    id: Number(wsLed.id),
    name: wsLed.name,
    gpio: Number(wsLed.gpio),
    state:
      wsLed.state === 'ON',
  }
}

function mapWebSocketDevice(
  wsDevice: WebSocketDevice
): Device {
  const leds =
    Array.isArray(
      wsDevice.leds
    )
      ? wsDevice.leds.map(
          mapWebSocketLed
        )
      : []

  return {
    id: Number(wsDevice.id),
    name: wsDevice.name,
    type: 'ESP32',
    location:
      'Sin ubicación',
    registered:
      wsDevice.registered ??
      true,
    status:
      wsDevice.online === false
        ? 'offline'
        : 'online',
    leds,
  }
}

/* =====================================================
   CONFIRM LED COMMANDS
===================================================== */

function processLedConfirmations(
  serverDevices: WebSocketDevice[]
) {
  for (
    const serverDevice of serverDevices
  ) {
    if (
      !Array.isArray(
        serverDevice.leds
      )
    ) {
      continue
    }

    for (
      const serverLed of
        serverDevice.leds
    ) {
      const key =
        getLedKey(
          Number(
            serverDevice.id
          ),
          Number(serverLed.id)
        )

      const pending =
        pendingLedCommands.value[
          key
        ]

      if (
        pending === undefined
      ) {
        continue
      }

      const confirmedState =
        serverLed.state === 'ON'

      /*
       * El servidor devolvió exactamente
       * el estado que esperábamos.
       */
      if (
        confirmedState ===
        pending
      ) {
        clearLedPending(
          Number(
            serverDevice.id
          ),
          Number(serverLed.id)
        )
      }
    }
  }
}

function updateDevicesFromServer(
  serverDevices: WebSocketDevice[]
) {
  processLedConfirmations(
    serverDevices
  )

  devices.value =
    serverDevices.map(
      mapWebSocketDevice
    )

  /*
   * Si un dispositivo está offline,
   * todos sus comandos pendientes se
   * cancelan porque ya no podemos esperar
   * una confirmación válida.
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
      const led of
        device.leds
    ) {
      clearLedPending(
        device.id,
        led.id
      )
    }
  }
}

/* =====================================================
   WEBSOCKET CONNECTION
   NOT MODIFIED
===================================================== */

function connectWebSocket() {
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
    wsConnected.value =
      true

    console.log(
      '[WS] Conectado correctamente'
    )
  }

  socket.onmessage = event => {
    try {
      const data =
        JSON.parse(
          event.data
        )

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

      if (
        data.type ===
        'command'
      ) {
        console.log(
          '[WS] Comando recibido:',
          data
        )
      }
    } catch (error) {
      console.error(
        '[WS] Error procesando mensaje:',
        error
      )
    }
  }

  socket.onerror = error => {
    wsConnected.value =
      false

    console.error(
      '[WS] Error:',
      error
    )
  }

  socket.onclose = () => {
    wsConnected.value =
      false

    console.log(
      '[WS] Conexión cerrada'
    )
  }
}

/* =====================================================
   LED CONTROL
===================================================== */

function toggleLed(
  device: Device,
  led: Led
) {
  /*
   * Dispositivo offline:
   * no permitimos enviar comandos.
   */
  if (
    device.status !==
    'online'
  ) {
    return
  }

  /*
   * Evita enviar otro comando
   * mientras esperamos confirmación.
   */
  if (
    isLedChanging(
      device.id,
      led.id
    )
  ) {
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
   * Calculamos el estado que esperamos
   * recibir después del toggle.
   */
  const expectedState =
    !led.state

  const message = {
    command:
      'led_toggle',
    device_id:
      device.id,
    led_id:
      led.id,
  }

  /*
   * Marcamos CAMBIANDO...
   * antes de enviar.
   */
  setLedPending(
    device,
    led,
    expectedState
  )

  try {
    ws.value.send(
      JSON.stringify(message)
    )
  } catch (error) {
    console.error(
      '[WS] Error enviando comando:',
      error
    )

    clearLedPending(
      device.id,
      led.id
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

/*function ledButtonLabel(
  device: Device,
  led: Led
) {
  if (
    deviceIsOffline(device)
  ) {
    return 'BLOQUEADO'
  }

  if (
    isLedChanging(
      device.id,
      led.id
    )
  ) {
    return 'CAMBIANDO...'
  }

  return led.state
    ? 'ON'
    : 'OFF'
}
*/
function ledButtonLabel(
  device: Device,
  led: Led
) {
  if (
    deviceIsOffline(device)
  ) {
    return 'BLOQUEADO'
  }

  return led.state
    ? 'ON'
    : 'OFF'
}
/* =====================================================
   LIFECYCLE
===================================================== */

onMounted(() => {
  applyTheme()
  checkApi()
  connectWebSocket()
})

onUnmounted(() => {
  if (ws.value) {
    ws.value.close()
    ws.value = null
  }

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
                      {{ totalLeds }}
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
                      {{ activeLeds }}
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

                <!-- OFFLINE OVERLAY -->

                

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
                    {{ device.leds.length }}
                    actuador{{
                      device.leds.length ===
                      1
                        ? ''
                        : 'es'
                    }}
                  </span>

                  <span>
                    ID #{{ device.id }}
                  </span>
                </div>

                <div
                  class="led-grid"
                >

                  <div
                    v-for="led in device.leds"
                    :key="led.id"
                    class="led-control"
                    :class="{
                      changing:
                        isLedChanging(
                          device.id,
                          led.id
                        ),
                      disabled:
                        device.status !==
                        'online'
                    }"
                  >

                    <div
                      class="led-info"
                    >
                      <div
                        class="led-indicator"
                        :class="{
                          on:
                            led.state,
                          changing:
                            isLedChanging(
                              device.id,
                              led.id
                            )
                        }"
                      >
                        <span></span>
                      </div>

                      <div>
                        <strong>
                          {{ led.name }}
                        </strong>

                        <small>
                          GPIO
                          {{ led.gpio }}
                        </small>
                      </div>
                    </div>

                    <button
                      class="led-button"
                      :class="{
                        active:
                          led.state,
                        changing:
                          isLedChanging(
                            device.id,
                            led.id
                          ),
                        offline:
                          device.status !==
                          'online'
                      }"
                      :disabled="
                        device.status !==
                          'online' ||
                        isLedChanging(
                          device.id,
                          led.id
                        )
                      "
                      @click="
                        toggleLed(
                          device,
                          led
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
                          ledButtonLabel(
                            device,
                            led
                          )
                        }}
                      </span>

                    </button>

                  </div>

                  <div
                    v-if="
                      device.leds.length ===
                      0
                    "
                    class="no-actuators"
                  >
                    Sin actuadores configurados
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
                class="led-grid"
              >

                <div
                  v-for="led in device.leds"
                  :key="led.id"
                  class="led-control"
                  :class="{
                    changing:
                      isLedChanging(
                        device.id,
                        led.id
                      ),
                    disabled:
                      device.status !==
                      'online'
                  }"
                >

                  <div
                    class="led-info"
                  >
                    <div
                      class="led-indicator"
                      :class="{
                        on:
                          led.state,
                        changing:
                          isLedChanging(
                            device.id,
                            led.id
                          )
                      }"
                    >
                      <span></span>
                    </div>

                    <div>
                      <strong>
                        {{ led.name }}
                      </strong>

                      <small>
                        GPIO
                        {{ led.gpio }}
                      </small>
                    </div>
                  </div>

                  <button
                    class="led-button"
                    :class="{
                      active:
                        led.state,
                      changing:
                        isLedChanging(
                          device.id,
                          led.id
                        ),
                      offline:
                        device.status !==
                        'online'
                    }"
                    :disabled="
                      device.status !==
                        'online' ||
                      isLedChanging(
                        device.id,
                        led.id
                      )
                    "
                    @click="
                      toggleLed(
                        device,
                        led
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
                        ledButtonLabel(
                          device,
                          led
                        )
                      }}
                    </span>
                  </button>

                </div>

                <div
                  v-if="
                    device.leds.length ===
                    0
                  "
                  class="no-actuators"
                >
                  Sin actuadores configurados
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
