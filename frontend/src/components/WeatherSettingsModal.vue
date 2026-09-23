<script setup lang="ts">

interface WeatherSettings {
  city: string
  latitude: number
  longitude: number
  timezone: string
}

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

const props = defineProps<{
  open: boolean
  weatherSettings: WeatherSettings
  settingsLoading: boolean
  settingsMessage: string
  settingsError: string
  citySearching: boolean
  citySearchError: string
  citySearchResults: CitySearchResult[]
}>()

const emit = defineEmits<{
  (event: 'close'): void
  (event: 'save'): void
  (event: 'search'): void
  (event: 'select-city', location: CitySearchResult): void
  (event: 'update-city', city: string): void
}>()

function handleCityInput(event: Event) {
  const target = event.target as HTMLInputElement

  emit(
    'update-city',
    target.value
  )
}

</script>

<template>

  <div
    v-if="props.open"
    class="weather-config-overlay"
    @click.self="emit('close')"
  >

    <section
      class="weather-config-modal"
      role="dialog"
      aria-modal="true"
      aria-labelledby="weather-config-title"
    >

      <!-- HEADER -->

      <header class="weather-config-header">

        <div>

          <span class="section-kicker">
            CONFIGURACIÓN
          </span>

          <h3 id="weather-config-title">
            Configuración del clima
          </h3>

          <p>
            Configura la ubicación utilizada
            para consultar el clima.
          </p>

        </div>

        <button
          type="button"
          class="weather-config-close"
          aria-label="Cerrar configuración del clima"
          :disabled="props.settingsLoading"
          @click="emit('close')"
        >
          ×
        </button>

      </header>

      <!-- FORMULARIO -->

      <form
        class="weather-config-form"
        @submit.prevent="emit('save')"
      >

        <!-- CIUDAD -->

        <label>

          <span>
            Ciudad
          </span>

          <div class="city-search-row">

            <input
              :value="props.weatherSettings.city"
              type="text"
              placeholder="Ej. Santa Tecla"
              :disabled="
                props.settingsLoading ||
                props.citySearching
              "
              @input="handleCityInput"
              @keyup.enter.prevent="emit('search')"
            />

            <button
              type="button"
              class="city-search-button"
              :disabled="
                props.settingsLoading ||
                props.citySearching ||
                !props.weatherSettings.city.trim()
              "
              @click="emit('search')"
            >
              {{
                props.citySearching
                  ? 'Buscando...'
                  : 'Buscar'
              }}
            </button>

          </div>

          <small>
            Escribe una ciudad para obtener
            automáticamente sus coordenadas.
          </small>

        </label>

        <!-- RESULTADOS DE BÚSQUEDA -->

        <div
          v-if="props.citySearchResults.length > 0"
          class="city-search-results"
        >

          <div class="city-search-results-title">
            Selecciona una ubicación:
          </div>

          <button
            v-for="location in props.citySearchResults"
            :key="location.id"
            type="button"
            class="city-search-result"
            @click="
              emit('select-city', location)
            "
          >

            <div class="city-search-result-main">

              <strong>
                {{ location.name }}
              </strong>

              <span>
                {{ location.admin1 }}

                <template
                  v-if="location.admin2"
                >
                  · {{ location.admin2 }}
                </template>
              </span>

            </div>

            <div class="city-search-result-country">

              <span>
                {{ location.country }}
              </span>

              <small>
                {{ location.timezone }}
              </small>

            </div>

          </button>

        </div>

        <!-- ERROR DE BÚSQUEDA -->

        <div
          v-if="props.citySearchError"
          class="weather-config-error"
        >
          {{ props.citySearchError }}
        </div>

        <!-- PREVIEW DE UBICACIÓN -->

        <div
          v-if="
            Number.isFinite(
              Number(props.weatherSettings.latitude)
            ) &&
            Number.isFinite(
              Number(props.weatherSettings.longitude)
            )
          "
          class="weather-location-preview"
        >

          <div class="weather-location-preview-header">

            <span>
              📍
            </span>

            <div>

              <strong>
                Ubicación encontrada
              </strong>

              <small>
                Open-Meteo
              </small>

            </div>

          </div>

          <div class="weather-location-data">

            <div>

              <span>
                Ciudad
              </span>

              <strong>
                {{ props.weatherSettings.city }}
              </strong>

            </div>

            <div>

              <span>
                Latitud
              </span>

              <strong>
                {{ props.weatherSettings.latitude }}
              </strong>

            </div>

            <div>

              <span>
                Longitud
              </span>

              <strong>
                {{ props.weatherSettings.longitude }}
              </strong>

            </div>

            <div>

              <span>
                Zona horaria
              </span>

              <strong>
                {{ props.weatherSettings.timezone }}
              </strong>

            </div>

          </div>

        </div>

        <!-- ERROR GENERAL -->

        <div
          v-if="props.settingsError"
          class="weather-config-error"
        >
          {{ props.settingsError }}
        </div>

        <!-- MENSAJE -->

        <div
          v-if="props.settingsMessage"
          class="weather-config-message"
        >
          {{ props.settingsMessage }}
        </div>

        <!-- ACCIONES -->

        <div class="weather-config-actions">

          <button
            type="button"
            class="weather-config-cancel"
            :disabled="props.settingsLoading"
            @click="emit('close')"
          >
            Cerrar
          </button>

          <button
            type="submit"
            class="weather-config-save"
            :disabled="props.settingsLoading"
          >
            {{
              props.settingsLoading
                ? 'Guardando...'
                : 'Guardar'
            }}
          </button>

        </div>

      </form>

    </section>

  </div>

</template>
