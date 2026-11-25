import { reactive } from 'vue'

export interface Toast {
  id: number
  kind: 'success' | 'error' | 'info'
  message: string
}

let nextId = 1
export const toasts = reactive<Toast[]>([])

export function notify(message: string, kind: Toast['kind'] = 'success', timeoutMs = 4000): void {
  const id = nextId++
  toasts.push({ id, kind, message })
  window.setTimeout(() => dismiss(id), timeoutMs)
}

export function dismiss(id: number): void {
  const index = toasts.findIndex((t) => t.id === id)
  if (index >= 0) toasts.splice(index, 1)
}
