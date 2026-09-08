const API_BASE_URL = 'http://127.0.0.1:8000/api'

export interface HealthResponse {
  status: string
  service: string
  version: string
}

export async function getHealth(): Promise<HealthResponse> {
  const response = await fetch(`${API_BASE_URL}/health`)

  if (!response.ok) {
    throw new Error(`API error: ${response.status}`)
  }

  return response.json() as Promise<HealthResponse>
}