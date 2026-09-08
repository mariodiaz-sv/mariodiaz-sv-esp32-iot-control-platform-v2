<script setup lang="ts">

import { computed, onMounted, onUnmounted, ref } from 'vue'
import { getHealth } from './services/api'


// =====================================================
// TIPOS
// =====================================================

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


// =====================================================
// ESTADO GENERAL
// =====================================================

const activeSection = ref('dashboard')


// =====================================================
// API
// =====================================================

const apiOnline = ref(false)
const apiVersion = ref('')


// =====================================================
// WEBSOCKET
// =====================================================

const wsConnected = ref(false)

const ws = ref<WebSocket | null>(null)


// =====================================================
// DISPOSITIVOS
// =====================================================
//
// IMPORTANTE:
//
// Vue NO define dispositivos aquí.
//
// Vue NO define LEDs aquí.
//
// Vue NO define GPIO aquí.
//
// server.php es la fuente de verdad.
//
// Cuando llega:
//
// {
//   type: "status",
//   devices: [...]
//
// }
//
// Vue reemplaza completamente el estado local.
//
// =====================================================

const devices = ref<Device[]>([])


// =====================================================
// SENSORES
// =====================================================
//
// Por ahora server.php todavía no envía sensores.
//
// Dejamos el contador preparado para la futura
// integración del módulo de sensores.
//
// =====================================================

const totalSensors = ref(0)


// =====================================================
// COMPUTED
// =====================================================

const onlineDevices = computed(() =>
  devices.value.filter(
    (device) =>
      device.status === 'online',
  ).length,
)


const offlineDevices = computed(() =>
  devices.value.filter(
    (device) =>
      device.status === 'offline',
  ).length,
)


// =====================================================
// API HEALTH
// =====================================================

async function checkApi() {

  try {

    const health =
      await getHealth()


    apiOnline.value =
      health.status === 'ok'


    apiVersion.value =
      health.version

  }

  catch (error) {

    apiOnline.value = false

    apiVersion.value = ''

    console.error(
      '[API] No se pudo conectar con Laravel:',
      error,
    )

  }

}


// =====================================================
// MAPEAR LED DESDE SERVER.PHP
// =====================================================

function mapWebSocketLed(
  wsLed: WebSocketLed,
): Led {

  return {

    id:
      Number(wsLed.id),

    name:
      wsLed.name,

    gpio:
      Number(wsLed.gpio),

    state:
      wsLed.state === 'ON',

  }

}


// =====================================================
// MAPEAR DEVICE DESDE SERVER.PHP
// =====================================================

function mapWebSocketDevice(
  wsDevice: WebSocketDevice,
): Device {

  const leds =
    Array.isArray(wsDevice.leds)
      ? wsDevice.leds.map(
          mapWebSocketLed,
        )
      : []


  return {

    id:
      Number(wsDevice.id),

    name:
      wsDevice.name,

    /*
    |--------------------------------------------------------------------------
    | POR AHORA SERVER.PHP NO ENVÍA type
    | NI location.
    |--------------------------------------------------------------------------
    |
    | No inventamos información del ESP32.
    |
    | Estos valores son solamente presentación
    | hasta que Laravel tenga esos campos.
    |
    */

    type:
      'ESP32',

    location:
      'Sin ubicación',

    registered:
      wsDevice.registered ?? true,

    status:
      wsDevice.online === false
        ? 'offline'
        : 'online',

    leds,

  }

}


// =====================================================
// ACTUALIZAR DISPOSITIVOS DESDE SERVER.PHP
// =====================================================
//
// IMPORTANTE:
//
// Cada status representa el estado completo.
//
// No hacemos:
//
// push()
// add()
// merge()
//
// Simplemente reemplazamos devices.
//
// Esto evita que Vue conserve dispositivos
// que ya no existen en el servidor.
//
// =====================================================

