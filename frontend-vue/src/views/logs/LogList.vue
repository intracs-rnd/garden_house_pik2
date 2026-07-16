<script setup>
import { onMounted, ref } from 'vue'
import errorLogApi from '@/api/errorLog'
import { useToast } from '@/composables/useToast'
import { extractErrorMessage } from '@/utils/helper'
import { formatDateTime } from '@/utils/formatter'
import { downloadBlob } from '@/utils/download'
import PageHeader from '@/components/layout/Header.vue'
import Button from '@/components/common/Button.vue'
import DataTable from '@/components/common/DataTable.vue'
import Modal from '@/components/common/Modal.vue'

const toast = useToast()

const columns = [
  { key: 'created_at', label: 'Waktu', width: '160px' },
  { key: 'status_code', label: 'Status', align: 'center', width: '80px' },
  { key: 'type', label: 'Tipe', width: '180px' },
  { key: 'message', label: 'Pesan' },
  { key: 'location', label: 'Lokasi', width: '220px' },
  { key: 'user_name', label: 'User', width: '120px' },
  { key: 'actions', label: '', align: 'right', width: '90px' },
]

const rows = ref([])
const loading = ref(false)
const error = ref('')
const search = ref('')
const meta = ref({ current_page: 1, per_page: 15, total: 0, last_page: 1 })

const downloading = ref('')
const clearing = ref(false)

const detailOpen = ref(false)
const detail = ref(null)
const detailLoading = ref(false)

async function fetchLogs(page = meta.value.current_page, perPage = meta.value.per_page) {
  loading.value = true
  error.value = ''
  try {
    const res = await errorLogApi.list({
      page,
      per_page: perPage,
      search: search.value || undefined,
    })
    rows.value = res.data || []
    if (res.meta) meta.value = res.meta
  } catch (err) {
    error.value = extractErrorMessage(err, 'Gagal memuat log error.')
  } finally {
    loading.value = false
  }
}

function onSearch() {
  fetchLogs(1)
}

async function openDetail(row) {
  detailOpen.value = true
  detail.value = null
  detailLoading.value = true
  try {
    const res = await errorLogApi.get(row.id)
    detail.value = res.data
  } catch (err) {
    toast.error(extractErrorMessage(err, 'Gagal memuat detail log.'))
    detailOpen.value = false
  } finally {
    detailLoading.value = false
  }
}

async function download(format) {
  downloading.value = format
  try {
    const blob = await errorLogApi.download(format)
    const stamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-')
    const ext = format === 'json' ? 'json' : 'csv'
    downloadBlob(blob, `error-logs-${stamp}.${ext}`)
    toast.success('Log error berhasil diunduh.')
  } catch (err) {
    toast.error(extractErrorMessage(err, 'Gagal mengunduh log error.'))
  } finally {
    downloading.value = ''
  }
}

async function clearLogs() {
  if (!window.confirm('Hapus SEMUA log error? Tindakan ini tidak bisa dibatalkan.')) return
  clearing.value = true
  try {
    await errorLogApi.clear()
    toast.success('Semua log error berhasil dihapus.')
    fetchLogs(1)
  } catch (err) {
    toast.error(extractErrorMessage(err, 'Gagal menghapus log error.'))
  } finally {
    clearing.value = false
  }
}

function statusClass(code) {
  if (code >= 500) return 'badge-danger'
  if (code >= 400) return 'badge-warning'
  return 'badge-muted'
}

onMounted(() => fetchLogs(1))
</script>

