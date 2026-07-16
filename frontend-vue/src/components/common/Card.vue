<script setup>
defineProps({
  title: { type: String, default: '' },
})
</script>

<template>
  <div class="card">
    <div v-if="title || $slots.header" class="card-header">
      <slot name="header">{{ title }}</slot>
    </div>
    <div class="card-body">
      <slot />
    </div>
  </div>
</template>
