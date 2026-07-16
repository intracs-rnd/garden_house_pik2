<script setup>
defineProps({
  text: { type: String, default: 'Memuat...' },
  inline: { type: Boolean, default: false },
})
</script>

<template>
  <div :class="['loader', { 'loader-inline': inline }]">
    <span class="loader-spinner" aria-hidden="true"></span>
    <span v-if="text" class="loader-text">{{ text }}</span>
  </div>
</template>

<style scoped>
.loader {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 40px 20px;
  color: var(--color-text-muted);
}
.loader-inline {
  flex-direction: row;
  padding: 0;
  gap: 8px;
}
.loader-spinner {
  width: 28px;
  height: 28px;
  border: 3px solid var(--color-border);
  border-top-color: var(--color-primary);
  border-radius: 50%;
  animation: loader-spin 0.7s linear infinite;
}
.loader-inline .loader-spinner {
  width: 16px;
  height: 16px;
  border-width: 2px;
}
.loader-text {
  font-size: 14px;
}
@keyframes loader-spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
