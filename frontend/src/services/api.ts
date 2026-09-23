/**

api.ts

Capa de comunicación HTTP entre el frontend Vue y la API

REST del backend Laravel.

Centraliza:

La URL base de la API mediante variables de entorno.

Las peticiones HTTP mediante apiRequest().

La comprobación del estado y versión del backend.

La obtención de los datos meteorológicos.

La consulta de la ubicación configurada para el clima.

La actualización de la ubicación meteorológica.

También define las interfaces TypeScript utilizadas para

representar las respuestas de la API y mantener tipado

consistente entre el backend y el frontend.

Este archivo no contiene lógica de interfaz ni de WebSocket.

Su responsabilidad es realizar las peticiones HTTP y devolver

los datos al resto de la aplicación.
*/
const API_BASE_URL =
  import.meta.env.VITE_API_BASE_URL ||
  'http://127.0.0.1:8000/api'

export interface HealthResponse {
  status: string
  service: string
  version: string
}

export interface WeatherLocation {
  city: string | null
  country: string | null
  latitude: number
  longitude: number
  timezone: string | null
  elevation: number | null
}


export interface WeatherCurrent {
  time: string
  interval: number
  temperature_2m: number
  apparent_temperature: number
  relative_humidity_2m: number
  uv_index: number
  wind_speed_10m: number
  wind_direction_10m: number
  precipitation: number
  weather_code: number
  is_day: number
}

export interface WeatherForecast {
  time: string[]
  temperature_2m: number[]
  weather_code: number[]
  precipitation: number[]
  precipitation_probability: number[]
  uv_index: number[]
  wind_speed_10m: number[]
  wind_direction_10m: number[]
}

export interface WeatherData {
  location: WeatherLocation
  current: WeatherCurrent | null
  current_units: Record<string, string>
  forecast: WeatherForecast | null
  forecast_units: Record<string, string>
}

async function apiRequest<T>(
  endpoint: string,
  options?: RequestInit
): Promise<T> {
  const response = await fetch(
    `${API_BASE_URL}${endpoint}`,
    {
      ...options,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...(options?.headers || {}),
      },
    }
  )

  if (!response.ok) {
    throw new Error(
      `API error: ${response.status}`
    )
  }

  return response.json() as Promise<T>
}

export async function getHealth(): Promise<HealthResponse> {
  return apiRequest<HealthResponse>('/health')
}

export async function getWeather(): Promise<WeatherData> {
  return apiRequest<WeatherData>('/weather')
}

export async function getWeatherLocation(): Promise<WeatherLocation> {
  return apiRequest<WeatherLocation>(
    '/weather/location'
  )
}

export async function updateWeatherLocation(
  location: {
    city?: string | null
    latitude: number
    longitude: number
    timezone?: string | null
  }
): Promise<WeatherLocation> {
  return apiRequest<WeatherLocation>(
    '/weather/location',
    {
      method: 'PUT',
      body: JSON.stringify(location),
    }
  )
}
