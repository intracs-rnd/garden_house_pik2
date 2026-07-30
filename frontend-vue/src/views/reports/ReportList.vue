<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import reportApi from '@/api/report'
import { useToast } from '@/composables/useToast'
import { extractErrorMessage } from '@/utils/helper'
import { formatNumber } from '@/utils/formatter'
import { downloadBlob, openBlob } from '@/utils/download'
import PageHeader from '@/components/layout/Header.vue'
import Button from '@/components/common/Button.vue'
import DataTable from '@/components/common/DataTable.vue'
import Loader from '@/components/common/Loader.vue'
import Modal from '@/components/common/Modal.vue'

const toast = useToast()

const PERIODS = [
  { value: 'harian', label: 'Harian' },
  { value: 'bulanan', label: 'Bulanan' },
  { value: 'tahunan', label: 'Tahunan' },
]

const DIRECTIONS = [
  { value: '', label: 'Semua Arah' },
  { value: 1, label: 'Tab In' },
  { value: 2, label: 'Tab Out' },
]

const RESULTS = [
  { value: '', label: 'Semua Hasil' },
  { value: '1', label: 'Diterima' },
  { value: '0', label: 'Ditolak' },
]

const now = new Date()
const pad = (n) => String(n).length === 1 ? `0${n}` : String(n)

const filters = ref({
  period: 'bulanan',
  day: `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`,
  month: `${now.getFullYear()}-${pad(now.getMonth() + 1)}`,
  year: String(now.getFullYear()),
  no_plat: '',
  direction: '',
  access_granted: '',
  gate: '',
})

const loading = ref(false)
const downloading = ref('') 
const error = ref('')

const gateControlData = ref(null)
const gateControlError = ref('')

const yearOptions = computed(() => {
  const current = now.getFullYear()
  return Array.from({ length: 6 }, (_, i) => String(current - i))
})

function resolveDate() {
  if (filters.value.period === 'harian') return filters.value.day
  if (filters.value.period === 'tahunan') return filters.value.year
  return filters.value.month
}

function buildParams() {
  return {
    period: filters.value.period,
    date: resolveDate(),
    direction: filters.value.direction || undefined,
    access_granted: filters.value.access_granted !== '' ? filters.value.access_granted : undefined,
    gate: filters.value.gate || undefined,
    no_plat: filters.value.no_plat || undefined,
  }
}

const gateColumns = [
  { key: 'no', label: 'No', align: 'center', width: '56px' },
  { key: 'event_ts', label: 'Waktu' },
  { key: 'gate_id', label: 'Gate' },
  { key: 'action_label', label: 'Aksi' },
  { key: 'nomor_plat', label: 'No. Plat' },
  { key: 'user_name', label: 'Operator' },
  { key: 'result', label: 'Hasil' },
  { key: 'detail_action', label: 'Detail', align: 'center', width: '72px' },
]

const uploadApiUrl = (import.meta.env.VITE_UPLOADS_API_URL || import.meta.env.VITE_UPLOADS_BASE_URL || 'http://192.168.214.7:4000/api/uploads').replace(/\/+$/, '')

async function fetchImageSource(path) {
  const response = await fetch(uploadApiUrl, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json, image/*, */*',
    },
    body: JSON.stringify({ path }),
  })

  if (!response.ok) {
    throw new Error(`Upload API ${response.status}`)
  }

  const contentType = String(response.headers.get('content-type') || '')
  if (contentType.includes('application/json')) {
    const payload = await response.json()
    const src = payload?.url || payload?.image || payload?.data?.url || payload?.data?.image
    if (!src) throw new Error('Image URL not found in upload API response')
    return src
  }

  const blob = await response.blob()
  return URL.createObjectURL(blob)
}

const gateControlPage = ref(1)
const gateControlPerPage = ref(10)

const gateRows = computed(() => gateControlData.value?.rows || [])
const gateTotal = computed(() => gateRows.value.length)
const gateLastPage = computed(() =>
  Math.max(1, Math.ceil(gateTotal.value / gateControlPerPage.value)),
)
const pagedGateRows = computed(() => {
  const start = (gateControlPage.value - 1) * gateControlPerPage.value
  return gateRows.value.slice(start, start + gateControlPerPage.value).map((row) => ({
    ...row,
    action_label: row.action === 'OPEN' ? 'Buka' : row.action === 'CLOSE' ? 'Tutup' : row.action,
  }))
})

