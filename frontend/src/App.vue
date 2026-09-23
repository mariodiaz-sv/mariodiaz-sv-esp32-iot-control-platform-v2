<script setup lang="ts">
import {
  computed,
  onMounted,
  onUnmounted,
  ref
} from 'vue'

import {
  getHealth,
  getWeather,
  getWeatherLocation,
  updateWeatherLocation,
} from './services/api'


import DeviceCard from './components/DeviceCard.vue'
import WeatherPanel from './components/WeatherPanel.vue'
import WeatherSettingsModal from './components/WeatherSettingsModal.vue'
import { useWebSocket } from './composables/useWebSocket'

import {
  useDevices,
} from './composables/useDevices'

import type {
  WebSocketStatusMessage,
} from './composables/useDevices'




interface WeatherForecast {
  time: string
  temperature: number
  condition: string
  icon: string
  precipitationProbability: number
}

interface WeatherData {
  city: string
  country: string
  date: string
  time: string
  temperature: number
  feelsLike: number
  humidity: number
  uvIndex: number
  windSpeed: number
  windDirection: string
  precipitation: number
  condition: string
  icon: string
  warningTitle: string
  warningDescription: string
  forecast: WeatherForecast[]
}
const weather = ref<WeatherData>({
  city: '',
  country: '',
  date: '',
  time: '',
  temperature: 0,
  feelsLike: 0,
  humidity: 0,
  uvIndex: 0,
  windSpeed: 0,
  windDirection: '',
  precipitation: 0,
  condition: '',
  icon: '🌤️',
  warningTitle: '',
  warningDescription: '',
  forecast: [],
})

const weatherLoading = ref(false)
const weatherError = ref('')
/*para la hora local */
const currentTime = ref('')
let currentTimeTimer: ReturnType<typeof setInterval> | null = null

function updateCurrentTime() {
  currentTime.value = new Date().toLocaleTimeString(
    'es-SV',
    {
      hour: '2-digit',
      minute: '2-digit',
    }
  )
}
/*para la fecha local*/


/* =====================================================
   NAVIGATION
===================================================== */

const activeSection = ref('dashboard')
const mobileMenuOpen = ref(false)

/* =====================================================
   CONFIGURACIÓN DEL CLIMA
===================================================== */

interface WeatherSettings {
  city: string
  latitude: number
  longitude: number
  timezone: string
}

const weatherSettings = ref<WeatherSettings>({
  city: 'Santa Tecla',
  latitude: 13.673111,
  longitude: -89.25687,
  timezone: 'America/El_Salvador',
})



const weatherSettingsOpen = ref(false)

const settingsLoading = ref(false)
const settingsMessage = ref('')
const settingsError = ref('')

const citySearching = ref(false)
const citySearchError = ref('')

const citySearchResults =
  ref<CitySearchResult[]>([])

/*
 * Búsqueda de ciudad mediante Open-Meteo Geocoding.
 *
 * El usuario solamente escribe la ciudad.
 * Open-Meteo devuelve:
 * - latitud
 * - longitud
 * - zona horaria
 * - país
 */

interface CitySearchResult {
  id: number
  name: string
  latitude: number
  longitude: number
  timezone: string
  country: string
  country_code: string
  admin1?: string
  admin2?: string
  population?: number
}

async function searchCity() {
  const city =
    weatherSettings.value.city.trim()

  citySearchError.value = ''
  settingsMessage.value = ''
  citySearchResults.value = []

  if (!city) {
    citySearchError.value =
      'Escribe una ciudad para buscarla.'

    return
  }

  citySearching.value = true

  try {
    const url =
      'https://geocoding-api.open-meteo.com/v1/search' +
      `?name=${encodeURIComponent(city)}` +
      '&count=10' +
      '&language=es' +
      '&format=json'

    const response =
      await fetch(url)

    if (!response.ok) {
      throw new Error(
        'No se pudo consultar Open-Meteo.'
      )
    }

    const data =
      await response.json()

    if (
      !data.results ||
      data.results.length === 0
    ) {
      throw new Error(
        `No se encontró la ciudad "${city}".`
      )
    }

    citySearchResults.value =
      data.results

  } catch (error) {
    console.error(
      '[OPEN-METEO] Error buscando ciudad:',
      error
    )

    citySearchError.value =
      error instanceof Error
        ? error.message
        : 'No se pudo encontrar la ciudad.'

  } finally {
    citySearching.value = false
  }
}

