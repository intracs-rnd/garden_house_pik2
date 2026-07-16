<script setup>
import { useToast } from '@/composables/useToast'

const { toasts, remove } = useToast()
</script>

<template>
  <Teleport to="body">
    <div class="toast-host">
      <TransitionGroup name="toast">
        <div
          v-for="toast in toasts"
          :key="toast.id"
          class="toast"
          :class="`toast-${toast.type}`"
          @click="remove(toast.id)"
        >
          {{ toast.message }}
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>

<style scoped>
.toast-host {
  position: fixed;
  top: 20px;
  right: 20px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  z-index: 2000;
}
.toast {
  min-width: 240px;
  max-width: 360px;
  padding: 12px 16px;
  border-radius: var(--radius-sm);
  color: #fff;
  font-size: 14px;
  box-shadow: var(--shadow-lg);
  cursor: pointer;
}
.toast-success { background: #16a34a; }
.toast-error { background: #dc2626; }
.toast-info { background: #334155; }

.toast-enter-active,
.toast-leave-active {
  transition: all 0.25s ease;
}
.toast-enter-from,
.toast-leave-to {
  opacity: 0;
  transform: translateX(20px);
}
</style>