function onGateChangePage(page) {
  gateControlPage.value = page
}

function onGateChangePerPage(perPage) {
  gateControlPerPage.value = perPage
  gateControlPage.value = 1
}

const gateControlSummary = computed(() => gateControlData.value?.summary || null)

const selectedGateRow = ref(null)
const showGateDetailModal = ref(false)
const gateDetailImages = ref([])
const gateDetailLoadingImages = ref(false)
const gateDetailImageError = ref('')

function cleanupGateDetailImages() {
  gateDetailImages.value.forEach((img) => {
    if (String(img.src || '').startsWith('blob:')) URL.revokeObjectURL(img.src)
  })
  gateDetailImages.value = []
}

async function openGateDetail(row) {
  selectedGateRow.value = row
  cleanupGateDetailImages()
  gateDetailImageError.value = ''
  showGateDetailModal.value = true

  const paths = Array.isArray(row.image_paths) ? row.image_paths : []
  if (paths.length === 0) return

  gateDetailLoadingImages.value = true
  const labels = ['CCTV', 'Gambar 2', 'Gambar 3', 'Gambar 4']
  const resolved = await Promise.all(
    paths.map(async (path, idx) => {
      try {
        const src = await fetchImageSource(path)
        return { key: `img-${idx}`, label: labels[idx] || `Gambar ${idx + 1}`, src }
      } catch {
        return null
      }
    }),
  )
  gateDetailLoadingImages.value = false
  gateDetailImages.value = resolved.filter(Boolean)
  if (!gateDetailImages.value.length) {
    gateDetailImageError.value = 'Gagal memuat gambar CCTV.'
  }
}

async function loadGateControl() {
  loading.value = true
  gateControlError.value = ''
  try {
    const params = buildParams()
    const res = await reportApi.gateControl(params)
    gateControlData.value = res.data
    gateControlPage.value = 1
  } catch (err) {
    gateControlError.value = extractErrorMessage(err, 'Gagal memuat data kontrol gate.')
  } finally {
    loading.value = false
  }
}

const summaryCards = computed(() => {
  if (gateControlSummary.value) {
    const s = gateControlSummary.value
    return [
      { label: 'Total Event', value: s.total, color: '#4f46e5' },
      { label: 'Buka Gate', value: s.open, color: '#16a34a' },
    ]
  }
  return []
})

async function generate() {
  loadGateControl()
}

async function download(kind, { format = 'pdf', preview = false } = {}) {
  downloading.value = preview ? `${kind}-preview` : `${kind}-${format}`
  try {
    const stamp = new Date().toISOString().slice(0, 10)
    const params = { ...buildParams(), download: preview ? undefined : 1 }
    
    if (format === 'excel') {
      const blob = await reportApi.gateControlExcel(buildParams())
      downloadBlob(blob, `laporan-kontrol-gate-${filters.value.period}-${stamp}.xlsx`)
      toast.success('Excel berhasil diunduh.')
    } else if (preview) {
      const blob = await reportApi.gateControlPdf(params)
      openBlob(blob)
    } else {
      const blob = await reportApi.gateControlPdf(params)
      downloadBlob(blob, `laporan-kontrol-gate-${filters.value.period}-${stamp}.pdf`)
      toast.success('PDF berhasil diunduh.')
    }
  } catch (err) {
    toast.error(extractErrorMessage(err, 'Gagal membuat berkas.'))
  } finally {
    downloading.value = ''
  }
}

onMounted(generate)

watch(
  () => ({
    period: filters.value.period,
    day: filters.value.day,
    month: filters.value.month,
    year: filters.value.year,
    no_plat: filters.value.no_plat,
    direction: filters.value.direction,
    access_granted: filters.value.access_granted,
    gate: filters.value.gate,
  }),
  () => {
    gateControlData.value = null
    gateControlError.value = ''
    loadGateControl()
  },
)

watch(showGateDetailModal, (open) => {
  if (!open) {
    cleanupGateDetailImages()
    gateDetailImageError.value = ''
  }
})

onBeforeUnmount(() => {
  cleanupGateDetailImages()
})
</script>