function selectCity(
  location: CitySearchResult
) {
  weatherSettings.value = {
    city: location.name,
    latitude: Number(location.latitude),
    longitude: Number(location.longitude),
    timezone:
      location.timezone ??
      'UTC',
  }

  citySearchResults.value = []

  citySearchError.value = ''

  settingsMessage.value =
    `Ubicación seleccionada: ${
      location.name
    }, ${
      location.admin1 ?? ''
    }, ${
      location.country
    }`
}


/* =====================================================
   CONFIGURACIÓN DEL CLIMA
===================================================== */


function getWeatherCondition(
  weatherCode: number
): {
  condition: string
  icon: string
} {
  if (weatherCode === 0) {
    return {
      condition: 'Despejado',
      icon: '☀️',
    }
  }

  if (
    weatherCode === 1 ||
    weatherCode === 2
  ) {
    return {
      condition: 'Parcialmente nublado',
      icon: '⛅',
    }
  }

  if (weatherCode === 3) {
    return {
      condition: 'Nublado',
      icon: '☁️',
    }
  }

  if (
    weatherCode === 45 ||
    weatherCode === 48
  ) {
    return {
      condition: 'Niebla',
      icon: '🌫️',
    }
  }

  if (
    weatherCode >= 51 &&
    weatherCode <= 57
  ) {
    return {
      condition: 'Llovizna',
      icon: '🌦️',
    }
  }

  if (
    weatherCode >= 61 &&
    weatherCode <= 67
  ) {
    return {
      condition: 'Lluvia',
      icon: '🌧️',
    }
  }

  if (
    weatherCode >= 71 &&
    weatherCode <= 77
  ) {
    return {
      condition: 'Nieve',
      icon: '🌨️',
    }
  }

  if (
    weatherCode >= 80 &&
    weatherCode <= 82
  ) {
    return {
      condition: 'Chubascos',
      icon: '🌦️',
    }
  }

  if (
    weatherCode === 95 ||
    weatherCode === 96 ||
    weatherCode === 99
  ) {
    return {
      condition: 'Tormenta',
      icon: '⛈️',
    }
  }

  return {
    condition: 'Condición desconocida',
    icon: '🌤️',
  }
}

function getWindDirection(
  degrees: number
): string {
  const directions = [
    'N',
    'NE',
    'E',
    'SE',
    'S',
    'SO',
    'O',
    'NO',
  ]

  const index = Math.round(
    degrees / 45
  ) % 8

  return directions[index]
}

function formatWeatherDate(
  dateString: string
): {
  date: string
  time: string
} {
  const date = new Date(dateString)

  if (Number.isNaN(date.getTime())) {
    return {
      date: '--',
      time: '--',
    }
  }

  return {
    date: date.toLocaleDateString(
      'es-SV',
      {
        day: 'numeric',
        month: 'long',
      }
    ),
    time: date.toLocaleTimeString(
      'es-SV',
      {
        hour: '2-digit',
        minute: '2-digit',
      }
    ),
  }
}