<template>
  <div>
    <PageHeader title="Log Error" subtitle="Catatan error / bug aplikasi — khusus Super Admin.">
      <template #actions>
        <Button
            variant="secondary"
            size="sm"
            :loading="downloading === 'csv'"
            @click="download('csv')"
        >
          ⬇ Download CSV
        </Button>
        <Button
            variant="secondary"
            size="sm"
            :loading="downloading === 'json'"
            @click="download('json')"
        >
          ⬇ Download JSON
        </Button>
        <Button variant="danger" size="sm" :loading="clearing" @click="clearLogs">
          🗑 Bersihkan
        </Button>
      </template>
    </PageHeader>

    <div class="card">
      <div class="toolbar">
        <form class="search-form" @submit.prevent="onSearch">
          <input
              v-model="search"
              class="form-control"
              type="search"
              placeholder="Cari pesan, tipe, URL, user..."
          />
          <Button type="submit" size="sm" variant="primary">Cari</Button>
        </form>
        <Button size="sm" variant="ghost" @click="fetchLogs()">↻ Muat ulang</Button>
      </div>

      <DataTable
          :columns="columns"
          :rows="rows"
          :loading="loading"
          :error="error"
          :page="meta.current_page"
          :per-page="meta.per_page"
          :total="meta.total"
          :last-page="meta.last_page"
          empty-text="Belum ada error tercatat. 🎉"
          @change-page="(p) => fetchLogs(p)"
          @change-per-page="(pp) => fetchLogs(1, pp)"
      >
        <template #cell-created_at="{ row }">
          {{ formatDateTime(row.created_at) }}
        </template>
        <template #cell-status_code="{ row }">
          <span class="badge" :class="statusClass(row.status_code)">{{ row.status_code || '-' }}</span>
        </template>
        <template #cell-type="{ row }">
          <code class="type-cell">{{ row.type }}</code>
        </template>
        <template #cell-message="{ row }">
          <span class="msg-cell" :title="row.message">{{ row.message }}</span>
        </template>
        <template #cell-location="{ row }">
          <span class="loc-cell" :title="`${row.file}:${row.line}`">
            {{ row.file ? row.file.split(/[\\/]/).pop() : '-' }}<template v-if="row.line">:{{ row.line }}</template>
          </span>
        </template>
        <template #cell-user_name="{ row }">
          {{ row.user_name || '-' }}
        </template>
        <template #cell-actions="{ row }">
          <Button size="sm" variant="ghost" @click="openDetail(row)">Detail</Button>
        </template>
      </DataTable>
    </div>

    <Modal v-model="detailOpen" title="Detail Log Error">
      <div v-if="detailLoading" class="detail-loading">Memuat...</div>
      <div v-else-if="detail" class="detail">
        <div class="detail-grid">
          <div><span>Waktu</span><strong>{{ formatDateTime(detail.created_at) }}</strong></div>
          <div><span>Status</span><strong>{{ detail.status_code || '-' }}</strong></div>
          <div><span>Tipe</span><strong>{{ detail.type }}</strong></div>
          <div><span>Level</span><strong>{{ detail.level }}</strong></div>
          <div><span>Method</span><strong>{{ detail.method || '-' }}</strong></div>
          <div><span>IP</span><strong>{{ detail.ip || '-' }}</strong></div>
          <div><span>User</span><strong>{{ detail.user_name || '-' }}</strong></div>
          <div><span>Lokasi</span><strong>{{ detail.file }}:{{ detail.line }}</strong></div>
        </div>
        <div class="detail-block">
          <span>URL</span>
          <p class="mono">{{ detail.url || '-' }}</p>
        </div>
        <div class="detail-block">
          <span>Pesan</span>
          <p class="mono">{{ detail.message }}</p>
        </div>
        <div class="detail-block">
          <span>Stack Trace</span>
          <pre class="trace">{{ detail.trace || '-' }}</pre>
        </div>
      </div>
      <template #footer>
        <Button variant="secondary" @click="detailOpen = false">Tutup</Button>
      </template>
    </Modal>
  </div>
</template>

<style scoped>
.toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 16px;
  flex-wrap: wrap;
}
.search-form {
  display: flex;
  gap: 8px;
  flex: 1;
  max-width: 420px;
}
.badge {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
}
.badge-danger {
  background: #fee2e2;
  color: #b91c1c;
}
.badge-warning {
  background: #fef3c7;
  color: #b45309;
}
.badge-muted {
  background: #e2e8f0;
  color: #475569;
}
.type-cell {
  font-size: 12px;
  color: #475569;
}
.msg-cell,
.loc-cell {
  display: inline-block;
  max-width: 320px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  vertical-align: bottom;
}
.loc-cell {
  max-width: 200px;
  font-size: 12px;
  color: #64748b;
}
.detail-loading {
  padding: 24px;
  text-align: center;
  color: var(--color-text-muted);
}
.detail-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px 16px;
  margin-bottom: 16px;
}
.detail-grid div {
  display: flex;
  flex-direction: column;
}
.detail-grid span,
.detail-block span {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #94a3b8;
}
.detail-grid strong {
  font-size: 14px;
  word-break: break-word;
}
.detail-block {
  margin-bottom: 14px;
}
.mono {
  font-family: monospace;
  font-size: 13px;
  word-break: break-all;
  margin: 4px 0 0;
}
.trace {
  margin: 4px 0 0;
  padding: 12px;
  background: #0f172a;
  color: #e2e8f0;
  border-radius: 8px;
  font-size: 12px;
  line-height: 1.5;
  max-height: 320px;
  overflow: auto;
  white-space: pre-wrap;
  word-break: break-word;
}
</style>
