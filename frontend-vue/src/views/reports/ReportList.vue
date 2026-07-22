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

// Filter state. The date input adapts to the selected period.
const filters = ref({
  period: 'bulanan',
  day: `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`,
  month: `${now.getFullYear()}-${pad(now.getMonth() + 1)}`,
  year: String(now.getFullYear()),
  time_from: '',
  time_to: '',
  no_plat: '',
  direction: '',
  access_granted: '',
  gate: '',
})

const activeTab = ref('rekap') // 'rekap' | 'detail'
const activeDetailType = ref('tap') // 'tap' | 'gate'
const loading = ref(false)
const downloading = ref('') // '' | '<kind>-<format>' | '<kind>-preview'
const error = ref('')
const recap = ref(null)
const detail = ref(null)

// Gate control report (loaded lazily when the user opens the "Kontrol Gate" sub-tab)
const gateControlData = ref(null)
const gateControlLoading = ref(false)
const gateControlError = ref('')

const yearOptions = computed(() => {
  const current = now.getFullYear()
  return Array.from({ length: 6 }, (_, i) => String(current - i))
})

/** The `date` query param, derived from the active period + its input. */
function resolveDate() {
  if (filters.value.period === 'harian') return filters.value.day
  if (filters.value.period === 'tahunan') return filters.value.year
  return filters.value.month
}

/** Query params shared by preview + PDF requests. */
function buildParams() {
  const isDaily = filters.value.period === 'harian'
  return {
    period: filters.value.period,
    date: resolveDate(),
    direction: filters.value.direction || undefined,
    access_granted: filters.value.access_granted !== '' ? filters.value.access_granted : undefined,
    gate: filters.value.gate || undefined,
    no_plat: filters.value.no_plat || undefined,
    time_from: isDaily && filters.value.time_from ? filters.value.time_from : undefined,
    time_to: isDaily && filters.value.time_to ? filters.value.time_to : undefined,
  }
}

const detailColumns = [
  { key: 'no', label: 'No', align: 'center', width: '56px' },
  { key: 'tapped_at_label', label: 'Waktu' },
  { key: 'card_number', label: 'Nomor Kartu', cellClass: 'fw-600' },
  { key: 'no_plat', label: 'No. Plat' },
  { key: 'owner', label: 'Pemilik' },
  { key: 'direction_label', label: 'Arah' },
  { key: 'result_label', label: 'Hasil' },
  { key: 'reason_label', label: 'Alasan' },
  { key: 'gate', label: 'Gate' },
  { key: 'actions', label: 'Aksi', align: 'center', width: '72px' },
]

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

const selectedRow = ref(null)
const showDetailModal = ref(false)
const uploadApiUrl = (import.meta.env.VITE_UPLOADS_API_URL || import.meta.env.VITE_UPLOADS_BASE_URL || 'http://192.168.214.7:4000/api/uploads').replace(/\/+$/, '')
const selectedImages = ref([])
const loadingImages = ref(false)
const imageError = ref('')

const IMAGE_FIELDS = [
  { key: 'entry_image_1', label: 'Entry 1' },
  { key: 'entry_image_2', label: 'Entry 2' },
  { key: 'entry_image_3', label: 'Entry 3' },
  { key: 'entry_image_4', label: 'Entry 4' },
  { key: 'exit_image_1', label: 'Exit 1' },
  { key: 'exit_image_2', label: 'Exit 2' },
  { key: 'exit_image_3', label: 'Exit 3' },
  { key: 'exit_image_4', label: 'Exit 4' },
]

function resolveUploadUrl(imagePath) {
  const raw = String(imagePath || '').trim()
  return raw || ''
}

function cleanupSelectedImages() {
  selectedImages.value.forEach((image) => {
    if (String(image.src || '').startsWith('blob:')) {
      URL.revokeObjectURL(image.src)
    }
  })
  selectedImages.value = []
}

function extractImagePath(row) {
  return IMAGE_FIELDS
    .map((field) => {
      const path = resolveUploadUrl(row?.[field.key])
      return path ? { ...field, path } : null
    })
    .filter(Boolean)
}

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

