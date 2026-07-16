import { reactive } from 'vue'

/**
 * A tiny global toast/notification system.
 * Usage:
 *   const { success, error } = useToast()
 *   success('Saved!')
 */
const state = reactive({
  toasts: [],
})

let counter = 0

function push(message, type = 'info', timeout = 3500) {
  const id = ++counter
  state.toasts.push({ id, message, type })
  if (timeout) {
    setTimeout(() => remove(id), timeout)
  }
  return id
}

function remove(id) {
  const index = state.toasts.findIndex((t) => t.id === id)
  if (index !== -1) state.toasts.splice(index, 1)
}

export function useToast() {
  return {
    toasts: state.toasts,
    remove,
    notify: push,
    success: (msg, timeout) => push(msg, 'success', timeout),
    error: (msg, timeout) => push(msg, 'error', timeout),
    info: (msg, timeout) => push(msg, 'info', timeout),
  }
}