function updateDevicesFromServer(
  serverDevices: WebSocketDevice[],
) {

  const newDevices =
    serverDevices.map(
      mapWebSocketDevice,
    )


  devices.value =
    newDevices


  // ===================================================
  // DEBUG
  // ===================================================

  console.log(
    '[WS] Estado recibido desde server.php:',
    newDevices,
  )


  for (
    const device of newDevices
  ) {

    console.log(
      `[WS] Device ${device.id} | ${device.name} | ${device.status}`,
    )


    for (
      const led of device.leds
    ) {

      console.log(
        `[WS] Device ${device.id} | LED ${led.id} | GPIO ${led.gpio} | ${
          led.state
            ? 'ON'
            : 'OFF'
        }`,
      )

    }

  }

}


// =====================================================
// WEBSOCKET
// =====================================================

function connectWebSocket() {

  console.log(
    '[WS] Conectando...',
  )


  /*
  |--------------------------------------------------------------------------
  | DESARROLLO LOCAL
  |--------------------------------------------------------------------------
  |
  | IMPORTANTE:
  |
  | Este es el navegador.
  |
  | Por eso usamos 127.0.0.1:8080 para Vue.
  |
  |--------------------------------------------------------------------------
  */

  const socket =
    new WebSocket(
      'ws://127.0.0.1:8080',
    )


  ws.value =
    socket


  // ===================================================
  // OPEN
  // ===================================================

  socket.onopen = () => {

    wsConnected.value =
      true


    console.log(
      '[WS] Conectado correctamente',
    )

  }


  // ===================================================
  // MESSAGE
  // ===================================================

  socket.onmessage = (
    event,
  ) => {

    console.log(
      '[WS] Mensaje recibido:',
      event.data,
    )


    try {

      const data =
        JSON.parse(
          event.data,
        )


      // =================================================
      // STATUS
      // =================================================

      if (
        data.type === 'status'
      ) {

        const statusMessage =
          data as WebSocketStatusMessage


        if (
          !Array.isArray(
            statusMessage.devices,
          )
        ) {

          console.warn(
            '[WS] status.devices no es un array',
          )

          return
        }


        updateDevicesFromServer(
          statusMessage.devices,
        )


        return
      }


      // =================================================
      // REGISTRATION
      // =================================================
      //
      // Este mensaje normalmente está dirigido al ESP32.
      //
      // Vue puede recibirlo si existen varios clientes,
      // pero no necesita modificar el estado.
      //
      // El estado oficial llega mediante "status".
      //
      // =================================================

      if (
        data.type === 'registration'
      ) {

        console.log(
          '[WS] Confirmación de registro:',
          data,
        )

        return
      }


      // =================================================
      // COMMAND
      // =================================================
      //
      // El servidor puede enviar:
      //
      // {
      //   type: "command",
      //   command: "led_set",
      //   device_id: 1,
      //   led_id: 2,
      //   gpio: 19,
      //   state: "ON"
      // }
      //
      // El comando es informativo.
      //
      // Vue NO cambia el LED manualmente aquí.
      //
      // Esperamos el siguiente "status".
      //
      // =================================================

      if (
        data.type === 'command'
      ) {

        console.log(
          '[WS] Comando recibido:',
          data,
        )

        return
      }


      // =================================================
      // OTROS MENSAJES
      // =================================================

      console.log(
        '[WS] Tipo de mensaje:',
        data.type,
      )

    }

    catch (error) {

      console.error(
        '[WS] Error procesando mensaje:',
        error,
      )

    }

  }


  // ===================================================
  // ERROR
  // ===================================================

  socket.onerror = (
    error,
  ) => {

    wsConnected.value =
      false


    console.error(
      '[WS] Error:',
      error,
    )

  }


  // ===================================================
  // CLOSE
  // ===================================================

  socket.onclose = () => {

    wsConnected.value =
      false


    console.log(
      '[WS] Conexion cerrada',
    )

  }

}


// =====================================================
// TOGGLE LED
// =====================================================
//
// Vue NO conoce GPIO.
//
// Vue NO decide ON/OFF.
//
// Vue solamente dice:
//
// device_id
// led_id
//
// server.php decide el GPIO y el nuevo estado.
//
// =====================================================