async function loadDetailImages(row) {
  cleanupSelectedImages()
  imageError.value = ''
  const imagePaths = extractImagePath(row)
  if (!imagePaths.length) return

  loadingImages.value = true
  const resolved = await Promise.all(
    imagePaths.map(async (item) => {
      try {
        const src = await fetchImageSource(item.path)
        return { ...item, src }
      } catch {
        return null
      }
    }),
  )
  loadingImages.value = false

  selectedImages.value = resolved.filter(Boolean)
  if (!selectedImages.value.length) {
    imageError.value = 'Gagal memuat gambar dari server.'
  } else if (selectedImages.value.length < imagePaths.length) {
    imageError.value = 'Sebagian gambar gagal dimuat.'
  }
}

// Client-side pagination for the detail tab. The endpoint returns every row
// at once, so we slice locally to keep rendering fast on large result sets.
const detailPage = ref(1)
const detailPerPage = ref(10)

const detailRows = computed(() => detail.value?.rows || [])
const detailTotal = computed(() => detailRows.value.length)
const detailLastPage = computed(() =>
  Math.max(1, Math.ceil(detailTotal.value / detailPerPage.value)),
)
const pagedDetailRows = computed(() => {
  const start = (detailPage.value - 1) * detailPerPage.value
  return detailRows.value.slice(start, start + detailPerPage.value)
})

function onDetailChangePage(page) {
  detailPage.value = page
}

function onDetailChangePerPage(perPage) {
  detailPerPage.value = perPage
  detailPage.value = 1
}

// Client-side pagination for the gate control sub-tab.
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

// Gate control detail modal
const selectedGateRow = ref(null)
const showGateDetailModal = ref(false)

// Gate detail modal image state (reuses fetchImageSource from the tap detail logic)
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
  if (gateControlData.value !== null) return // already loaded for current filters
  gateControlLoading.value = true
  gateControlError.value = ''
  try {
    const params = buildParams()
    const res = await reportApi.gateControl(params)
    gateControlData.value = res.data
    gateControlPage.value = 1
  } catch (err) {
    gateControlError.value = extractErrorMessage(err, 'Gagal memuat data kontrol gate.')
  } finally {
    gateControlLoading.value = false
  }
}

function switchDetailType(type) {
  activeDetailType.value = type
  if (type === 'gate') loadGateControl()
}

async function openDetail(row) {
  selectedRow.value = row
  showDetailModal.value = true
  await loadDetailImages(row)
}

const timelineHead = computed(() => ({
  harian: 'Jam',
  bulanan: 'Tanggal',
  tahunan: 'Bulan',
}[recap.value?.period] || 'Periode'))

const summary = computed(() =>
  activeTab.value === 'rekap' ? recap.value?.summary : detail.value?.summary,
)

const summaryCards = computed(() => {
  // When viewing the gate control sub-tab, show gate-specific stats
  if (activeTab.value === 'detail' && activeDetailType.value === 'gate' && gateControlSummary.value) {
    const s = gateControlSummary.value
    return [
      { label: 'Total Event', value: s.total, color: '#4f46e5' },
      { label: 'Buka Gate', value: s.open, color: '#16a34a' },
      { label: 'Tutup Gate', value: s.close, color: '#dc2626' },
    ]
  }
  const s = summary.value
  if (!s) return []
  return [
    { label: 'Total Tap', value: s.total, color: '#4f46e5' },
    { label: 'Tap In', value: s.tab_in, color: '#0ea5e9' },
    { label: 'Tap Out', value: s.tab_out, color: '#9333ea' },
    { label: 'Diterima', value: s.granted, color: '#16a34a' },
    { label: 'Ditolak', value: s.denied, color: '#dc2626' },
  ]
})

