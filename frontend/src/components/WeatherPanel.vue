<script setup lang="ts">

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

defineProps<{
  weather: WeatherData
}>()

const emit = defineEmits<{
  (event: 'open-settings'): void
}>()

function openSettings() {
  emit('open-settings')
}

</script>

<template>

  <section
    class="weather-section dashboard-panel"
  >

    <div class="section-heading">

      <div>

        <span class="section-kicker">
          WEATHER
        </span>

        <h3>
          Clima
        </h3>

        <p>
          Condiciones meteorológicas actuales.
        </p>

      </div>

      <button
        type="button"
        class="customize-button weather-config-button"
        @click="openSettings"
      >
        ⚙️ Configuración
      </button>

    </div>

    <article class="device-card">

      <!-- INFORMACIÓN PRINCIPAL -->

      <div class="weather-main">

        <div class="weather-icon">
          {{ weather.icon }}
        </div>

        <div class="weather-main-info">

          <div class="weather-location">

            <strong>
              {{ weather.city }}
            </strong>

            <span>
              {{ weather.country }}
            </span>

          </div>

          <div class="weather-temperature">
            {{ weather.temperature }}°C
          </div>

          <strong class="weather-condition">
            {{ weather.condition }}
          </strong>

          <span class="weather-feels-like">
            Sensación {{ weather.feelsLike }}°C
          </span>

        </div>

      </div>

      <!-- DATOS METEOROLÓGICOS -->

      <div class="weather-details">

        <div class="weather-detail">

          <span class="weather-detail-icon">
            ☀️
          </span>

          <div>
            <small>UV</small>

            <strong>
              {{ weather.uvIndex }}
            </strong>
          </div>

        </div>

        <div class="weather-detail">

          <span class="weather-detail-icon">
            💧
          </span>

          <div>
            <small>Humedad</small>

            <strong>
              {{ weather.humidity }}%
            </strong>
          </div>

        </div>

        <div class="weather-detail">

          <span class="weather-detail-icon">
            💨
          </span>

          <div>
            <small>Viento</small>

            <strong>
              {{ weather.windSpeed }} km/h
            </strong>
          </div>

        </div>

        <div class="weather-detail">

          <span class="weather-detail-icon">
            🧭
          </span>

          <div>
            <small>Dirección</small>

            <strong>
              {{ weather.windDirection }}
            </strong>
          </div>

        </div>

        <div class="weather-detail">

          <span class="weather-detail-icon">
            🌧️
          </span>

          <div>
            <small>Agua</small>

            <strong>
              {{ weather.precipitation }} mm
            </strong>
          </div>

        </div>

      </div>

      <!-- ADVERTENCIA -->

      <div class="weather-warning">

        <div class="weather-warning-icon">
          ⚠️
        </div>

        <div>

          <strong>
            {{ weather.warningTitle }}
          </strong>

          <span>
            {{ weather.warningDescription }}
          </span>

        </div>

      </div>

      <!-- PRONÓSTICO -->

      <div class="weather-forecast">

        <div class="weather-forecast-heading">

          <span class="section-kicker">
            PRONÓSTICO
          </span>

          <span>
            Próximas horas
          </span>

        </div>

        <div class="weather-forecast-grid">

          <div
            v-for="forecast in weather.forecast"
            :key="forecast.time"
            class="weather-forecast-item"
          >

            <strong class="forecast-time">
              {{ forecast.time }}
            </strong>

            <span class="forecast-icon">
              {{ forecast.icon }}
            </span>

            <strong class="forecast-temperature">
              {{ forecast.temperature }}°
            </strong>

            <span class="forecast-condition">
              {{ forecast.condition }}
            </span>

            <span class="forecast-rain">
              💧 {{ forecast.precipitationProbability }}%
            </span>

          </div>

        </div>

      </div>

    </article>

  </section>

</template>