<template>
  <div class="page">
    <PageHeader
      title="Laporan Kontrol Gate"
      subtitle="Laporan aktivitas kontrol gate (harian, bulanan, tahunan)"
    />

    <div v-if="error" class="alert alert-danger">{{ error }}</div>

    <template v-if="true">
      <!-- Period + download actions -->
      <div class="card">
        <div class="card-body report-toolbar">
          <div class="report-actions">
            <Button variant="secondary" size="sm" :loading="downloading === 'gate-control-pdf'" @click="download('gate-control', { format: 'pdf' })">
              ⬇ PDF
            </Button>
            <Button variant="secondary" size="sm" :loading="downloading === 'gate-control-excel'" @click="download('gate-control', { format: 'excel' })">
              ⬇ Excel
            </Button>
            <Button variant="ghost" size="sm" :loading="downloading === 'gate-control-preview'" @click="download('gate-control', { preview: true })">
              👁 Pratinjau PDF
            </Button>
          </div>
        </div>
      </div>

      <!-- Summary cards -->
      <div class="grid grid-stats">
        <div v-for="card in summaryCards" :key="card.label" class="stat-card">
          <span class="stat-dot" :style="{ background: card.color }"></span>
          <div>
            <div class="stat-value">{{ formatNumber(card.value) }}</div>
            <div class="stat-label">{{ card.label }}</div>
          </div>
        </div>
      </div>

      <!-- Filter bar -->
      <div class="card">
        <div class="card-body filter-bar">
          <div class="field">
            <label>Periode</label>
            <select v-model="filters.period" class="form-control" @change="generate">
              <option v-for="p in PERIODS" :key="p.value" :value="p.value">{{ p.label }}</option>
            </select>
          </div>

          <div class="field">
            <label>Tanggal</label>
            <input v-if="filters.period === 'harian'" v-model="filters.day" type="date" class="form-control" />
            <input v-else-if="filters.period === 'bulanan'" v-model="filters.month" type="month" class="form-control" />
            <select v-else v-model="filters.year" class="form-control">
              <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
            </select>
          </div>

          <div class="field">
            <label>No. Plat</label>
            <input v-model="filters.no_plat" type="text" class="form-control" placeholder="Semua plat" />
          </div>

          <div class="field">
            <label>Arah</label>
            <select v-model="filters.direction" class="form-control">
              <option v-for="d in DIRECTIONS" :key="d.label" :value="d.value">{{ d.label }}</option>
            </select>
          </div>

          <div class="field">
            <label>Hasil</label>
            <select v-model="filters.access_granted" class="form-control">
              <option v-for="r in RESULTS" :key="r.label" :value="r.value">{{ r.label }}</option>
            </select>
          </div>

          <div class="field">
            <label>Gate</label>
            <input v-model="filters.gate" type="text" class="form-control" placeholder="Semua gate" />
          </div>

          <div class="field field-actions">
            <Button variant="primary" :loading="loading" @click="generate">Tampilkan</Button>
          </div>
        </div>
      </div>

      <Loader v-if="loading" text="Memuat data kontrol gate..." />
      <div v-else-if="gateControlError" class="alert alert-danger">{{ gateControlError }}</div>
      <template v-else>
        <DataTable
          :columns="gateColumns"
          :rows="pagedGateRows"
          :paginated="true"
          :page="gateControlPage"
          :per-page="gateControlPerPage"
          :total="gateTotal"
          :last-page="gateLastPage"
          empty-text="Tidak ada event kontrol gate pada periode ini."
          @change-page="onGateChangePage"
          @change-per-page="onGateChangePerPage"
        >
          <template #cell-action_label="{ row }">
            <span class="badge" :class="row.action === 'OPEN' ? 'badge-success' : 'badge-secondary'">
              {{ row.action_label }}
            </span>
          </template>
          <template #cell-detail_action="{ row }">
            <button class="icon-btn" type="button" title="Lihat detail" @click="openGateDetail(row)">👁</button>
          </template>
        </DataTable>
      </template>
    </template>

    <Modal v-model="showGateDetailModal" title="Detail Kontrol Gate">
      <div v-if="selectedGateRow" class="detail-modal">
        <div class="detail-image">
          <div v-if="gateDetailLoadingImages" class="detail-image-placeholder">
            <span>⏳</span>
            <small>Memuat gambar CCTV...</small>
          </div>
          <div v-else-if="gateDetailImages.length" class="detail-image-grid">
            <a
              v-for="img in gateDetailImages"
              :key="img.key"
              class="detail-image-item"
              :href="img.src"
              target="_blank"
              rel="noopener noreferrer"
            >
              <img :src="img.src" :alt="img.label" loading="lazy" />
              <small>{{ img.label }}</small>
            </a>
          </div>
          <div v-else class="detail-image-placeholder">
            <span>🚗</span>
            <small>{{ gateDetailImageError || 'Gambar tidak tersedia' }}</small>
          </div>
        </div>
        <dl class="detail-grid">
          <div><dt>Waktu</dt><dd>{{ selectedGateRow.event_ts }}</dd></div>
          <div><dt>Gate</dt><dd>{{ selectedGateRow.gate_id }}</dd></div>
          <div>
            <dt>Aksi</dt>
            <dd>
              <span class="badge" :class="selectedGateRow.action === 'OPEN' ? 'badge-success' : 'badge-secondary'">
                {{ selectedGateRow.action_label }}
              </span>
            </dd>
          </div>
          <div><dt>No. Plat</dt><dd>{{ selectedGateRow.nomor_plat }}</dd></div>
          <div><dt>Operator</dt><dd>{{ selectedGateRow.user_name }}</dd></div>
          <div><dt>Hasil</dt><dd>{{ selectedGateRow.result }}</dd></div>
        </dl>
      </div>
    </Modal>
  </div>