async function generate() {
  loading.value = true
  error.value = ''
  // Reset gate control cache so it reloads with new filters
  gateControlData.value = null
  gateControlError.value = ''
  try {
    const params = buildParams()
    const [recapRes, detailRes] = await Promise.all([
      reportApi.recap(params),
      reportApi.detail(params),
    ])
    recap.value = recapRes.data
    detail.value = detailRes.data
    detailPage.value = 1
    // If the gate sub-tab is already open, eagerly reload its data too
    if (activeDetailType.value === 'gate') {
      loadGateControl()
    }
  } catch (err) {
    error.value = extractErrorMessage(err, 'Gagal memuat laporan.')
    recap.value = null
    detail.value = null
  } finally {
    loading.value = false
  }
}

async function download(kind, { format = 'pdf', preview = false } = {}) {
  downloading.value = preview ? `${kind}-preview` : `${kind}-${format}`
  try {
    const stamp = new Date().toISOString().slice(0, 10)

    if (kind === 'gate-control') {
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
      return
    }

    if (format === 'excel') {
      const blob = kind === 'rekap'
        ? await reportApi.recapExcel(buildParams())
        : await reportApi.detailExcel(buildParams())
      downloadBlob(blob, `laporan-${kind}-${filters.value.period}-${stamp}.xlsx`)
      toast.success('Excel berhasil diunduh.')
      return
    }

    const params = { ...buildParams(), download: preview ? undefined : 1 }
    const blob = kind === 'rekap'
      ? await reportApi.recapPdf(params)
      : await reportApi.detailPdf(params)

    if (preview) {
      openBlob(blob)
    } else {
      downloadBlob(blob, `laporan-${kind}-${filters.value.period}-${stamp}.pdf`)
      toast.success('PDF berhasil diunduh.')
    }
  } catch (err) {
    toast.error(extractErrorMessage(err, 'Gagal membuat berkas.'))
  } finally {
    downloading.value = ''
  }
}

onMounted(generate)

watch(showDetailModal, (open) => {
  if (!open) {
    cleanupSelectedImages()
    imageError.value = ''
  }
})

watch(showGateDetailModal, (open) => {
  if (!open) {
    cleanupGateDetailImages()
    gateDetailImageError.value = ''
  }
})

onBeforeUnmount(() => {
  cleanupSelectedImages()
  cleanupGateDetailImages()
})
</script>

