<script setup>
/**
 * Reusable page header shown at the top of a view.
 * Use the #actions slot to place buttons on the right.
 */
defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
})
</script>

<template>
  <div class="page-header">
    <div>
      <h1>{{ title }}</h1>
      <p v-if="subtitle" class="page-subtitle">{{ subtitle }}</p>
    </div>
    <div v-if="$slots.actions" class="page-header-actions">
      <slot name="actions" />
    </div>
  </div>
</template>

<style scoped>
.page-header-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}
</style>