function getWeatherWarning(
  weatherCode: number
): {
  title: string
  description: string
} {
  if (
    weatherCode === 95 ||
    weatherCode === 96 ||
    weatherCode === 99
  ) {
    return {
      title: 'Precaución',
      description:
        'Se reportan condiciones de tormenta.',
    }
  }

  if (
    weatherCode >= 61 &&
    weatherCode <= 82
  ) {
    return {
      title: 'Condiciones de lluvia',
      description:
        'Se esperan precipitaciones durante el período consultado.',
    }
  }

  return {
    title: 'Sin alertas',
    description:
      'Las condiciones actuales no requieren advertencias especiales.',
  }
}
async function loadWeatherSettings() {
  try {
    const location = await getWeatherLocation()

    weatherSettings.value = {
      city:
        location.city ?? '',

      latitude:
        Number(location.latitude),

      longitude:
        Number(location.longitude),

      timezone:
        location.timezone ??
        'America/El_Salvador',
    }
  } catch (error) {
    console.error(
      'Error cargando configuración del clima:',
      error
    )
  }
}

async function saveWeatherSettings() {
  settingsLoading.value = true
  settingsMessage.value = ''
  settingsError.value = ''

  try {
    const city =
      weatherSettings.value.city.trim()

    if (!city) {
      throw new Error(
        'La ciudad es obligatoria.'
      )
    }

    /*
     * Si todavía no tenemos coordenadas,
     * buscamos automáticamente la ciudad.
     */
   if (
  !Number.isFinite(
    Number(weatherSettings.value.latitude)
  ) ||
  !Number.isFinite(
    Number(weatherSettings.value.longitude)
  )
) {
  throw new Error(
    'Primero debes buscar y seleccionar una ciudad.'
  )
}


    const latitude =
      Number(
        weatherSettings.value.latitude
      )

    const longitude =
      Number(
        weatherSettings.value.longitude
      )

    const timezone =
      weatherSettings.value.timezone.trim()

    if (
      !Number.isFinite(latitude) ||
      latitude < -90 ||
      latitude > 90
    ) {
      throw new Error(
        'No se pudo obtener una latitud válida para la ciudad.'
      )
    }

    if (
      !Number.isFinite(longitude) ||
      longitude < -180 ||
      longitude > 180
    ) {
      throw new Error(
        'No se pudo obtener una longitud válida para la ciudad.'
      )
    }

    if (!timezone) {
      throw new Error(
        'No se pudo obtener la zona horaria de la ciudad.'
      )
    }

    const location =
      await updateWeatherLocation({
        city:
          weatherSettings.value.city,

        latitude,

        longitude,

        timezone,
      })

    weatherSettings.value = {
      city:
        location.city ??
        weatherSettings.value.city,

      latitude:
        Number(location.latitude),

      longitude:
        Number(location.longitude),

      timezone:
        location.timezone ??
        timezone,
    }

    settingsMessage.value =
      'Ubicación actualizada correctamente.'

    await loadWeather()

  } catch (error) {
    settingsError.value =
      error instanceof Error
        ? error.message
        : 'No se pudo guardar la configuración.'

  } finally {
    settingsLoading.value = false
  }
}