<template>
  <div class="page">
    <PageHeader
      title="Laporan Transaksi"
      subtitle="Rekap & detail transaksi akses kartu (harian, bulanan, tahunan)"
    />



    <div v-if="error" class="alert alert-danger">{{ error }}</div>

    <Loader v-if="loading && !recap" text="Memuat laporan..." />

    <template v-else-if="recap">
      <!-- Period + download actions -->
      <div class="card">
        <div class="card-body report-toolbar">
          <div class="report-period">
            <span class="period-badge">{{ recap.period_label }}</span>
            <strong>{{ recap.range.label }}</strong>
            <small v-if="recap.filters?.length">{{ recap.filters.join(' · ') }}</small>
          </div>
          <div class="report-actions">
            <Button variant="secondary" size="sm" :loading="downloading === 'rekap-pdf'" @click="download('rekap', { format: 'pdf' })">
              ⬇ Rekap (PDF)
            </Button>
            <Button variant="secondary" size="sm" :loading="downloading === 'detail-pdf'" @click="download('detail', { format: 'pdf' })">
              ⬇ Detail (PDF)
            </Button>
            <Button variant="secondary" size="sm" :loading="downloading === 'rekap-excel'" @click="download('rekap', { format: 'excel' })">
              ⬇ Rekap (Excel)
            </Button>
            <Button variant="secondary" size="sm" :loading="downloading === 'detail-excel'" @click="download('detail', { format: 'excel' })">
              ⬇ Detail (Excel)
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

          <template v-if="filters.period === 'harian'">
            <div class="field">
              <label>Waktu Awal</label>
              <input v-model="filters.time_from" type="time" class="form-control" />
            </div>
            <div class="field">
              <label>Waktu Akhir</label>
              <input v-model="filters.time_to" type="time" class="form-control" />
            </div>
          </template>

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

      <!-- Tabs -->
      <div class="tabs">
        <button :class="['tab', { active: activeTab === 'rekap' }]" @click="activeTab = 'rekap'">Rekap</button>
        <button :class="['tab', { active: activeTab === 'detail' }]" @click="activeTab = 'detail'">Detail</button>
        <span class="tabs-spacer"></span>
        <Button v-if="activeTab !== 'detail' || activeDetailType === 'tap'" variant="ghost" size="sm" @click="download(activeTab, { preview: true })">👁 Pratinjau PDF</Button>
      </div>

      <!-- Rekap tab -->
      <template v-if="activeTab === 'rekap'">
        <div class="card">
          <div class="card-header"><strong>Rekap {{ timelineHead === 'Jam' ? 'per Jam' : timelineHead === 'Bulan' ? 'per Bulan' : 'per Tanggal' }}</strong></div>
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th>{{ timelineHead }}</th>
                  <th class="text-right">Total</th>
                  <th class="text-right">Tap In</th>
                  <th class="text-right">Tap Out</th>
                  <th class="text-right">Diterima</th>
                  <th class="text-right">Ditolak</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(b, i) in recap.timeline" :key="i">
                  <td>{{ b.label }}</td>
                  <td class="text-right">{{ formatNumber(b.total) }}</td>
                  <td class="text-right">{{ formatNumber(b.in) }}</td>
                  <td class="text-right">{{ formatNumber(b.out) }}</td>
                  <td class="text-right">{{ formatNumber(b.granted) }}</td>
                  <td class="text-right">{{ formatNumber(b.denied) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="grid grid-2">
          <div class="card">
            <div class="card-header"><strong>Rekap per Alasan</strong></div>
            <div class="table-wrap">
              <table class="table">
                <thead>
                  <tr><th>Alasan</th><th class="text-right">Total</th><th class="text-right">Diterima</th><th class="text-right">Ditolak</th></tr>
                </thead>
                <tbody>
                  <tr v-for="(r, i) in recap.by_reason" :key="i">
                    <td>{{ r.label }}</td>
                    <td class="text-right">{{ formatNumber(r.total) }}</td>
                    <td class="text-right">{{ formatNumber(r.granted) }}</td>
                    <td class="text-right">{{ formatNumber(r.denied) }}</td>
                  </tr>
                  <tr v-if="!recap.by_reason.length"><td colspan="4" class="empty-state">Tidak ada data.</td></tr>
                </tbody>
              </table>
            </div>
          </div>

          <div class="card">
            <div class="card-header"><strong>Rekap per Gate</strong></div>
            <div class="table-wrap">
              <table class="table">
                <thead>
                  <tr><th>Gate</th><th class="text-right">Total</th><th class="text-right">Tab In</th><th class="text-right">Tab Out</th></tr>
                </thead>
                <tbody>
                  <tr v-for="(g, i) in recap.by_gate" :key="i">
                    <td>{{ g.gate }}</td>
                    <td class="text-right">{{ formatNumber(g.total) }}</td>
                    <td class="text-right">{{ formatNumber(g.in) }}</td>
                    <td class="text-right">{{ formatNumber(g.out) }}</td>
                  </tr>
                  <tr v-if="!recap.by_gate.length"><td colspan="4" class="empty-state">Tidak ada data.</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </template>

      <!-- Detail tab -->
      <template v-else>
        <!-- Detail sub-tabs -->
        <div class="sub-tabs">
          <button :class="['sub-tab', { active: activeDetailType === 'tap' }]" @click="activeDetailType = 'tap'">
            🪪 Tap Kartu
          </button>
          <button :class="['sub-tab', { active: activeDetailType === 'gate' }]" @click="switchDetailType('gate')">
            🚧 Kontrol Gate
          </button>
        </div>

        <!-- Tap Kartu sub-tab -->
        <template v-if="activeDetailType === 'tap'">
          <DataTable
            :columns="detailColumns"
            :rows="pagedDetailRows"
            :paginated="true"
            :page="detailPage"
            :per-page="detailPerPage"
            :total="detailTotal"
            :last-page="detailLastPage"
            empty-text="Tidak ada transaksi pada periode ini."
            @change-page="onDetailChangePage"
            @change-per-page="onDetailChangePerPage"
          >
            <template #cell-result_label="{ row }">
              <span class="badge" :class="row.access_granted ? 'badge-success' : 'badge-danger'">
                {{ row.result_label }}
              </span>
            </template>
            <template #cell-actions="{ row }">
              <button class="icon-btn" type="button" title="Lihat detail" @click="openDetail(row)">👁</button>
            </template>
          </DataTable>
        </template>

        <!-- Kontrol Gate sub-tab -->
        <template v-else>
          <!-- Gate control download toolbar -->
          <div class="gate-toolbar">
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

          <Loader v-if="gateControlLoading" text="Memuat data kontrol gate..." />
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
      </template>
    </template>

    <!-- Detail popup -->
    <Modal v-model="showDetailModal" title="Detail Transaksi">
      <div v-if="selectedRow" class="detail-modal">
        <div class="detail-image">
          <div v-if="loadingImages" class="detail-image-placeholder">
            <span>⏳</span>
            <small>Memuat gambar...</small>
          </div>
          <div v-else-if="selectedImages.length" class="detail-image-grid">
            <a
              v-for="image in selectedImages"
              :key="image.key"
              class="detail-image-item"
              :href="image.src"
              target="_blank"
              rel="noopener noreferrer"
            >
              <img :src="image.src" :alt="image.label" loading="lazy" />
              <small>{{ image.label }}</small>
            </a>
          </div>
          <div v-else class="detail-image-placeholder">
            <span>🚗</span>
            <small>{{ imageError || 'Gambar belum tersedia' }}</small>
          </div>
        </div>
        <dl class="detail-grid">
          <div><dt>Waktu</dt><dd>{{ selectedRow.tapped_at_label }}</dd></div>
          <div><dt>Nomor Kartu</dt><dd>{{ selectedRow.card_number || '-' }}</dd></div>
          <div><dt>No. Plat</dt><dd>{{ selectedRow.no_plat || '-' }}</dd></div>
          <div><dt>Pemilik</dt><dd>{{ selectedRow.owner || '-' }}</dd></div>
          <div><dt>Arah</dt><dd>{{ selectedRow.direction_label }}</dd></div>
          <div>
            <dt>Hasil</dt>
            <dd>
              <span class="badge" :class="selectedRow.access_granted ? 'badge-success' : 'badge-danger'">
                {{ selectedRow.result_label }}
              </span>
            </dd>
          </div>
          <div><dt>Alasan</dt><dd>{{ selectedRow.reason_label || '-' }}</dd></div>
          <div><dt>Gate</dt><dd>{{ selectedRow.gate || '-' }}</dd></div>
        </dl>
      </div>
    </Modal>

    <!-- Gate control detail popup -->
    <Modal v-model="showGateDetailModal" title="Detail Kontrol Gate">
      <div v-if="selectedGateRow" class="detail-modal">
        <!-- CCTV image section (only for manual rows with view_image_path) -->
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
/* Sub-tabs (inside the Detail tab) */
.sub-tabs {
  display: flex;
  gap: 4px;
  margin-bottom: 12px;
  border-bottom: 2px solid var(--color-border, #e5e7eb);
  padding-bottom: 4px;
}
.sub-tab {
  border: none;
  background: transparent;
  padding: 6px 14px;
  border-radius: var(--radius-sm, 8px) var(--radius-sm, 8px) 0 0;
  font-size: 13px;
  font-weight: 600;
  color: var(--color-text-muted);
  cursor: pointer;
  transition: background 0.15s, color 0.15s;
}
.sub-tab:hover {
  background: #f3f4f6;
  color: var(--color-text, #111827);
}
.sub-tab.active {
  background: var(--color-primary, #4f46e5);
  color: #fff;
}
/* Gate control download toolbar */
.gate-toolbar {
  display: flex;
  gap: 8px;
  margin-bottom: 12px;
  flex-wrap: wrap;
}
</style>