</template>

<style scoped>
.filter-bar {
  display: flex;
  flex-wrap: wrap;
  gap: 14px;
  align-items: flex-end;
}
.field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 150px;
  flex: 1;
}
.field label {
  font-size: 12px;
  color: var(--color-text-muted);
  font-weight: 600;
}
.field-actions {
  flex: 0 0 auto;
  min-width: auto;
}
.report-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
}
.report-period {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.report-period strong {
  font-size: 15px;
}
.report-period small {
  color: var(--color-text-muted);
}
.period-badge {
  background: #eef2ff;
  color: #3730a3;
  padding: 2px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
}
.report-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
.grid-stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 12px;
  margin: 4px 0 16px;
}
.stat-card {
  display: flex;
  align-items: center;
  gap: 12px;
  background: #fff;
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: var(--radius-sm, 8px);
  padding: 14px 16px;
}
.stat-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  flex-shrink: 0;
}
.stat-value {
  font-size: 20px;
  font-weight: 700;
  line-height: 1.1;
}
.stat-label {
  font-size: 12px;
  color: var(--color-text-muted);
}
.tabs {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 12px;
}
.tab {
  border: none;
  background: transparent;
  padding: 8px 16px;
  border-radius: var(--radius-sm, 8px);
  font-size: 14px;
  font-weight: 600;
  color: var(--color-text-muted);
  cursor: pointer;
}
.tab.active {
  background: var(--color-primary, #4f46e5);
  color: #fff;
}
.tabs-spacer {
  flex: 1;
}
.grid-2 {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  gap: 16px;
}
.text-right {
  text-align: right;
}
.icon-btn {
  border: 1px solid var(--color-border, #e5e7eb);
  background: #fff;
  border-radius: var(--radius-sm, 8px);
  padding: 4px 8px;
  font-size: 15px;
  line-height: 1;
  cursor: pointer;
}
.icon-btn:hover {
  background: #f3f4f6;
}
.detail-modal {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.detail-image-placeholder {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  height: 160px;
  border: 1px dashed var(--color-border, #cbd5e1);
  border-radius: var(--radius-sm, 8px);
  background: #f8fafc;
  color: var(--color-text-muted);
}
.detail-image-placeholder span {
  font-size: 40px;
}
.detail-image-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 10px;
}
.detail-image-item {
  display: flex;
  flex-direction: column;
  gap: 6px;
  text-decoration: none;
  color: inherit;
}
.detail-image-item img {
  width: 100%;
  height: 120px;
  object-fit: cover;
  border-radius: var(--radius-sm, 8px);
  border: 1px solid var(--color-border, #e5e7eb);
  background: #f8fafc;
}
.detail-image-item small {
  font-size: 11px;
  color: var(--color-text-muted);
}
.detail-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 12px 16px;
  margin: 0;
}
.detail-grid dt {
  font-size: 12px;
  color: var(--color-text-muted);
  font-weight: 600;
  margin-bottom: 2px;
}
.detail-grid dd {
  margin: 0;
  font-size: 14px;
}
</style>