async function loadWeather() {
  weatherLoading.value = true
  weatherError.value = ''

  try {
    const data = await getWeather()

    if (!data.current) {
      throw new Error(
        'La API no devolvió datos meteorológicos actuales.'
      )
    }

    const current = data.current

    const currentCondition =
      getWeatherCondition(
        current.weather_code
      )

    const currentDateTime =
      formatWeatherDate(
        current.time
      )

    const warning =
      getWeatherWarning(
        current.weather_code
      )

    const forecast = []

if (data.forecast) {
  const forecastTimes =
    data.forecast.time

  const currentTime =
    new Date(current.time).getTime()

  const firstFutureIndex =
    forecastTimes.findIndex(
      (time: string) =>
        new Date(time).getTime() > currentTime
    )

  if (firstFutureIndex !== -1) {
    const endIndex =
      Math.min(
        firstFutureIndex + 6,
        forecastTimes.length
      )

    for (
      let index = firstFutureIndex;
      index < endIndex;
      index++
    ) {
      const condition =
        getWeatherCondition(
          data.forecast.weather_code[
            index
          ]
        )

      const forecastDate =
        formatWeatherDate(
          data.forecast.time[index]
        )

      forecast.push({
        time: forecastDate.time,

        temperature:
          Math.round(
            data.forecast.temperature_2m[
              index
            ]
          ),

        condition:
          condition.condition,

        icon:
          condition.icon,

        precipitationProbability:
          data.forecast
            .precipitation_probability[
              index
            ] ?? 0,
      })
    }
  }
}


    weather.value = {
      city:
        data.location.city ??
        'Ubicación desconocida',

      country:
        data.location.country ??
        '',

      date:
        //currentDateTime.date,
        new Intl.DateTimeFormat(
    'es-SV',
    {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      timeZone: data.location.timezone ?? undefined,
    }
  ).format(
    new Date()
  ),

      time:
        currentDateTime.time,

      temperature:
        Number(
          current.temperature_2m
        ),

      feelsLike:
        Number(
          current.apparent_temperature
        ),

      humidity:
        Number(
          current.relative_humidity_2m
        ),

      uvIndex:
        Number(
          current.uv_index
        ),

      windSpeed:
        Number(
          current.wind_speed_10m
        ),

      windDirection:
        getWindDirection(
          Number(
            current.wind_direction_10m
          )
        ),

      precipitation:
        Number(
          current.precipitation
        ),

      condition:
        currentCondition.condition,

      icon:
        currentCondition.icon,

      warningTitle:
        warning.title,

      warningDescription:
        warning.description,

      forecast,
    }
  } catch (error) {
    weatherError.value =
      error instanceof Error
        ? error.message
        : 'No se pudo obtener el clima.'

    console.error(
      '[WEATHER] Error:',
      error
    )
  } finally {
    weatherLoading.value = false
  }
}

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

/* =====================================================
   WEBSOCKET
===================================================== */

const {
  wsConnected,
  connectWebSocket,
  send,
} = useWebSocket({
  onMessage(data) {
    /*
     * STATUS
     */
    if (
      typeof data === 'object' &&
      data !== null &&
      'type' in data &&
      data.type === 'status'
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
      typeof data === 'object' &&
      data !== null &&
      'type' in data &&
      data.type ===
        'client_registration'
    ) {
      console.log(
        '[WS] ✓ Confirmación de registro:',
        data
      )

      return
    }

    /*
     * COMMAND
     */
    if (
      typeof data === 'object' &&
      data !== null &&
      'type' in data &&
      data.type === 'command'
    ) {
      console.log(
        '[WS] Comando recibido:',
        data
      )

      return
    }
  },
})

/* =====================================================
   DEVICES
===================================================== */

const {
  devices,

  totalSensors,
  onlineDevices,
  offlineDevices,

  totalActuators,
  activeActuators,

  availability,

  updateDevicesFromServer,

  isActuatorChanging,

  toggleActuator,

  actuatorButtonLabel,
} = useDevices(send)


/* =====================================================
   DASHBOARD PREFERENCES
===================================================== */

type DashboardPanel =
  | 'stats'
  | 'overview'
  | 'devices'
  | 'activity'
  | 'weather'

interface DashboardPreferences {
  stats: boolean
  overview: boolean
  devices: boolean
  activity: boolean
  weather: boolean
}

