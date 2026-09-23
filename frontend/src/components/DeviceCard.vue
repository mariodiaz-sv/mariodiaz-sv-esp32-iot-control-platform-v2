<script setup lang="ts">

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
  humidity?: number
}

interface Device {
  id: number
  name: string
  type: string
  location: string
  status: 'online' | 'offline'
  registered: boolean
  actuators: Actuator[]
  sensors: Sensor[]
}

const props = defineProps<{
  device: Device
  isActuatorChanging: (
    deviceId: number,
    actuatorId: number
  ) => boolean
  actuatorButtonLabel: (
    device: Device,
    actuator: Actuator
  ) => string
}>()

const emit = defineEmits<{
  (
    event: 'toggle-actuator',
    device: Device,
    actuator: Actuator
  ): void
}>()

function deviceIsOffline(device: Device) {
  return device.status === 'offline'
}

function toggleActuator(
  device: Device,
  actuator: Actuator
) {
  emit(
    'toggle-actuator',
    device,
    actuator
  )
}

</script>

<template>

  <article
    class="device-card"
    :class="{
      'device-offline':
        deviceIsOffline(device)
    }"
  >

    <!-- DEVICE HEADER -->

    <div class="device-card-top">

      <div class="device-info">

        <div
          class="device-avatar"
          :class="{
            online:
              device.status === 'online',
            offline:
              device.status === 'offline'
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
        :class="device.status"
      >

        <span></span>

        {{
          device.status === 'online'
            ? 'ONLINE'
            : 'OFFLINE'
        }}

      </div>

    </div>


    <!-- OFFLINE -->

    <div
      v-if="deviceIsOffline(device)"
      class="device-offline-message"
    >

      <span class="offline-message-icon">
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


    <!-- DEVICE META -->

    <div class="device-meta">

      <span>
        {{ device.actuators.length }}
        actuador{{
          device.actuators.length === 1
            ? ''
            : 'es'
        }}
      </span>

      <span>
        ID #{{ device.id }}
      </span>

    </div>


    <!-- SENSORS -->

    <div
      v-if="
        device.sensors &&
        device.sensors.length
      "
      class="device-subsection"
    >

      <div class="subsection-title">

        <span class="section-kicker">
          SENSORES
        </span>

      </div>

      <div class="led-grid">

        <div
          v-for="sensor in device.sensors"
          :key="sensor.id"
          class="led-control temperature-control"
        >

          <div class="led-info">

            <div
              class="led-indicator temperature-indicator"
            >
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

          <div class="sensor-values">

            <div class="temperature-value">

                {{
                  device.status === 'offline'
                    ? 'Sin conexión'
                    : sensor.temperature !== undefined
                      ? `${sensor.temperature.toFixed(1)}°C`
                      : '--'
                }}

              </div>


            <div
              v-if="
                sensor.humidity !== undefined
              "
              class="humidity-value"
            >
              💧
              {{
                device.status === 'offline'
                  ? 'Sin conexión'
                  : `${sensor.humidity.toFixed(1)}%`
              }}
            </div>


          </div>

        </div>

      </div>

    </div>


    <!-- ACTUATORS -->

    <div class="device-subsection">

      <div class="subsection-title">

        <span class="section-kicker">
          ACTUADORES
        </span>

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
          v-if="
            device.actuators.length === 0
          "
          class="no-actuators"
        >
          Sin actuadores configurados
        </div>

      </div>

    </div>

  </article>

</template>