function toggleLed(
  device: Device,
  led: Led,
) {

  // ===================================================
  // DEVICE OFFLINE
  // ===================================================

  if (
    device.status !== 'online'
  ) {

    console.warn(
      '[WS] Dispositivo offline:',
      device.id,
    )

    return
  }


  // ===================================================
  // WEBSOCKET
  // ===================================================

  if (
    !ws.value ||
    ws.value.readyState !==
      WebSocket.OPEN
  ) {

    console.warn(
      '[WS] WebSocket no esta conectado',
    )

    return
  }


  // ===================================================
  // MENSAJE
  // ===================================================
  //
  // IMPORTANTE:
  //
  // No enviamos GPIO.
  //
  // No enviamos state.
  //
  // No enviamos nombre.
  //
  // server.php ya conoce todo eso.
  //
  // ===================================================

  const message = {

    command:
      'led_toggle',

    device_id:
      device.id,

    led_id:
      led.id,

  }


  // ===================================================
  // DEBUG
  // ===================================================

  console.log(
    '[WS] Enviando:',
    message,
  )


  // ===================================================
  // ENVIAR
  // ===================================================

  ws.value.send(
    JSON.stringify(
      message,
    ),
  )

}


// =====================================================
// SELECCIONAR SECCION
// =====================================================

function selectSection(
  section: string,
) {

  activeSection.value =
    section

}


// =====================================================
// MOUNT
// =====================================================

onMounted(() => {

  checkApi()

  connectWebSocket()

})


// =====================================================
// UNMOUNT
// =====================================================

onUnmounted(() => {

  if (ws.value) {

    ws.value.close()

  }

})

</script>


