<script setup>
import { computed } from 'vue'

const props = defineProps({
  currentPage: { type: Number, default: 1 },
  lastPage: { type: Number, default: 1 },
  total: { type: Number, default: 0 },
  perPage: { type: Number, default: 15 },
})

const emit = defineEmits(['change'])

const pages = computed(() => {
  const range = []
  const start = Math.max(1, props.currentPage - 2)
  const end = Math.min(props.lastPage, props.currentPage + 2)
  for (let i = start; i <= end; i++) range.push(i)
  return range
})

const from = computed(() =>
  props.total === 0 ? 0 : (props.currentPage - 1) * props.perPage + 1,
)
const to = computed(() => Math.min(props.currentPage * props.perPage, props.total))

function go(page) {
  if (page < 1 || page > props.lastPage || page === props.currentPage) return
  emit('change', page)
}
</script>

<template>
  <div class="pagination">
    <span class="pagination-info">
      Menampilkan {{ from }}–{{ to }} dari {{ total }} data
    </span>
    <div class="pagination-controls">
      <button
        class="page-btn"
        :disabled="currentPage <= 1"
        @click="go(currentPage - 1)"
      >
        ‹
      </button>
      <button
        v-for="page in pages"
        :key="page"
        class="page-btn"
        :class="{ active: page === currentPage }"
        @click="go(page)"
      >
        {{ page }}
      </button>
      <button
        class="page-btn"
        :disabled="currentPage >= lastPage"
        @click="go(currentPage + 1)"
      >
        ›
      </button>
    </div>
  </div>
</template>

<style scoped>
.pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
  padding: 14px 16px;
}
.pagination-info {
  font-size: 13px;
  color: var(--color-text-muted);
}
.pagination-controls {
  display: flex;
  gap: 6px;
}
.page-btn {
  min-width: 34px;
  height: 34px;
  padding: 0 8px;
  border: 1px solid var(--color-border);
  background: #fff;
  border-radius: var(--radius-sm);
  cursor: pointer;
  font-size: 14px;
  color: var(--color-text);
  transition: all 0.15s ease;
}
.page-btn:hover:not(:disabled):not(.active) {
  background: #f1f5f9;
}
.page-btn.active {
  background: var(--color-primary);
  border-color: var(--color-primary);
  color: #fff;
}
.page-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
