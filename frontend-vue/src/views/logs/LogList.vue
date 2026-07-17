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
const copied = ref(false)

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

async function copyTrace() {
  if (!detail.value?.trace) return
  try {
    await navigator.clipboard.writeText(detail.value.trace)
    copied.value = true
    setTimeout(() => (copied.value = false), 1500)
  } catch {
    toast.error('Gagal menyalin ke clipboard.')
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
      <div v-if="detailLoading" class="detail-loading">
        <span class="spinner"></span>
        Memuat detail...
      </div>
      <div v-else-if="detail" class="detail">
        <!-- Header ringkas (tetap terlihat, tidak ikut scroll) -->
        <div class="detail-summary">
          <span class="badge" :class="statusClass(detail.status_code)">
            {{ detail.status_code || '-' }}
          </span>
          <code class="type-pill">{{ detail.type }}</code>
          <span class="detail-time">{{ formatDateTime(detail.created_at) }}</span>
        </div>

        <!-- Konten yang bisa discroll -->
        <div class="detail-scroll">
          <!-- Pesan error -->
          <div class="detail-section">
            <h4 class="section-title">Pesan</h4>
            <p class="message-box">{{ detail.message }}</p>
          </div>

          <!-- Info request -->
          <div class="detail-section">
            <h4 class="section-title">Informasi Request</h4>
            <div class="info-grid">
              <div class="info-item">
                <span class="info-label">Method</span>
                <strong class="info-value">{{ detail.method || '-' }}</strong>
              </div>
              <div class="info-item">
                <span class="info-label">Level</span>
                <strong class="info-value">{{ detail.level || '-' }}</strong>
              </div>
              <div class="info-item">
                <span class="info-label">IP Address</span>
                <strong class="info-value">{{ detail.ip || '-' }}</strong>
              </div>
              <div class="info-item">
                <span class="info-label">User</span>
                <strong class="info-value">{{ detail.user_name || '-' }}</strong>
              </div>
              <div class="info-item info-item--wide">
                <span class="info-label">Lokasi File</span>
                <strong class="info-value mono-value">{{ detail.file }}<template v-if="detail.line">:{{ detail.line }}</template></strong>
              </div>
              <div class="info-item info-item--wide">
                <span class="info-label">URL</span>
                <strong class="info-value mono-value">{{ detail.url || '-' }}</strong>
              </div>
            </div>
          </div>

          <!-- Stack trace -->
          <div class="detail-section">
            <div class="section-title-row">
              <h4 class="section-title">Stack Trace</h4>
              <button
                  v-if="detail.trace"
                  type="button"
                  class="copy-btn"
                  @click="copyTrace"
              >
                {{ copied ? '✓ Disalin' : '⧉ Salin' }}
              </button>
            </div>
            <pre class="trace">{{ detail.trace || 'Tidak ada stack trace.' }}</pre>
          </div>
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

/* ---- Detail modal ---- */
.detail-loading {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 40px;
  color: var(--color-text-muted, #64748b);
  font-size: 14px;
}
.spinner {
  width: 16px;
  height: 16px;
  border: 2px solid #e2e8f0;
  border-top-color: #64748b;
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
}
@keyframes spin {
  to { transform: rotate(360deg); }
}

.detail {
  display: flex;
  flex-direction: column;
  /* Batasi tinggi total modal supaya tidak melebihi layar laptop */
  max-height: min(72vh, 620px);
}

.detail-summary {
  display: flex;
  align-items: center;
  gap: 10px;
  padding-bottom: 12px;
  border-bottom: 1px solid #e2e8f0;
  flex-wrap: wrap;
  flex-shrink: 0;
}
.type-pill {
  font-size: 12px;
  font-weight: 600;
  color: #475569;
  background: #f1f5f9;
  padding: 3px 10px;
  border-radius: 6px;
}
.detail-time {
  margin-left: auto;
  font-size: 13px;
  color: #94a3b8;
}

/* Area yang scroll, sementara header ringkas & footer tetap diam */
.detail-scroll {
  display: flex;
  flex-direction: column;
  gap: 14px;
  overflow-y: auto;
  padding: 14px 4px 4px 0;
  margin-right: -4px;
}
.detail-scroll::-webkit-scrollbar {
  width: 6px;
}
.detail-scroll::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 999px;
}

.detail-section {
  display: flex;
  flex-direction: column;
}

.section-title {
  margin: 0 0 8px;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: #94a3b8;
}
.section-title-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}
.section-title-row .section-title {
  margin: 0;
}

.message-box {
  margin: 0;
  padding: 10px 12px;
  background: #fef2f2;
  border: 1px solid #fecaca;
  border-radius: 8px;
  color: #991b1b;
  font-size: 13px;
  line-height: 1.45;
  word-break: break-word;
  max-height: 110px;
  overflow-y: auto;
}

.info-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px 18px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 12px 14px;
}
@media (max-width: 560px) {
  .info-grid {
    grid-template-columns: 1fr;
  }
}
.info-item {
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-width: 0;
}
.info-item--wide {
  grid-column: 1 / -1;
}
.info-label {
  font-size: 10.5px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #94a3b8;
  font-weight: 600;
}
.info-value {
  font-size: 13.5px;
  color: #1e293b;
  word-break: break-word;
}
.mono-value {
  font-family: 'SF Mono', 'Fira Code', monospace;
  font-size: 12.5px;
  font-weight: 500;
}

.copy-btn {
  font-size: 12px;
  font-weight: 500;
  color: #475569;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 4px 10px;
  cursor: pointer;
  transition: background 0.15s ease;
}
.copy-btn:hover {
  background: #e2e8f0;
}

.trace {
  margin: 0;
  padding: 12px;
  background: #0f172a;
  color: #e2e8f0;
  border-radius: 8px;
  font-size: 11.5px;
  line-height: 1.55;
  max-height: 200px;
  overflow: auto;
  white-space: pre-wrap;
  word-break: break-word;
}
</style>