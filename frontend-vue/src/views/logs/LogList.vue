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

<style scoped src="./LogList.css"></style>
