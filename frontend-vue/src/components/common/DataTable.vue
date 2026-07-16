<script setup>
import { computed } from 'vue'
import Loader from './Loader.vue'
import Pagination from './Pagination.vue'

/**
 * Reusable, performant data table.
 *
 * Performance notes:
 * - Keeps previously rendered rows on screen while a refetch is in flight
 *   (a subtle overlay is shown instead of replacing the whole table), which
 *   removes the jarring "full reload" flash when changing pages / searching.
 * - Cells are rendered through named slots (`cell-<key>`) so parents only pay
 *   for the markup they actually need.
 *
 * Column shape: { key, label, align?, width?, headerClass?, cellClass? }
 */
const props = defineProps({
  columns: { type: Array, required: true },
  rows: { type: Array, default: () => [] },
  rowKey: { type: [String, Function], default: 'id' },
  // Foreground load (no data yet) -> full spinner.
  loading: { type: Boolean, default: false },
  // Background revalidation (data already visible) -> subtle overlay.
  refreshing: { type: Boolean, default: false },
  error: { type: String, default: '' },
  emptyText: { type: String, default: 'Belum ada data.' },
  loadingText: { type: String, default: 'Memuat data...' },
  showIndex: { type: Boolean, default: false },
  indexLabel: { type: String, default: 'No' },
  // Pagination (server-side).
  paginated: { type: Boolean, default: true },
  page: { type: Number, default: 1 },
  perPage: { type: Number, default: 15 },
  total: { type: Number, default: 0 },
  lastPage: { type: Number, default: 1 },
  // Per-page selector.
  showPerPage: { type: Boolean, default: true },
  perPageOptions: { type: Array, default: () => [10, 15, 25, 50, 100] },
})

const emit = defineEmits(['change-page', 'change-per-page'])

const colspan = computed(() => props.columns.length + (props.showIndex ? 1 : 0))
const showFullLoader = computed(() => props.loading && props.rows.length === 0)
const showOverlay = computed(
  () => (props.loading || props.refreshing) && props.rows.length > 0,
)

function keyFor(row, index) {
  if (typeof props.rowKey === 'function') return props.rowKey(row, index)
  return row?.[props.rowKey] ?? index
}

function indexNumber(index) {
  return (props.page - 1) * props.perPage + index + 1
}

function cellValue(row, col) {
  return col.key ? row?.[col.key] : ''
}
</script>

<template>
  <div class="datatable">
    <div v-if="error" class="alert alert-danger" style="margin: 16px">
      {{ error }}
    </div>

    <template v-else>
      <Loader v-if="showFullLoader" :text="loadingText" />

      <div v-else class="datatable-content">
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th v-if="showIndex" class="text-center" style="width: 60px">
                  {{ indexLabel }}
                </th>
                <th
                  v-for="col in columns"
                  :key="col.key || col.label"
                  :class="col.headerClass || (col.align ? `text-${col.align}` : '')"
                  :style="col.width ? { width: col.width } : null"
                >
                  {{ col.label }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, index) in rows" :key="keyFor(row, index)">
                <td v-if="showIndex" class="text-center">{{ indexNumber(index) }}</td>
                <td
                  v-for="col in columns"
                  :key="col.key || col.label"
                  :class="col.cellClass || (col.align ? `text-${col.align}` : '')"
                >
                  <slot
                    :name="`cell-${col.key}`"
                    :row="row"
                    :value="cellValue(row, col)"
                    :index="index"
                  >
                    {{ cellValue(row, col) ?? '-' }}
                  </slot>
                </td>
              </tr>
              <tr v-if="!rows.length">
                <td :colspan="colspan" class="empty-state">{{ emptyText }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <transition name="datatable-fade">
          <div v-if="showOverlay" class="datatable-overlay">
            <Loader :text="loadingText" inline />
          </div>
        </transition>
      </div>

      <div v-if="paginated && rows.length" class="datatable-footer">
        <label v-if="showPerPage" class="per-page">
          <span>Tampilkan</span>
          <select
            class="form-control per-page-select"
            :value="perPage"
            @change="emit('change-per-page', Number($event.target.value))"
          >
            <option v-for="opt in perPageOptions" :key="opt" :value="opt">{{ opt }}</option>
          </select>
          <span>data</span>
        </label>

        <Pagination
          class="datatable-pagination"
          :current-page="page"
          :last-page="lastPage"
          :total="total"
          :per-page="perPage"
          @change="(p) => emit('change-page', p)"
        />
      </div>
    </template>
  </div>
</template>

<style scoped>
.datatable-content {
  position: relative;
}
.datatable-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 8px;
}
.datatable-pagination {
  flex: 1;
  min-width: 240px;
}
.per-page {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 14px 0 14px 16px;
  font-size: 13px;
  color: var(--color-text-muted);
  white-space: nowrap;
}
.per-page-select {
  width: auto;
  min-width: 72px;
  height: 34px;
  padding: 0 8px;
}
.datatable-overlay {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding-top: 24px;
  background: rgba(255, 255, 255, 0.6);
  backdrop-filter: blur(1px);
  z-index: 2;
}
.datatable-fade-enter-active,
.datatable-fade-leave-active {
  transition: opacity 0.15s ease;
}
.datatable-fade-enter-from,
.datatable-fade-leave-to {
  opacity: 0;
}
</style>