const defaultDashboardPreferences: DashboardPreferences = {
  stats: true,
  overview: true,
  devices: true,
  activity: true,
  weather: true,
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

/* =====================================================
   WEATHER SETTINGS UI
===================================================== */

async function openWeatherSettings() {
  settingsMessage.value = ''
  settingsError.value = ''
  citySearchError.value = ''

  weatherSettingsOpen.value = true

  await loadWeatherSettings()
}

function closeWeatherSettings() {
  if (
    settingsLoading.value ||
    citySearching.value
  ) {
    return
  }

  weatherSettingsOpen.value = false
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
   LIFECYCLE
===================================================== */


onMounted(async () => {
  applyTheme()
  checkApi()

  updateCurrentTime()

  currentTimeTimer = setInterval(
    updateCurrentTime,
    1000
  )

  await loadWeatherSettings()
  await loadWeather()

  connectWebSocket()
})


onUnmounted(() => {
  if (currentTimeTimer) {
    clearInterval(
      currentTimeTimer
    )

    currentTimeTimer = null
  }
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
          <!--fecha y hora
          <div class="weather-datetime">-->
              <div class="user-details">
                <span>
                  {{ weather.date }}<!--fecha formato corta-->
                

                </span>

                <strong>
                  <!--{{ weather.time }}--><!--hora del clima-->
                  {{ currentTime }}<!--hora local-->
                </strong>

              </div>

          <!--fin fecha y hora-->

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
                <label class="customizer-option">

                  <div>

                    <strong>
                      Clima
                    </strong>

                    <span>
                      Temperatura y condiciones
                      meteorológicas.
                    </span>

                  </div>

                  <input
                    type="checkbox"
                    :checked="
                      dashboardPreferences.weather
                    "
                    @change="
                      toggleDashboardPanel('weather')
                    "
                  />

                </label>
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


<div class="dashboard-two-columns">

  <!-- =================================================
       COLUMNA IZQUIERDA: DISPOSITIVOS
  ================================================== -->

<section
  v-if="dashboardPreferences.devices"
  class="devices-section dashboard-panel"
>

    <div class="section-heading">

      <div>
        <span class="section-kicker">
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
        type="button"
        @click="selectSection('devices')"
      >
        Ver todos
        <span>→</span>
      </button>

    </div>

    <div
      v-if="devices.length"
      class="devices-grid"
    >
<!--muestra la tarjeta de dispositovos conectados-->
      <DeviceCard 
        v-for="device in devices"
        :key="device.id"
        :device="device"
        :is-actuator-changing="
          isActuatorChanging
        "
        :actuator-button-label="
          actuatorButtonLabel
        "
        @toggle-actuator="
          toggleActuator
        "
      />
<!--fin muestra la tarjeta de dispositovos conectados-->

    </div>

    <div
      v-else
      class="empty-state"
    >

      <div class="empty-state-icon">
        ▣
      </div>

      <h3>
        Esperando dispositivos
      </h3>

      <p>
        Cuando un ESP32 se registre,
        aparecerá automáticamente aquí.
      </p>

      <div class="waiting-status">

        <span
          class="status-dot"
          :class="{
            online: wsConnected
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


  <!-- =================================================
       COLUMNA DERECHA: CLIMA
  ================================================== -->
  
  <WeatherPanel
  v-if="dashboardPreferences.weather"
  :weather="weather"
  @open-settings="openWeatherSettings"
/>

</div>

<!-- FIN DISPOSITIVOS + CLIMA -->
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

 <!--muestra la tarjeta de dispositovos conectados-->
      <DeviceCard 
        v-for="device in devices"
        :key="device.id"
        :device="device"
        :is-actuator-changing="
          isActuatorChanging
        "
        :actuator-button-label="
          actuatorButtonLabel
        "
        @toggle-actuator="
          toggleActuator
        "
      />
<!--fin muestra la tarjeta de dispositovos conectados-->
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

  <!-- WEATHER CONFIGURATION MODAL -->

  <WeatherSettingsModal
  :open="weatherSettingsOpen"
  :weather-settings="weatherSettings"
  :settings-loading="settingsLoading"
  :settings-message="settingsMessage"
  :settings-error="settingsError"
  :city-searching="citySearching"
  :city-search-error="citySearchError"
  :city-search-results="citySearchResults"
  @close="closeWeatherSettings"
  @save="saveWeatherSettings"
  @search="searchCity"
  @select-city="selectCity"
  @update-city="
    weatherSettings.city = $event
  "
/>

</template>