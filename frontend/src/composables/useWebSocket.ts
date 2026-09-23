/**

useWebSocket.ts

Composable encargado de gestionar la conexión WebSocket

entre el frontend Vue y el servidor IoT.

Centraliza:

Creación y cierre de la conexión WebSocket.

Registro del Dashboard Vue ante el servidor.

Recepción y procesamiento de mensajes JSON.

Envío de comandos al servidor.

Estado reactivo de la conexión.

Reconexión automática cuando la conexión se pierde.

Limpieza de timers y conexión al desmontar el componente.

Este composable no contiene lógica específica de dispositivos

ni de actuadores. Su responsabilidad es proporcionar un canal

de comunicación WebSocket reutilizable.

Los mensajes recibidos se entregan mediante onMessage para que

otros composables, como useDevices.ts, puedan procesarlos.
*/
import {
  onUnmounted,
  ref,
} from 'vue'

interface UseWebSocketOptions {
  onMessage?: (
    data: unknown
  ) => void
}

export function useWebSocket(
  options: UseWebSocketOptions = {}
) {
  const ws = ref<WebSocket | null>(null)

  const wsConnected = ref(false)

  let reconnectTimer:
    ReturnType<typeof setTimeout> | null = null

  let componentUnmounted = false

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

    const WS_URL =
      import.meta.env.VITE_WS_URL ||
      'ws://127.0.0.1:8080'

    const socket =
      new WebSocket(WS_URL)

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

      /*
       * REGISTRO DE VUE
       *
       * Identificamos esta conexión
       * ante el servidor WebSocket.
       */

      socket.send(
        JSON.stringify({
          type: 'register_client',
          client_type: 'vue',
          name: 'Vue Dashboard',
        })
      )

      console.log(
        '[WS] ✓ Cliente Vue registrado'
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

        options.onMessage?.(data)

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

  function disconnectWebSocket() {
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
  }

  function send(
    data: unknown
  ) {
    if (
      !ws.value ||
      ws.value.readyState !==
        WebSocket.OPEN
    ) {
      console.warn(
        '[WS] WebSocket no está conectado'
      )

      return false
    }

    try {
      ws.value.send(
        JSON.stringify(data)
      )

      return true

    } catch (error) {
      console.error(
        '[WS] Error enviando mensaje:',
        error
      )

      return false
    }
  }

  onUnmounted(() => {
    disconnectWebSocket()
  })

  return {
    ws,
    wsConnected,
    connectWebSocket,
    disconnectWebSocket,
    send,
  }
}