<template>

  <div class="app-shell">


    <!-- =================================================
         SIDEBAR
         ================================================= -->

    <aside class="sidebar">

      <div class="brand">

        <div class="brand-icon">
          <span>⚡</span>
        </div>

        <div>

          <strong>
            IoT Control
          </strong>

          <small>
            Platform V2
          </small>

        </div>

      </div>


      <nav class="navigation">

        <p class="nav-label">
          PLATAFORMA
        </p>


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

          <span class="nav-icon">
            ▪
          </span>

          Dashboard

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

          <span class="nav-icon">
            ▣
          </span>

          Dispositivos

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

          <span class="nav-icon">
            ◉
          </span>

          Sensores

        </button>


        <p
          class="nav-label nav-label-spaced"
        >
          ADMINISTRACIÓN
        </p>


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

          <span class="nav-icon">
            ♙
          </span>

          Usuarios

        </button>

      </nav>


      <div class="sidebar-footer">

        <div
          class="connection-status"
        >

          <span
            class="status-dot"
            :class="{
              online:
                wsConnected
            }"
          ></span>


          <div>

            <strong>
              WebSocket
            </strong>

            <small>

              {{
                wsConnected
                  ? 'Conectado'
                  : 'Desconectado'
              }}

            </small>

          </div>

        </div>

      </div>

    </aside>


    <!-- =================================================
         MAIN
         ================================================= -->

    <main class="main-content">


      <!-- =================================================
           TOPBAR
           ================================================= -->

      <header class="topbar">

        <div>

          <p class="breadcrumb">
            IoT Control Platform
          </p>


          <h1>

            {{
              activeSection ===
              'dashboard'

                ? 'Dashboard'

                : activeSection ===
                  'devices'

                  ? 'Dispositivos'

                  : activeSection ===
                    'sensors'

                    ? 'Sensores'

                    : 'Usuarios'
            }}

          </h1>

        </div>


        <div
          class="topbar-actions"
        >

          <div
            class="api-status"
          >

            <span
              class="status-dot"
              :class="{
                online:
                  apiOnline
              }"
            ></span>


            {{
              apiOnline
                ? `API online · v${apiVersion}`
                : 'API offline'
            }}

          </div>


          <div
            class="user-menu"
          >

            <div class="avatar">
              M
            </div>


            <div
              class="user-info"
            >

              <strong>
                Administrador
              </strong>

              <small>
                Admin
              </small>

            </div>

          </div>

        </div>

      </header>


      <!-- =================================================
           DASHBOARD
           ================================================= -->

      <section
        v-if="
          activeSection ===
          'dashboard'
        "
        class="content"
      >


        <!-- WELCOME -->

        <div class="welcome">

          <div>

            <p class="eyebrow">
              CENTRO DE CONTROL
            </p>


            <h2>
              Resumen de tu infraestructura IoT
            </h2>


            <p
              class="description"
            >
              Supervisa tus dispositivos y controla tus actuadores en tiempo real.
            </p>

          </div>


          <div
            class="live-badge"
          >

            <span
              class="status-dot online"
            ></span>

            Tiempo real

          </div>

        </div>


        <!-- =================================================
             STATS
             ================================================= -->

        <div
          class="stats-grid"
        >


          <article
            class="stat-card"
          >

            <div
              class="stat-icon blue"
            >
              ▣
            </div>


            <div>

              <span
                class="stat-label"
              >
                Dispositivos
              </span>


              <strong>
                {{ devices.length }}
              </strong>


              <small>
                Total registrados
              </small>

            </div>

          </article>


          <article
            class="stat-card"
          >

            <div
              class="stat-icon green"
            >
              ✓
            </div>


            <div>

              <span
                class="stat-label"
              >
                En línea
              </span>


              <strong>
                {{ onlineDevices }}
              </strong>


              <small>
                Dispositivos activos
              </small>

            </div>

          </article>


          <article
            class="stat-card"
          >

            <div
              class="stat-icon orange"
            >
              !
            </div>


            <div>

              <span
                class="stat-label"
              >
                Fuera de línea
              </span>


              <strong>
                {{ offlineDevices }}
              </strong>


              <small>
                Requieren atención
              </small>

            </div>

          </article>


          <article
            class="stat-card"
          >

            <div
              class="stat-icon purple"
            >
              ◉
            </div>


            <div>

              <span
                class="stat-label"
              >
                Sensores
              </span>


              <strong>
                {{ totalSensors }}
              </strong>


              <small>
                Sensores registrados
              </small>

            </div>

          </article>

        </div>


        <!-- =================================================
             DEVICES HEADER
             ================================================= -->

        <div
          class="section-header"
        >

          <div>

            <h3>
              Dispositivos
            </h3>


            <p>
              Estado y control de tus dispositivos IoT
            </p>

          </div>


          <button
            class="secondary-button"
            @click="
              selectSection(
                'devices'
              )
            "
          >
            Ver todos
          </button>

        </div>


        <!-- =================================================
             DEVICES
             ================================================= -->

        <div
          class="devices-grid"
        >


          <article
            v-for="
              device in devices
            "
            :key="device.id"
            class="device-card"
          >


            <!-- DEVICE HEADER -->

            <div
              class="device-header"
            >


              <div
                class="device-title"
              >

                <div
                  class="device-icon"
                >
                  ESP
                </div>


                <div>

                  <h4>
                    {{ device.name }}
                  </h4>


                  <p>
                    {{ device.type }} · {{ device.location }}
                  </p>

                </div>

              </div>


              <span
                class="device-status"
                :class="
                  device.status
                "
              >

                <span
                  class="status-dot"
                ></span>


                {{
                  device.status ===
                  'online'
                    ? 'Online'
                    : 'Offline'
                }}

              </span>

            </div>


            <div
              class="device-divider"
            ></div>


            <!-- =================================================
                 LEDS DINAMICOS
                 ================================================= -->

            <div
              class="led-controls"
            >


              <div
                v-for="
                  led in device.leds
                "
                :key="led.id"
                class="led-control"
              >


                <div>

                  <span
                    class="led-name"
                  >
                    {{ led.name }}
                  </span>


                  <small>
                    GPIO {{ led.gpio }}
                  </small>

                </div>


                <button
                  class="led-switch"
                  :class="{
                    on:
                      led.state
                  }"
                  :disabled="
                    device.status !==
                    'online'
                  "
                  @click="
                    toggleLed(
                      device,
                      led
                    )
                  "
                >

                  <span></span>


                  {{
                    led.state
                      ? 'ON'
                      : 'OFF'
                  }}

                </button>

              </div>


              <!-- SIN LEDS -->

              <div
                v-if="
                  device.leds.length ===
                  0
                "
                class="no-leds"
              >

                No hay actuadores configurados.

              </div>

            </div>

          </article>


          <!-- SIN DISPOSITIVOS -->

          <div
            v-if="
              devices.length === 0
            "
            class="empty-module"
          >

            <div
              class="empty-icon"
            >
              ▣
            </div>


            <h3>
              No hay dispositivos conectados
            </h3>


            <p>
              Esperando el registro de dispositivos IoT.
            </p>

          </div>

        </div>


        <!-- =================================================
             ACTIVITY
             ================================================= -->

        <div
          class="section-header activity-header"
        >

          <div>

            <h3>
              Actividad reciente
            </h3>


            <p>
              Eventos de la plataforma
            </p>

          </div>

        </div>


        <div
          class="activity-card"
        >


          <div
            class="activity-item"
          >

            <div
              class="activity-icon green"
            >
              ✓
            </div>


            <div>

              <strong>
                Sistema IoT activo
              </strong>


              <small>
                Comunicación WebSocket en tiempo real
              </small>

            </div>


            <time>
              Ahora
            </time>

          </div>


          <div
            class="activity-item"
          >

            <div
              class="activity-icon blue"
            >
              ●
            </div>


            <div>

              <strong>
                Dispositivos dinámicos
              </strong>


              <small>
                Los dispositivos y actuadores se cargan desde el servidor
              </small>

            </div>


            <time>
              Ahora
            </time>

          </div>

        </div>

      </section>


      <!-- =================================================
           DEVICES
           ================================================= -->

      <section
        v-else-if="
          activeSection ===
          'devices'
        "
        class="content"
      >

        <div
          class="page-intro"
        >

          <p class="eyebrow">
            GESTIÓN
          </p>


          <h2>
            Dispositivos
          </h2>


          <p>
            Administra los dispositivos ESP32 registrados en la plataforma.
          </p>

        </div>


        <div
          class="empty-module"
        >

          <div
            class="empty-icon"
          >
            ▣
          </div>


          <h3>
            Gestión de dispositivos
          </h3>


          <p>
            Aquí conectaremos el CRUD de dispositivos con Laravel.
          </p>

        </div>

      </section>


      <!-- =================================================
           SENSORS
           ================================================= -->

      <section
        v-else-if="
          activeSection ===
          'sensors'
        "
        class="content"
      >

        <div
          class="page-intro"
        >

          <p class="eyebrow">
            GESTIÓN
          </p>


          <h2>
            Sensores
          </h2>


          <p>
            Administra los sensores asociados a tus dispositivos.
          </p>

        </div>


        <div
          class="empty-module"
        >

          <div
            class="empty-icon"
          >
            ◉
          </div>


          <h3>
            Gestión de sensores
          </h3>


          <p>
            Aquí conectaremos el CRUD de sensores con Laravel.
          </p>

        </div>

      </section>


      <!-- =================================================
           USERS
           ================================================= -->

      <section
        v-else
        class="content"
      >

        <div
          class="page-intro"
        >

          <p class="eyebrow">
            ADMINISTRACIÓN
          </p>


          <h2>
            Usuarios
          </h2>


          <p>
            Administra los usuarios y permisos de la plataforma.
          </p>

        </div>


        <div
          class="empty-module"
        >

          <div
            class="empty-icon"
          >
            ♙
          </div>


          <h3>
            Gestión de usuarios
          </h3>


          <p>
            Aquí conectaremos el CRUD de usuarios con Laravel.
          </p>

        </div>

      </section>


    </main>

  </div>

</template>
