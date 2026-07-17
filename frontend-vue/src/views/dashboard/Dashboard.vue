<script setup>
import { onMounted, onUnmounted, ref, computed, watch } from 'vue'
import { RouterLink } from 'vue-router'
import dashboardApi from '@/api/dashboard'
import rfidApi from '@/api/rfid'
import cameraApi from '@/api/camera'
import { gateApi } from '@/api/gate'
import transactionApi from '@/api/transaction'
import { useKartuStore } from '@/stores/kartu'
import { useAuthStore } from '@/stores/auth'
import { useMqtt } from '@/composables/useMqtt'
import { useGateControl } from '@/composables/useGateControl'
import { useToast } from '@/composables/useToast'
import { KENDARAAN_STATUS, extractErrorMessage, kartuReasonMeta, USER_TYPE_VARIANT } from '@/utils/helper'
import { formatNumber, formatDateTime, capitalize } from '@/utils/formatter'
import PageHeader from '@/components/layout/Header.vue'
import Loader from '@/components/common/Loader.vue'
import LiveStream from '@/components/common/LiveStream.vue'
import Modal from '@/components/common/Modal.vue'
import Button from '@/components/common/Button.vue'
import ActivityTrendsChart from '@/components/dashboard/ActivityTrendsChart.vue'

const loading = ref(true)
const error = ref('')
const stats = ref(null)

// MQTT untuk RFID Status
const RFID_STATUS_TOPIC = 'gate/in/rfid_status'
const rfidStatusData = ref({
  card_number: null,
  rfid_tag: null,
  status: null,
  access_granted: null,
  reason: null,
  timestamp: null,
})
const mqttConnected = ref(false)
const mqttError = ref(null)
const mqttDetailModal = ref(false)

// Setup MQTT connection
const { isConnected, error: mqttErr, connect: mqttConnect, subscribe: mqttSubscribe } = useMqtt(null, { autoConnect: false })

// Setup Gate Control
const { publishGateAction, isPublishing: gatePublishing, publishError: gatePublishError, isConnecting: gateConnecting } = useGateControl()

// Setup Toast Notifications
const { success: toastSuccess, error: toastError } = useToast()

// Handle MQTT RFID status messages
function handleRfidStatus(message) {
  // Update RFID Status card display
  rfidStatusData.value = {
    gate_id: message.gate_id || message.gate || null,
    device_type: message.device_type || message.type || 'RFID',
    status: message.status || null,
    message: message.message || message.reason || null,
    timestamp: message.timestamp || new Date().toISOString(),
  }
  // Note: Badge status di-update oleh loadRfidConnStatus() polling
  // yang fetch dari database log_rfid_conn
}

async function initMqtt() {
  try {
    await mqttConnect()
    mqttConnected.value = isConnected.value
    if (mqttConnected.value) {
      // Subscribe dengan QoS 1 (wajib)
      await mqttSubscribe(RFID_STATUS_TOPIC, handleRfidStatus, { qos: 1 })
    }
  } catch (err) {
    mqttError.value = err
    console.error('Failed to init MQTT:', err)
  }
}

// The four CCTV feeds shown in the 2x2 grid. Names + HLS URLs are loaded from
// the backend (GET /api/cameras/feeds) so they follow the "Pengaturan Kamera"
// form. Falls back to the .env / local MediaMTX defaults so it still works if
// the API is unavailable.
const defaultCameras = [
  { id: 1, name: 'Kamera 1', enabled: true, src: import.meta.env.VITE_STREAM_URL_1 || 'http://localhost:1984/api/ws?src=cam1', gate_id: 'GATE_IN_01' },
  { id: 2, name: 'Kamera 2', enabled: true, src: import.meta.env.VITE_STREAM_URL_2 || 'http://localhost:1984/api/ws?src=cam2', gate_id: 'GATE_IN_02' },
  { id: 3, name: 'Kamera 3', enabled: true, src: import.meta.env.VITE_STREAM_URL_3 || 'http://localhost:1984/api/ws?src=cam3', gate_id: 'GATE_OUT_01' },
  { id: 4, name: 'Kamera 4', enabled: true, src: import.meta.env.VITE_STREAM_URL_4 || 'http://localhost:1984/api/ws?src=cam4', gate_id: 'GATE_OUT_02' },
]

const cameras = ref(defaultCameras)

async function loadCameras() {
  try {
    const res = await cameraApi.getFeeds()
    const feeds = res.data?.cameras || []
    if (feeds.length) {
      cameras.value = feeds.map((c, i) => {
        const gateIds = ['GATE_IN_01', 'GATE_IN_02', 'GATE_OUT_01', 'GATE_OUT_02']
        return {
          id: i + 1,
          name: c.name,
          src: c.stream_url,
          enabled: c.enabled,
          gate_id: gateIds[i] || `GATE_${i + 1}`,
        }
      })
    }
  } catch {
    // Keep the .env fallbacks if the feed endpoint is unavailable.
  }
}

// --- Live Kendaraan In/Out (real gate tap history) --------------------------
// Reuses the "Riwayat Tap Terbaru" feed from the gate console
// (GET /api/kartu-logs, via the kartu store) so the dashboard mirrors real tap
// activity. The feed is polled periodically to stay live.
const store = useKartuStore()
const auth = useAuthStore()

const ACTIVITY_LIMIT = 15
const ACTIVITY_POLL_MS = 5000

const vehicleActivityAll = ref([])
const activityLoading = ref(true)
const activityError = ref('')
const activityPage = ref(1)
const activityPerPage = 5

// Filter tanggal untuk feed aktivitas. '' = tampilkan semua log yang termuat
// (tidak difilter). Format mengikuti <input type="date"> yaitu YYYY-MM-DD.
const selectedDate = ref('')
const todayDateStr = computed(() => toLocalDateStr(new Date()))

function toLocalDateStr(date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

function clearDateFilter() {
  selectedDate.value = ''
}

watch(selectedDate, () => {
  activityPage.value = 1
})

// Map a raw access log (same shape KartuGate consumes) into a feed item.
function mapLog(log) {
  // Kartu status: 1 = Aktif, 2 = Non Aktif, 3 = Blacklist (App\Models\Kartu).
  const kartuStatus = log.kartu?.status != null ? Number(log.kartu.status) : null
  const active = kartuStatus === 1 && !log.kartu?.is_blacklisted
  return {
    id: log.id,
    type: Number(log.direction) === 1 ? 'in' : 'out',
    plate: log.card_number,
    name: log.kartu?.user?.name || log.user?.name || 'Tidak dikenal',
    userType: log.kartu?.user?.type || log.user?.type || null,
    gate: log.gate || '',
    granted: log.access_granted,
    reason: log.reason,
    active,
    statusLabel: log.kartu?.status_label || (active ? 'Aktif' : 'Non Aktif'),
    time: log.tapped_at ? formatDateTime(log.tapped_at) : '',
    rawTappedAt: log.tapped_at || null,
  }
}

async function loadActivity() {
  try {
    const res = await store.fetchRecentLogs({ per_page: 200 })
    const logs = res.data || []
    vehicleActivityAll.value = logs.map(mapLog)
    activityError.value = ''
  } catch (err) {
    activityError.value = extractErrorMessage(err, 'Gagal memuat aktivitas kendaraan.')
  } finally {
    activityLoading.value = false
  }
}

// Log yang cocok dengan tanggal terpilih (kalau ada filter aktif).
const filteredActivity = computed(() => {
  if (!selectedDate.value) return vehicleActivityAll.value
  return vehicleActivityAll.value.filter(
      (item) => item.rawTappedAt && toLocalDateStr(new Date(item.rawTappedAt)) === selectedDate.value,
  )
})

// Filter aktivitas untuk hari ini saja (untuk counter Kendaraan Di Dalam)
const todayActivity = computed(() => {
  const today = todayDateStr.value
  return vehicleActivityAll.value.filter(
      (item) => item.rawTappedAt && toLocalDateStr(new Date(item.rawTappedAt)) === today,
  )
})

// Counter kendaraan masuk dan keluar HARI INI (bukan total dari 200 log)
const vehicleInCount = computed(
    () => todayActivity.value.filter((item) => item.type === 'in' && item.granted).length,
)
const vehicleOutCount = computed(
    () => todayActivity.value.filter((item) => item.type === 'out' && item.granted).length,
)

const activityPagination = computed(() => ({
  total: filteredActivity.value.length,
  pages: Math.max(Math.ceil(filteredActivity.value.length / activityPerPage), 1),
}))

const vehicleActivity = computed(() => {
  const start = (activityPage.value - 1) * activityPerPage
  return filteredActivity.value.slice(start, start + activityPerPage)
})

function updateActivityPage(page) {
  activityPage.value = page
}

let activityTimer
function startActivityFeed() {
  loadActivity()
  activityTimer = setInterval(loadActivity, ACTIVITY_POLL_MS)
}

// --- RFID gate reader connection status -------------------------------------
// Latest heartbeat (log_rfid_conn) per gate, polled so the dashboard reflects
// whether each gate's RFID reader is currently connected.
const rfidGates = ref([])
const rfidSummary = ref({ total: 0, online: 0, offline: 0 })
const rfidError = ref('')

async function loadRfidStatus() {
  try {
    const res = await rfidApi.connStatus()
    const payload = res.data || {}
    rfidGates.value = payload.gates || []
    rfidSummary.value = payload.summary || { total: 0, online: 0, offline: 0 }
    rfidError.value = ''
  } catch (err) {
    rfidError.value = extractErrorMessage(err, 'Gagal memuat status RFID.')
  }
}

let rfidTimer
function startRfidStatus() {
  loadRfidStatus()
  rfidTimer = setInterval(loadRfidStatus, ACTIVITY_POLL_MS)
}

// Overall reader connectivity: online when every known gate is connected.
const rfidAllOnline = computed(
    () => rfidSummary.value.total > 0 && rfidSummary.value.offline === 0,
)

// Aktivitas hari ini = jumlah tap (masuk+keluar, granted maupun ditolak) yang
// tapped_at-nya jatuh pada tanggal lokal hari ini. Catatan: karena sumbernya
// dibatasi 200 log terbaru (lihat loadActivity), jika dalam satu hari terjadi
// lebih dari 200 tap, angka ini bisa kurang dari jumlah sebenarnya — bukan
// gara-gara belum lewat tengah malam, tapi karena log lama terdorong keluar
// dari jendela 200 tersebut.
const todayActivityCount = computed(() => todayActivity.value.length)

// --- RFID Gate Detail Modal -------------------------------------------------
const rfidDetailModal = ref(false)
const rfidDetailGate = ref(null)
const rfidDetailLogs = ref([])
const rfidDetailLoading = ref(false)
const rfidDetailError = ref('')
const rfidDetailMeta = ref(null)

async function openRfidDetail(gate) {
  rfidDetailGate.value = gate
  rfidDetailModal.value = true
  rfidDetailLoading.value = true
  rfidDetailError.value = ''
  rfidDetailLogs.value = []

  try {
    const res = await rfidApi.connHistory(gate.gate_id, { per_page: 50 })
    rfidDetailLogs.value = res.data || []
    rfidDetailMeta.value = res.meta || null
  } catch (err) {
    rfidDetailError.value = extractErrorMessage(err, 'Gagal memuat riwayat koneksi.')
  } finally {
    rfidDetailLoading.value = false
  }
}

function closeRfidDetail() {
  rfidDetailModal.value = false
  rfidDetailGate.value = null
  rfidDetailLogs.value = []
}

// --- Gate control (Buka / Tutup Gate) ---------------------------------------
// Kontrol manual per kamera/gate. Untuk sekarang FRONTEND ONLY: form ditampilkan
// dan divalidasi di sisi klien, integrasi ke backend menyusul.
const gateModal = ref(false)
const gateAction = ref('open') // 'open' | 'close'
const gateCamera = ref(null)
const gateSubmitting = ref(false)

const emptyGateForm = () => ({
  nomor_plat: '',
})
const gateForm = ref(emptyGateForm())
const gateErrors = ref({})
const gateSearching = ref(false)
const gateTransactionData = ref(null)
const gateImages = ref([])
const gateImageLoading = ref(false)

const gateActionLabel = computed(() => (gateAction.value === 'open' ? 'Buka Gate' : 'Tutup Gate'))

// Judul modal mengikuti step yang sedang berjalan:
// - Belum ada data transaksi tervalidasi -> masih tahap pencarian plat.
// - Sudah ada data transaksi -> siap konfirmasi buka gate.
const gateModalTitle = computed(() => {
  const camPart = gateCamera.value ? ' · ' + gateCamera.value.name : ''
  return gateTransactionData.value
      ? `Konfirmasi Buka Gate${camPart}`
      : `Cari Kendaraan${camPart}`
})

function isGateReaderOnline(cam) {
  // Find the gate associated with this camera and check if reader is online
  const gateId = cam.gate_id || cam.id // Fallback ke cam.id jika gate_id tidak ada
  const gate = rfidGates.value.find(g => g.gate_id === gateId || g.id === gateId)
  return gate ? gate.is_online : true // Default true jika gate tidak ditemukan
}

function openGateModal(cam) {
  if (!auth.canManage('kartu_gate')) return
  if (!isGateReaderOnline(cam)) {
    alert('Reader gate offline. Kontrol gate tidak tersedia.')
    return
  }
  gateCamera.value = cam
  gateAction.value = 'open'
  gateForm.value = emptyGateForm()
  gateErrors.value = {}
  gateTransactionData.value = null
  gateImages.value = []
  gateModal.value = true
}

function validateGateForm() {
  const errors = {}
  if (!gateForm.value.nomor_plat.trim()) errors.nomor_plat = 'Nomor plat wajib diisi.'
  gateErrors.value = errors
  return Object.keys(errors).length === 0
}

async function searchPlateNumber() {
  if (!validateGateForm()) return

  gateSearching.value = true
  gateErrors.value = {}
  gateTransactionData.value = null
  gateImages.value = []

  try {
    // Validate plate number and get active transaction
    console.log('🔍 Searching for plate:', gateForm.value.nomor_plat.trim())
    const response = await transactionApi.getActiveTransaction(gateForm.value.nomor_plat.trim())

    if (!response.data) {
      toastError('Nomor plat tidak valid atau tidak memiliki transaksi aktif')
      return
    }

    console.log('✅ Transaction found:', response.data)
    gateTransactionData.value = response.data

    // Collect image paths from entry_image_1 to entry_image_4 (with underscore)
    // Also check for view_image_path from log_cctv (preferred as it works with API)
    const imagePaths = []

    // Priority 1: Use view_image_path from log_cctv if available (this path works!)
    if (response.data.view_image_path) {
      console.log('✨ Using view_image_path from log_cctv:', response.data.view_image_path)
      imagePaths.push(response.data.view_image_path)
    }

    // Priority 2: Use entry_image fields from transactions table
    const entryImages = [
      response.data.entry_image_1 || response.data.entry_image1,
      response.data.entry_image_2 || response.data.entry_image2,
      response.data.entry_image_3 || response.data.entry_image3,
      response.data.entry_image_4 || response.data.entry_image4,
    ].filter(path => path != null && path !== '')

    if (entryImages.length > 0) {
      console.log('📷 Found entry_image paths:', entryImages.length)
      imagePaths.push(...entryImages)
    }

    console.log('📷 Total image paths to fetch:', imagePaths.length, imagePaths)

    if (imagePaths.length > 0) {
      gateImageLoading.value = true

      // Fetch all images in parallel
      const imagePromises = imagePaths.map(async (path, idx) => {
        try {
          console.log(`🌐 [${idx + 1}/${imagePaths.length}] Fetching image:`, path)
          const imageData = await transactionApi.fetchImage(path)
          console.log(`✅ [${idx + 1}/${imagePaths.length}] Image data received:`, {
            success: imageData.success,
            hasUrl: !!imageData.url,
            hasBase64: !!imageData.base64,
            base64Length: imageData.base64 ? imageData.base64.length : 0,
            urlPreview: imageData.url ? imageData.url.substring(0, 50) : null
          })
          return imageData
        } catch (err) {
          console.error(`❌ [${idx + 1}/${imagePaths.length}] Failed to fetch:`, path, err)
          return { success: false, path, error: err.message }
        }
      })

      const images = await Promise.all(imagePromises)
      console.log('📦 All images fetched:', images)

      // Store all images including failed ones for display
      gateImages.value = images
      gateImageLoading.value = false

      const successCount = images.filter(img => img.success).length
      console.log(`✅ Success count: ${successCount}/${images.length}`)

      if (successCount > 0) {
        toastSuccess(`Data transaksi ditemukan dengan ${successCount} gambar`)
      } else {
        toastSuccess('Data transaksi ditemukan')
        console.warn('⚠️ No images loaded successfully')
      }
    } else {
      console.log('ℹ️ No image paths in transaction')
      toastSuccess('Data transaksi ditemukan (tanpa gambar)')
    }

  } catch (err) {
    console.error('❌ Search error:', err)
    const errorMsg = extractErrorMessage(err, 'Nomor plat tidak valid')
    gateErrors.value.nomor_plat = errorMsg
    toastError(errorMsg)
  } finally {
    gateSearching.value = false
  }
}

function submitGateAction() {
  if (!gateTransactionData.value) {
    toastError('Silakan cari dan validasi nomor plat terlebih dahulu')
    return
  }

  if (!gateCamera.value) {
    alert('Camera tidak ditemukan')
    return
  }

  gateSubmitting.value = true

  // Publish gate action ke MQTT dan log dengan nomor plat
  publishGateAction(gateCamera.value.gate_id, gateAction.value === 'open', {
    nomor_plat: gateForm.value.nomor_plat,
  })
      .then(async (success) => {
        if (success) {
          // Update transaction status to COMPLETED
          try {
            await transactionApi.completeTransaction(gateTransactionData.value.id)
            gateModal.value = false
            if (gateAction.value === 'open') {
              toastSuccess('Gate berhasil dibuka dan transaksi diselesaikan')
            } else {
              toastSuccess('Gate berhasil ditutup dan transaksi diselesaikan')
            }
          } catch (err) {
            toastError(`Gate dibuka tapi gagal update transaksi: ${extractErrorMessage(err)}`)
          }
        } else {
          toastError(`Gagal mengirim perintah: ${gatePublishError.value}`)
        }
      })
      .catch((err) => {
        toastError(`Error: ${err.message}`)
      })
      .finally(() => {
        gateSubmitting.value = false
      })
}

function handleImageError(event) {
  console.error('Image failed to load:', event.target.src)
  // Hide broken image
  event.target.style.display = 'none'
}

// --- Detail / Riwayat Gate Log ----------------------------------
const detailModal = ref(false)
const detailCamera = ref(null)
const detailGateLogs = ref([])
const detailGateLoading = ref(false)
const detailGateError = ref('')
const detailGatePage = ref(1)
const detailGatePerPage = 10
const detailGatePagination = ref({
  current_page: 1,
  per_page: 10,
  total: 0,
  last_page: 1,
  has_more: false,
})

async function loadDetailGateLogs(page = 1) {
  detailGateLoading.value = true
  detailGateError.value = ''

  try {
    const res = await gateApi.getLogsByGateId(detailCamera.value.gate_id, {
      page,
      per_page: detailGatePerPage,
    })
    detailGateLogs.value = res.data?.logs || []
    detailGatePagination.value = res.data?.pagination || {}
    detailGatePage.value = page
  } catch (err) {
    detailGateError.value = extractErrorMessage(err, 'Gagal memuat riwayat gate.')
  } finally {
    detailGateLoading.value = false
  }
}

async function openDetailModal(cam) {
  detailCamera.value = cam
  detailGateLogs.value = []
  detailGatePage.value = 1
  detailModal.value = true
  await loadDetailGateLogs(1)
}

const statusBreakdown = computed(() => {
  const byStatus = stats.value?.kendaraan_by_status || {}
  return Object.entries(KENDARAAN_STATUS).map(([value, meta]) => ({
    label: meta.label,
    variant: meta.variant,
    total: byStatus[value] || 0,
  }))
})

const cards = computed(() => [
  {
    label: 'Total Pengguna',
    value: stats.value?.total_users ?? 0,
    to: auth.hasFeature('users') ? { name: 'users.index' } : null,
    color: '#4f46e5',
    icon: 'M17 20h5v-2a4 4 0 0 0-3-3.87M9 20H4v-2a4 4 0 0 1 3-3.87m6-1.13a4 4 0 1 0-4-4 4 4 0 0 0 4 4z',
  },
  {
    label: 'Total Kartu Akses',
    value: stats.value?.total_kartu ?? 0,
    to: auth.hasFeature('kartu') ? { name: 'kartu.index' } : null,
    color: '#9333ea',
    icon: 'M3 5h18a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zm-1 5h20M6 15h4',
  },
  {
    label: 'Kendaraan Di Dalam',
    value: Math.max(vehicleInCount.value - vehicleOutCount.value, 0),
    to: null,
    color: '#f59e0b',
    icon: 'M5 13l1.4-4.2A2 2 0 0 1 8.3 7.4h7.4a2 2 0 0 1 1.9 1.4L19 13M5 13a2 2 0 0 0-2 2v3.5a1 1 0 0 0 1 1h1.2M5 13h14M19 13a2 2 0 0 1 2 2v3.5a1 1 0 0 1-1 1h-1.2M7.5 19.5a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3zM16.5 19.5a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3z',
  },
  {
    label: 'Aktivitas Hari Ini',
    value: todayActivityCount.value,
    to: null,
    color: '#0891b2',
    icon: 'M3 12h4l3 8 4-16 3 8h4',
  },
])

async function loadStats() {
  loading.value = true
  error.value = ''
  try {
    const res = await dashboardApi.stats()
    stats.value = res.data
  } catch (err) {
    error.value = extractErrorMessage(err, 'Gagal memuat statistik dashboard.')
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  loadStats()
  loadCameras()
  startActivityFeed()
  startRfidStatus()
  initMqtt()
})

onUnmounted(() => {
  clearInterval(activityTimer)
  clearInterval(rfidTimer)
})
</script>

<template>
  <div class="page">
    <div class="dashboard-head">
      <PageHeader title="Dashboard" subtitle="Ringkasan data sistem GH PIK2" />

      <!-- Akses cepat ke status RFID Reader (MQTT) -->
      <button
          type="button"
          class="mqtt-chip"
          :class="mqttConnected ? 'is-online' : 'is-offline'"
          :title="mqttConnected ? `Terhubung ke ${RFID_STATUS_TOPIC}` : 'MQTT tidak terhubung'"
          @click="mqttDetailModal = true"
      >
        <span class="mqtt-chip-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="2" y="4" width="20" height="16" rx="2" />
            <path d="M7 15h.01M11 15h2" />
          </svg>
        </span>
        <span class="mqtt-chip-text">
          <span class="mqtt-chip-label">RFID Reader</span>
          <span class="mqtt-chip-state">{{ mqttConnected ? 'Online' : 'Offline' }}</span>
        </span>
        <span class="status-dot"></span>
      </button>
    </div>

    <Loader v-if="loading" text="Memuat statistik..." />
    <div v-else-if="error" class="alert alert-danger">{{ error }}</div>

    <template v-else>
      <!-- Stat cards -->
      <div class="grid grid-stats">
        <component
            :is="card.to ? 'RouterLink' : 'div'"
            v-for="card in cards"
            :key="card.label"
            :to="card.to"
            class="stat-card"
        >
          <div class="stat-icon" :style="{ background: card.color }">
            <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path :d="card.icon" />
            </svg>
          </div>
          <div class="stat-meta">
            <span class="stat-value">{{ card.raw ? card.value : formatNumber(card.value) }}</span>
            <span class="stat-label">{{ card.label }}</span>
          </div>
          <svg v-if="card.to" class="stat-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9 18l6-6-6-6" />
          </svg>
        </component>
      </div>

      <!-- Distribusi status kendaraan (ringkas, hanya tampil jika ada datanya) -->
      <div v-if="statusBreakdown.some((s) => s.total > 0)" class="status-strip">
        <span
            v-for="s in statusBreakdown"
            :key="s.label"
            class="status-pill"
            :class="`badge-${s.variant}`"
        >
          <span class="status-pill-value">{{ formatNumber(s.total) }}</span>
          {{ s.label }}
        </span>
      </div>

      <!-- Live CCTV + Kendaraan In/Out -->
      <div class="row live-row">
        <!-- Live CCTV streams -->
        <div class="card live-cctv">
          <div class="card-header card-header-flex">
            <span class="card-header-title">
              <svg class="card-header-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M23 7l-7 5 7 5V7z" /><rect x="1" y="5" width="15" height="14" rx="2" />
              </svg>
              Live CCTV
            </span>
            <span class="stream-count">{{ cameras.length }} kamera</span>
          </div>
          <div class="card-body">
            <div class="camera-grid">
              <div v-for="cam in cameras" :key="cam.id" class="camera-tile">
                <div v-if="cam.enabled === false" class="camera-off">
                  <svg class="camera-off-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M1 1l22 22M21 21H3a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h1m4-1h4l2 2h4a2 2 0 0 1 2 2v9M9.5 9.5a3 3 0 0 0 4 4" />
                  </svg>
                  <span class="camera-off-title">Kamera dinonaktifkan</span>
                  <small class="camera-off-sub">{{ cam.name }} sedang dimatikan</small>
                  <RouterLink
                      v-if="auth.canManage('cameras')"
                      :to="{ name: 'settings.cameras' }"
                      class="camera-off-link"
                  >Aktifkan di Pengaturan Kamera</RouterLink>
                </div>
                <template v-else>
                  <div class="camera-stream-wrap">
                    <LiveStream :src="cam.src" :label="cam.name" />
                    <span class="camera-badge" :class="isGateReaderOnline(cam) ? 'is-online' : 'is-offline'">
                      <span class="status-dot"></span>{{ cam.gate_id }}
                    </span>
                  </div>
                </template>
                <div v-if="cam.id <= 2" class="camera-controls">
                  <Button
                      v-if="auth.canManage('kartu_gate')"
                      size="sm"
                      variant="primary"
                      :disabled="!isGateReaderOnline(cam)"
                      :title="isGateReaderOnline(cam) ? 'Kontrol gate' : 'Reader offline - Kontrol tidak tersedia'"
                      @click="openGateModal(cam)"
                  >
                    <svg class="ctrl-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 21V7l7-4 7 4v14M9 21v-6h6v6" /></svg>
                    Kontrol Gate
                  </Button>
                  <Button size="sm" variant="secondary" @click="openDetailModal(cam)">
                    <svg class="ctrl-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8h.01M11 12h1v4h1M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" /></svg>
                    Detail
                  </Button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Live Kendaraan In/Out -->
        <div class="card live-activity">
          <div class="card-header card-header-flex">
            <span class="card-header-title">
              <svg class="card-header-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 12h4l3 8 4-16 3 8h4" />
              </svg>
              Live Kendaraan In / Out
            </span>
            <span class="live-indicator"><span class="live-pulse"></span>Live</span>
          </div>

          <div class="activity-filter">
            <svg class="activity-filter-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="3" y="4" width="18" height="18" rx="2" /><path d="M16 2v4M8 2v4M3 10h18" />
            </svg>
            <input
                id="activity-date"
                v-model="selectedDate"
                type="date"
                class="activity-filter-input"
                :max="todayDateStr"
                aria-label="Filter berdasarkan tanggal"
            />
            <button v-if="selectedDate" type="button" class="activity-filter-clear" @click="clearDateFilter">
              Reset
            </button>
            <span class="activity-filter-hint">dari 200 log terakhir</span>
          </div>

          <div class="activity-summary">
            <div class="summary-item">
              <span class="summary-icon is-in">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
              </span>
              <span class="summary-value text-in">{{ formatNumber(vehicleInCount) }}</span>
              <span class="summary-label">Masuk</span>
            </div>
            <div class="summary-divider"></div>
            <div class="summary-item">
              <span class="summary-icon is-out">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M11 18l-6-6 6-6" /></svg>
              </span>
              <span class="summary-value text-out">{{ formatNumber(vehicleOutCount) }}</span>
              <span class="summary-label">Keluar</span>
            </div>
          </div>

          <div class="activity-feed">
            <div v-if="activityLoading && !vehicleActivity.length" class="activity-empty">
              <span class="spinner"></span>Memuat aktivitas...
            </div>
            <div v-else-if="activityError && !vehicleActivity.length" class="activity-empty is-error">
              {{ activityError }}
            </div>
            <div v-else-if="!vehicleActivity.length" class="activity-empty">
              {{ selectedDate ? 'Tidak ada aktivitas pada tanggal ini di dalam 200 log terakhir yang dimuat.' : 'Belum ada aktivitas tap.' }}
            </div>
            <TransitionGroup v-else name="activity">
              <div
                  v-for="item in vehicleActivity"
                  :key="item.id"
                  class="activity-item"
                  :class="{ 'is-denied': !item.granted }"
              >
                <span class="activity-icon" :class="item.type === 'in' ? 'is-in' : 'is-out'">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path :d="item.type === 'in' ? 'M5 12h14M13 6l6 6-6 6' : 'M19 12H5M11 18l-6-6 6-6'" />
                  </svg>
                </span>
                <div class="activity-info">
                  <span class="activity-plate">
                    {{ item.plate }}
                    <span
                        v-if="item.userType"
                        class="badge activity-type-badge"
                        :class="`badge-${USER_TYPE_VARIANT[item.userType] || 'muted'}`"
                    >
                      {{ capitalize(item.userType) }}
                    </span>
                  </span>
                  <span class="activity-meta">
                    {{ item.name }}<template v-if="item.gate"> · {{ item.gate }}</template>
                  </span>
                </div>
                <div class="activity-side">
                  <span
                      class="badge"
                      :class="item.granted ? (item.type === 'in' ? 'badge-success' : 'badge-info') : 'badge-danger'"
                  >
                    {{ item.granted ? (item.type === 'in' ? 'Masuk' : 'Keluar') : 'Ditolak' }}
                  </span>
                  <span
                      class="activity-status"
                      :class="item.active ? 'is-active' : 'is-inactive'"
                      :title="item.active ? 'Kartu aktif' : 'Kartu tidak aktif'"
                  >
                    <span class="status-dot"></span>{{ item.statusLabel }}
                  </span>
                  <span v-if="!item.granted" class="activity-reason">{{ kartuReasonMeta(item.reason).label }}</span>
                  <span class="activity-time">{{ item.time }}</span>
                </div>
              </div>
            </TransitionGroup>
          </div>

          <!-- Pagination untuk Activity Feed -->
          <div v-if="!activityLoading && vehicleActivity.length > 0 && activityPagination.pages > 1" class="activity-pagination">
            <span class="pagination-info">
              {{ (activityPage - 1) * activityPerPage + 1 }}–{{ Math.min(activityPage * activityPerPage, activityPagination.total) }} dari {{ activityPagination.total }}
            </span>
            <div class="pagination-buttons">
              <button
                  type="button"
                  class="page-btn"
                  :disabled="activityPage === 1"
                  @click="updateActivityPage(activityPage - 1)"
                  aria-label="Sebelumnya"
              >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6" /></svg>
              </button>
              <span class="page-current">{{ activityPage }} / {{ activityPagination.pages }}</span>
              <button
                  type="button"
                  class="page-btn"
                  :disabled="activityPage >= activityPagination.pages"
                  @click="updateActivityPage(activityPage + 1)"
                  aria-label="Selanjutnya"
              >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6" /></svg>
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Grafik Trend Aktivitas 7 Hari Terakhir -->
      <ActivityTrendsChart :autoRefresh="true" :refreshInterval="30000" />

      <!-- Modal: Buka / Tutup Gate (frontend only) -->
      <Modal v-model="gateModal" :title="gateModalTitle">
        <div class="gate-steps">
          <span class="gate-step" :class="{ 'is-active': !gateTransactionData, 'is-done': gateTransactionData }">
            <span class="gate-step-num">
              <svg v-if="gateTransactionData" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12" /></svg>
              <template v-else>1</template>
            </span>
            Cari Plat
          </span>
          <span class="gate-step-line" :class="{ 'is-done': gateTransactionData }"></span>
          <span class="gate-step" :class="{ 'is-active': gateTransactionData }">
            <span class="gate-step-num">2</span>
            Buka Gate
          </span>
        </div>
        <p class="gate-hint">
          {{ gateTransactionData
            ? 'Data transaksi ditemukan. Periksa detail di bawah, lalu klik "Buka Gate" untuk membuka.'
            : 'Masukkan nomor plat kendaraan dan klik Cari untuk validasi.' }}
        </p>
        <form class="gate-form" @submit.prevent="searchPlateNumber">
          <div class="form-group">
            <label class="form-label">Nomor Plat <span class="req">*</span></label>
            <div style="display: flex; gap: 8px;">
              <input
                  v-model="gateForm.nomor_plat"
                  type="text"
                  class="form-control plate-input"
                  :class="{ 'is-invalid': gateErrors.nomor_plat }"
                  placeholder="B 1234 XYZ"
                  :disabled="gateSearching || gateTransactionData"
                  style="flex: 1;"
              />
              <Button
                  v-if="!gateTransactionData"
                  type="submit"
                  variant="primary"
                  :loading="gateSearching"
                  style="white-space: nowrap;"
              >
                {{ gateSearching ? 'Mencari...' : 'Cari' }}
              </Button>
              <Button
                  v-else
                  type="button"
                  variant="secondary"
                  @click="gateTransactionData = null; gateImages = []; gateForm.nomor_plat = ''"
                  style="white-space: nowrap;"
              >
                Reset
              </Button>
            </div>
            <span v-if="gateErrors.nomor_plat" class="form-error">{{ gateErrors.nomor_plat }}</span>
          </div>
        </form>

        <!-- Transaction Data Display -->
        <div v-if="gateTransactionData" class="gate-transaction-info">
          <div class="transaction-info-header">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 20px; height: 20px; color: #16a34a;">
              <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
              <polyline points="22 4 12 14.01 9 11.01"></polyline>
            </svg>
            <span style="font-weight: 600; color: #16a34a;">Data Transaksi Valid</span>
          </div>
          <div class="transaction-info-grid">
            <div class="info-item">
              <span class="info-label">Kode Transaksi:</span>
              <span class="info-value">{{ gateTransactionData.code_transaction || '-' }}</span>
            </div>
            <div class="info-item">
              <span class="info-label">Waktu Masuk:</span>
              <span class="info-value">{{ gateTransactionData.entry_time ? formatDateTime(gateTransactionData.entry_time) : '-' }}</span>
            </div>
            <div class="info-item">
              <span class="info-label">Lokasi:</span>
              <span class="info-value">{{ gateTransactionData.location || '-' }}</span>
            </div>
            <div class="info-item">
              <span class="info-label">Status:</span>
              <span class="badge badge-warning">{{ gateTransactionData.status }}</span>
            </div>
          </div>

          <!-- Images Display -->
          <div v-if="gateImages.length > 0 || gateImageLoading" class="gate-images">
            <label class="form-label">Gambar Entry</label>
            <div v-if="gateImageLoading" class="gate-images-loading">
              <span class="spinner"></span>
              <span>Memuat gambar...</span>
            </div>
            <div v-else class="images-grid">
              <div v-for="(image, index) in gateImages" :key="index" class="image-item">
                <!-- Display image from URL (with data URI prefix) -->
                <img v-if="image.url"
                     :src="image.url"
                     :alt="`Entry Image ${index + 1}`"
                     @error="handleImageError"
                     @load="() => console.log('Image loaded successfully:', index)"
                     style="width: 100%; height: 100%; object-fit: cover;"
                />
                <!-- Display image from base64 (add data URI prefix) -->
                <img v-else-if="image.base64"
                     :src="`data:image/jpeg;base64,${image.base64}`"
                     :alt="`Entry Image ${index + 1}`"
                     @error="handleImageError"
                     @load="() => console.log('Base64 image loaded:', index)"
                     style="width: 100%; height: 100%; object-fit: cover;"
                />
                <!-- Fallback: Show placeholder with path info -->
                <div v-else class="image-placeholder">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 32px; height: 32px; margin-bottom: 8px; opacity: 0.5;">
                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                    <circle cx="8.5" cy="8.5" r="1.5"></circle>
                    <polyline points="21 15 16 10 5 21"></polyline>
                  </svg>
                  <div style="font-size: 11px; color: var(--color-text-muted);">
                    {{ image.success === false ? 'Gagal memuat' : 'Tidak tersedia' }}
                  </div>
                  <div v-if="image.error" style="font-size: 10px; color: var(--color-danger); margin-top: 4px; padding: 0 8px;">
                    {{ image.error }}
                  </div>
                  <div v-if="image.path" style="font-size: 10px; color: var(--color-text-muted); margin-top: 4px; word-break: break-all; max-width: 100%; padding: 0 8px;">
                    {{ image.path.split('/').pop() }}
                  </div>
                  <!-- Debug info button -->
                  <button
                      v-if="image.success === false"
                      @click="console.log('Image debug:', image)"
                      style="margin-top: 8px; padding: 4px 8px; font-size: 10px; border: 1px solid var(--color-border); border-radius: 4px; background: white; cursor: pointer;"
                  >
                    Debug Info
                  </button>
                </div>
              </div>
            </div>
          </div>
          <div v-else-if="gateTransactionData" class="gate-images-empty">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="width: 24px; height: 24px; opacity: 0.5;">
              <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
              <circle cx="8.5" cy="8.5" r="1.5"></circle>
              <polyline points="21 15 16 10 5 21"></polyline>
            </svg>
            <span>Tidak ada gambar tersedia</span>
            <details style="margin-top: 10px; font-size: 11px; color: var(--color-text-muted); max-width: 100%; text-align: left;">
              <summary style="cursor: pointer; font-weight: 600;">Debug Info</summary>
              <div style="margin-top: 8px; padding: 8px; background: white; border: 1px solid var(--color-border); border-radius: 4px; font-family: monospace;">
                <div>entry_image_1: {{ gateTransactionData.entry_image_1 || gateTransactionData.entry_image1 || 'null' }}</div>
                <div>entry_image_2: {{ gateTransactionData.entry_image_2 || gateTransactionData.entry_image2 || 'null' }}</div>
                <div>entry_image_3: {{ gateTransactionData.entry_image_3 || gateTransactionData.entry_image3 || 'null' }}</div>
                <div>entry_image_4: {{ gateTransactionData.entry_image_4 || gateTransactionData.entry_image4 || 'null' }}</div>
              </div>
            </details>
          </div>
        </div>

        <!--        <div v-if="gateCamera" class="gate-live-cctv">-->
        <!--          <label class="form-label">Live CCTV</label>-->
        <!--          <LiveStream :src="gateCamera.src" />-->
        <!--        </div>-->

        <div v-if="gatePublishError" class="alert alert-danger" style="margin-top: 12px;">
          {{ gatePublishError }}
        </div>

        <template #footer>
          <Button variant="secondary" type="button" @click="gateModal = false" :disabled="gateSubmitting || gateConnecting">Batal</Button>
          <Button
              v-if="gateTransactionData"
              :variant="gateAction === 'open' ? 'primary' : 'danger'"
              type="button"
              :loading="gateSubmitting || gateConnecting"
              @click="submitGateAction"
          >
            <span v-if="gateConnecting">Connecting MQTT...</span>
            <span v-else-if="gateSubmitting">Mengirim...</span>
            <span v-else>{{ gateActionLabel }}</span>
          </Button>
        </template>
      </Modal>

      <!-- Modal: Detail / Riwayat Gate Log -->
      <Modal
          v-model="detailModal"
          :title="`Riwayat Gate${detailCamera ? ' · ' + detailCamera.name : ''}`"
      >
        <div class="detail-history">
          <div v-if="detailGateLoading" class="detail-empty"><span class="spinner"></span>Memuat riwayat...</div>
          <div v-else-if="detailGateError" class="alert alert-danger">{{ detailGateError }}</div>
          <div v-else-if="!detailGateLogs.length" class="detail-empty">Belum ada riwayat gate.</div>
          <table v-else class="detail-table">
            <thead>
            <tr>
              <th>Waktu</th>
              <th>Gate ID</th>
              <th>Nomor Plat</th>
              <th>Aksi</th>
              <th>Hasil</th>
            </tr>
            </thead>
            <tbody>
            <tr v-for="log in detailGateLogs" :key="log.id">
              <td class="detail-time">{{ formatDateTime(log.event_ts) }}</td>
              <td class="detail-gate-id">{{ log.gate_id }}</td>
              <td class="detail-nomor-plat">{{ log.nomor_plat || '-' }}</td>
              <td>
                <span class="badge" :class="log.action === 'OPEN' ? 'badge-success' : 'badge-info'">{{ log.action }}</span>
              </td>
              <td><span class="badge badge-success">{{ log.result }}</span></td>
            </tr>
            </tbody>
          </table>
          <div v-if="!detailGateLoading && detailGateLogs.length > 0" class="activity-pagination detail-pagination">
            <span class="pagination-info">
              {{ (detailGatePage - 1) * detailGatePerPage + 1 }}–{{ Math.min(detailGatePage * detailGatePerPage, detailGatePagination.total) }} dari {{ detailGatePagination.total }} records
            </span>
            <div class="pagination-buttons">
              <button
                  type="button"
                  class="page-btn"
                  :disabled="detailGatePage === 1 || detailGateLoading"
                  @click="loadDetailGateLogs(detailGatePage - 1)"
                  aria-label="Sebelumnya"
              >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6" /></svg>
              </button>
              <span class="page-current">{{ detailGatePage }} / {{ detailGatePagination.last_page }}</span>
              <button
                  type="button"
                  class="page-btn"
                  :disabled="!detailGatePagination.has_more || detailGateLoading"
                  @click="loadDetailGateLogs(detailGatePage + 1)"
                  aria-label="Selanjutnya"
              >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6" /></svg>
              </button>
            </div>
          </div>
        </div>
        <template #footer>
          <Button variant="secondary" type="button" @click="detailModal = false">Tutup</Button>
        </template>
      </Modal>

      <!-- Modal: RFID Gate Detail (tanpa riwayat log) -->
      <Modal
          v-model="rfidDetailModal"
          :title="`Detail RFID Gate${rfidDetailGate ? ' · ' + rfidDetailGate.gate_id : ''}`"
      >
        <div v-if="rfidDetailGate" class="rfid-detail-summary">
          <div class="rfid-detail-item">
            <span class="rfid-detail-label">Gate ID</span>
            <span class="rfid-detail-value">{{ rfidDetailGate.gate_id }}</span>
          </div>
          <div class="rfid-detail-item">
            <span class="rfid-detail-label">Status Saat Ini</span>
            <span class="rfid-detail-value">
              <span class="badge" :class="rfidDetailGate.is_online ? 'badge-success' : 'badge-danger'">
                {{ rfidDetailGate.status_label }}
              </span>
            </span>
          </div>
          <div v-if="rfidDetailGate.detail" class="rfid-detail-item">
            <span class="rfid-detail-label">Detail</span>
            <span class="rfid-detail-value">{{ rfidDetailGate.detail }}</span>
          </div>
          <div v-if="rfidDetailGate.event_ts" class="rfid-detail-item">
            <span class="rfid-detail-label">Terakhir Update</span>
            <span class="rfid-detail-value">{{ formatDateTime(rfidDetailGate.event_ts) }}</span>
          </div>
        </div>
        <template #footer>
          <Button variant="secondary" type="button" @click="closeRfidDetail">Tutup</Button>
        </template>
      </Modal>

      <!-- Modal: Detail Status RFID Reader (MQTT), diakses lewat chip di header -->
      <Modal v-model="mqttDetailModal" title="Status RFID Reader">
        <div v-if="!mqttConnected" class="rfid-empty">
          <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <circle cx="12" cy="12" r="10" />
            <line x1="12" y1="8" x2="12" y2="12" />
            <line x1="12" y1="16" x2="12.01" y2="16" />
          </svg>
          <p>MQTT tidak terhubung</p>
          <small v-if="mqttError">{{ mqttError.message || mqttError }}</small>
        </div>

        <div v-else-if="!rfidStatusData.gate_id && !rfidStatusData.status" class="rfid-empty">
          <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <rect x="2" y="4" width="20" height="16" rx="2" />
            <path d="M7 15h.01M11 15h2" />
          </svg>
          <p>Menunggu data dari topic:</p>
          <code class="topic-name">{{ RFID_STATUS_TOPIC }}</code>
          <small>QoS: 1</small>
        </div>

        <div v-else class="rfid-status-display">
          <div class="rfid-hero" :class="rfidStatusData.status === 'CONNECTED' ? 'is-connected' : 'is-disconnected'">
            <div class="rfid-hero-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="4" width="20" height="16" rx="2" />
                <path d="M7 15h.01M11 15h2" />
              </svg>
            </div>
            <div class="rfid-hero-text">
              <span class="rfid-hero-label">{{ rfidStatusData.status === 'CONNECTED' ? 'Reader Terhubung' : 'Reader Terputus' }}</span>
              <span class="rfid-hero-gate">Gate {{ rfidStatusData.gate_id || '-' }}</span>
            </div>
            <span class="badge rfid-hero-badge" :class="rfidStatusData.status === 'CONNECTED' ? 'badge-success' : 'badge-danger'">
              {{ rfidStatusData.status === 'CONNECTED' ? 'Online' : 'Offline' }}
            </span>
          </div>

          <div class="rfid-field-grid">
            <div class="rfid-field-box">
              <span class="rfid-field-box-label">Device Type</span>
              <span class="rfid-field-box-value code">{{ rfidStatusData.device_type || '-' }}</span>
            </div>
            <div class="rfid-field-box">
              <span class="rfid-field-box-label">Terakhir Update</span>
              <span class="rfid-field-box-value">{{ rfidStatusData.timestamp ? formatDateTime(rfidStatusData.timestamp) : '-' }}</span>
            </div>
            <div v-if="rfidStatusData.message" class="rfid-field-box rfid-field-box-wide">
              <span class="rfid-field-box-label">Message</span>
              <span class="rfid-field-box-value">{{ rfidStatusData.message }}</span>
            </div>
          </div>
        </div>

        <template #footer>
          <Button variant="secondary" type="button" @click="mqttDetailModal = false">Tutup</Button>
        </template>
      </Modal>
    </template>
  </div>
</template>

<style scoped>
/* ---------- Header ---------- */
.dashboard-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 4px;
}

/* ---------- Stat cards ---------- */
.grid-stats {
  margin-top: 20px;
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 18px;
}
@media (max-width: 1100px) {
  .grid-stats {
    grid-template-columns: repeat(2, 1fr);
  }
}
@media (max-width: 560px) {
  .grid-stats {
    grid-template-columns: 1fr;
  }
}

/* ---------- Status breakdown strip ---------- */
.status-strip {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 14px;
}
.status-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12.5px;
  font-weight: 600;
  padding: 6px 14px;
  border-radius: 999px;
}
.status-pill-value {
  font-weight: 700;
}
.stat-card {
  position: relative;
  display: flex;
  align-items: center;
  gap: 20px;
  padding: 24px 26px;
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  box-shadow: var(--shadow-sm);
  transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
}
a.stat-card:hover {
  transform: translateY(-2px);
  box-shadow: var(--shadow);
  border-color: color-mix(in srgb, var(--color-primary, #4f46e5) 30%, var(--color-border));
}
.stat-icon {
  display: grid;
  place-items: center;
  width: 56px;
  height: 56px;
  border-radius: 14px;
  flex-shrink: 0;
  box-shadow: 0 4px 10px -4px rgb(0 0 0 / 0.25);
}
.stat-icon svg {
  width: 28px;
  height: 28px;
}
.stat-meta {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}
.stat-value {
  font-size: 30px;
  font-weight: 700;
  line-height: 1.15;
  letter-spacing: -0.01em;
}
.stat-label {
  font-size: 14px;
  color: var(--color-text-muted);
  font-weight: 500;
}
.stat-arrow {
  width: 18px;
  height: 18px;
  margin-left: auto;
  flex-shrink: 0;
  color: var(--color-text-muted);
  opacity: 0;
  transform: translateX(-4px);
  transition: opacity 0.15s ease, transform 0.15s ease;
}
a.stat-card:hover .stat-arrow {
  opacity: 1;
  transform: translateX(0);
}

/* ---------- Section / card headers ---------- */
.card-header-flex {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.card-header-title {
  display: inline-flex;
  align-items: center;
  gap: 9px;
  font-weight: 600;
}
.card-header-icon {
  width: 17px;
  height: 17px;
  color: var(--color-text-muted);
  flex-shrink: 0;
}
.stream-count {
  font-size: 12px;
  font-weight: 500;
  color: var(--color-text-muted);
  background: var(--color-bg, #f1f5f9);
  border: 1px solid var(--color-border);
  border-radius: 999px;
  padding: 3px 11px;
}

/* ---------- Live CCTV 2x2 grid ---------- */
.camera-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 18px;
}
.camera-tile {
  display: flex;
  flex-direction: column;
  border: 1px solid var(--color-border);
  border-radius: var(--radius, 10px);
  overflow: hidden;
  background: #000;
  box-shadow: var(--shadow-sm);
  transition: box-shadow 0.15s ease, transform 0.15s ease;
}
.camera-tile:hover {
  transform: translateY(-2px);
  box-shadow: var(--shadow);
}
.camera-stream-wrap {
  position: relative;
}
.camera-badge {
  position: absolute;
  top: 10px;
  left: 10px;
  z-index: 2;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.02em;
  color: #fff;
  padding: 4px 10px;
  border-radius: 999px;
  background: rgb(15 23 42 / 0.65);
  backdrop-filter: blur(3px);
}
.camera-badge .status-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--color-text-muted);
  flex-shrink: 0;
}
.camera-badge.is-online .status-dot {
  background: #4ade80;
  box-shadow: 0 0 0 2px rgb(74 222 128 / 0.25);
}
.camera-badge.is-offline .status-dot {
  background: #f87171;
  box-shadow: 0 0 0 2px rgb(248 113 113 / 0.25);
}

/* Disabled-camera placeholder (shown instead of the player) */
.camera-off {
  position: relative;
  aspect-ratio: 16 / 9;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 16px;
  text-align: center;
  background: repeating-linear-gradient(
      45deg,
      #0f172a,
      #0f172a 12px,
      #131c30 12px,
      #131c30 24px
  );
  color: #94a3b8;
}
.camera-off-icon {
  width: 36px;
  height: 36px;
  color: #64748b;
}
.camera-off-title {
  font-size: 14px;
  font-weight: 600;
  color: #e2e8f0;
}
.camera-off-sub {
  font-size: 12px;
  color: #94a3b8;
}
.camera-off-link {
  margin-top: 4px;
  font-size: 12px;
  color: #93c5fd;
  text-decoration: underline;
}
.camera-off-link:hover {
  color: #bfdbfe;
}

/* Per-camera gate controls */
.camera-controls {
  display: flex;
  gap: 8px;
  padding: 10px;
  background: var(--color-surface);
  border-top: 1px solid var(--color-border);
}
.camera-controls .btn {
  flex: 1;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
}
.ctrl-icon {
  width: 15px;
  height: 15px;
  flex-shrink: 0;
}

/* ---------- Gate action form ---------- */
.gate-steps {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 16px;
}
.gate-step {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  font-weight: 600;
  color: var(--color-text-muted);
  transition: color 0.15s ease;
}
.gate-step-num {
  display: grid;
  place-items: center;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  border: 2px solid var(--color-border);
  font-size: 11.5px;
  font-weight: 700;
  color: var(--color-text-muted);
  background: var(--color-surface);
  flex-shrink: 0;
  transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
}
.gate-step-num svg {
  width: 12px;
  height: 12px;
}
.gate-step.is-active {
  color: var(--color-text);
}
.gate-step.is-active .gate-step-num {
  border-color: var(--color-primary, #4f46e5);
  color: var(--color-primary, #4f46e5);
}
.gate-step.is-done {
  color: var(--color-success, #16a34a);
}
.gate-step.is-done .gate-step-num {
  background: var(--color-success, #16a34a);
  border-color: var(--color-success, #16a34a);
  color: #fff;
}
.gate-step-line {
  flex: 1;
  height: 2px;
  background: var(--color-border);
  transition: background 0.15s ease;
}
.gate-step-line.is-done {
  background: var(--color-success, #16a34a);
}
.gate-hint {
  margin: 0 0 16px;
  font-size: 13px;
  color: var(--color-text-muted);
  line-height: 1.5;
}
.gate-form {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.plate-input {
  text-transform: uppercase;
  letter-spacing: 0.04em;
  font-weight: 600;
}
.gate-live-cctv {
  margin-top: 16px;
  padding-top: 16px;
  border-top: 1px solid var(--color-border);
}
.gate-live-cctv .form-label {
  margin-bottom: 10px;
}
.req {
  color: var(--color-danger);
}

/* ---------- Transaction Info Display ---------- */
.gate-transaction-info {
  margin-top: 16px;
  padding: 16px;
  background: var(--color-bg, #f8fafc);
  border: 1px solid var(--color-border);
  border-radius: 10px;
}
.transaction-info-header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
  font-size: 14px;
}
.transaction-info-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 12px;
}
.info-item {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.info-label {
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--color-text-muted);
}
.info-value {
  font-size: 13px;
  font-weight: 500;
  color: var(--color-text);
}

/* ---------- Images Grid ---------- */
.gate-images {
  margin-top: 16px;
}
.images-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 12px;
  margin-top: 10px;
}
.image-item {
  position: relative;
  width: 100%;
  padding-top: 75%; /* 4:3 aspect ratio */
  background: var(--color-bg, #f1f5f9);
  border: 1px solid var(--color-border);
  border-radius: 8px;
  overflow: hidden;
}
.image-item img {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.image-placeholder {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  color: var(--color-text-muted);
  text-align: center;
  padding: 8px;
}
.gate-images-loading {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 20px;
  margin-top: 10px;
  background: var(--color-bg, #f8fafc);
  border: 1px solid var(--color-border);
  border-radius: 8px;
  font-size: 13px;
  color: var(--color-text-muted);
}
.gate-images-empty {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 20px;
  margin-top: 16px;
  background: var(--color-bg, #f8fafc);
  border: 1px dashed var(--color-border);
  border-radius: 8px;
  font-size: 13px;
  color: var(--color-text-muted);
}

/* ---------- Riwayat gate table ---------- */
.detail-history {
  max-height: 420px;
  overflow-y: auto;
}
.detail-empty {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 32px 12px;
  text-align: center;
  font-size: 13px;
  color: var(--color-text-muted);
}
.detail-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.detail-table th,
.detail-table td {
  text-align: left;
  padding: 10px 12px;
  border-bottom: 1px solid var(--color-border);
  white-space: nowrap;
}
.detail-table th {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--color-text-muted);
  font-weight: 600;
  position: sticky;
  top: 0;
  background: var(--color-surface);
}
.detail-table tbody tr:hover {
  background: var(--color-bg, #f8fafc);
}
.detail-table tr:last-child td {
  border-bottom: none;
}
.detail-time {
  color: var(--color-text-muted);
  font-variant-numeric: tabular-nums;
}

/* ---------- Live CCTV + Kendaraan In/Out row ---------- */
.live-row {
  align-items: stretch;
  margin-top: 24px;
}
.live-cctv {
  flex: 2;
  min-width: 320px;
}
.live-activity {
  flex: 1.15;
  min-width: 360px;
  display: flex;
  flex-direction: column;
}

/* "Live" pulsing indicator */
.live-indicator {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 600;
  color: var(--color-danger);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.live-pulse {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--color-danger);
  animation: live-pulse 1.5s infinite;
}
@keyframes live-pulse {
  0% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.5); }
  70% { box-shadow: 0 0 0 6px rgba(220, 38, 38, 0); }
  100% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0); }
}

/* ---------- Activity date filter ---------- */
.activity-filter {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 18px;
  border-bottom: 1px solid var(--color-border);
  flex-wrap: wrap;
}
.activity-filter-icon {
  width: 15px;
  height: 15px;
  color: var(--color-text-muted);
  flex-shrink: 0;
}
.activity-filter-input {
  border: 1px solid var(--color-border);
  border-radius: 8px;
  background: var(--color-surface);
  color: var(--color-text);
  font-size: 12.5px;
  padding: 5px 8px;
  height: 30px;
}
.activity-filter-clear {
  border: none;
  background: var(--color-danger-light);
  color: var(--color-danger);
  font-size: 12px;
  font-weight: 600;
  padding: 5px 10px;
  border-radius: 999px;
  cursor: pointer;
}
.activity-filter-clear:hover {
  filter: brightness(0.95);
}
.activity-filter-hint {
  margin-left: auto;
  font-size: 11px;
  color: var(--color-text-muted);
}

/* ---------- In/Out summary ---------- */
.activity-summary {
  display: flex;
  align-items: center;
  padding: 18px 24px;
  border-bottom: 1px solid var(--color-border);
}
.summary-item {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
}
.summary-icon {
  display: grid;
  place-items: center;
  width: 34px;
  height: 34px;
  border-radius: 50%;
}
.summary-icon svg {
  width: 18px;
  height: 18px;
}
.summary-icon.is-in {
  background: var(--color-success-light);
  color: var(--color-success);
}
.summary-icon.is-out {
  background: var(--color-danger-light);
  color: var(--color-danger);
}
.summary-label {
  font-size: 13px;
  color: var(--color-text-muted);
  font-weight: 500;
}
.summary-value {
  font-size: 28px;
  font-weight: 700;
  line-height: 1.1;
}
.text-in { color: var(--color-success); }
.text-out { color: var(--color-danger); }
.summary-divider {
  width: 1px;
  align-self: stretch;
  background: var(--color-border);
}

/* ---------- Activity feed ---------- */
.activity-feed {
  flex: 1;
  min-height: 0;
  max-height: 620px;
  overflow-y: auto;
  padding: 8px 12px;
}
.activity-item {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 13px 12px;
  border-radius: var(--radius-sm, 8px);
  transition: background 0.15s ease;
}
.activity-item:hover {
  background: var(--color-bg, #f8fafc);
}
.activity-item.is-denied {
  background: var(--color-danger-light);
  box-shadow: inset 3px 0 0 0 var(--color-danger);
}
.activity-empty {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 32px 12px;
  text-align: center;
  font-size: 13px;
  color: var(--color-text-muted);
}
.activity-empty.is-error {
  color: var(--color-danger);
}
.activity-icon {
  display: grid;
  place-items: center;
  width: 40px;
  height: 40px;
  border-radius: 50%;
  flex-shrink: 0;
}
.activity-icon svg {
  width: 20px;
  height: 20px;
}
.activity-icon.is-in {
  background: var(--color-success-light);
  color: var(--color-success);
}
.activity-icon.is-out {
  background: var(--color-danger-light);
  color: var(--color-danger);
}
.activity-info {
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-width: 0;
  flex: 1;
}
.activity-plate {
  font-size: 15px;
  font-weight: 700;
  letter-spacing: 0.02em;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}
.activity-type-badge {
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.02em;
  padding: 1px 6px;
}
.activity-meta {
  font-size: 12.5px;
  color: var(--color-text-muted);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.activity-side {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 4px;
  flex-shrink: 0;
}
.activity-status {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.02em;
  padding: 1px 6px;
  border-radius: 999px;
}
.activity-status .status-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  flex-shrink: 0;
}
.activity-status.is-active {
  color: var(--color-success);
  background: var(--color-success-light);
}
.activity-status.is-active .status-dot {
  background: var(--color-success);
}
.activity-status.is-inactive {
  color: var(--color-text-muted);
  background: var(--color-bg, #f1f5f9);
}
.activity-status.is-inactive .status-dot {
  background: var(--color-text-muted);
}
.activity-reason {
  font-size: 11px;
  color: var(--color-danger);
}
.activity-time {
  font-size: 11.5px;
  color: var(--color-text-muted);
  font-variant-numeric: tabular-nums;
}

/* New-entry animation */
.activity-enter-active {
  transition: all 0.4s ease;
}
.activity-enter-from {
  opacity: 0;
  transform: translateY(-10px);
}

/* ---------- Pagination (shared) ---------- */
.activity-pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 12px 18px;
  border-top: 1px solid var(--color-border);
}
.detail-pagination {
  margin-top: 4px;
  border-top: 1px solid var(--color-border);
  padding: 12px 4px 0;
}
.pagination-info {
  font-size: 12px;
  color: var(--color-text-muted);
}
.pagination-buttons {
  display: flex;
  align-items: center;
  gap: 8px;
}
.page-btn {
  display: grid;
  place-items: center;
  width: 28px;
  height: 28px;
  border-radius: 999px;
  border: 1px solid var(--color-border);
  background: var(--color-surface);
  color: var(--color-text);
  cursor: pointer;
  transition: background 0.15s ease, border-color 0.15s ease;
}
.page-btn svg {
  width: 15px;
  height: 15px;
}
.page-btn:hover:not(:disabled) {
  background: var(--color-bg, #f1f5f9);
  border-color: var(--color-primary, #4f46e5);
}
.page-btn:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}
.page-current {
  font-size: 12.5px;
  font-weight: 600;
  color: var(--color-text-muted);
  min-width: 44px;
  text-align: center;
}

/* ---------- Loading spinner ---------- */
.spinner {
  width: 14px;
  height: 14px;
  border-radius: 50%;
  border: 2px solid var(--color-border);
  border-top-color: var(--color-primary, #4f46e5);
  animation: spin 0.7s linear infinite;
}
@keyframes spin {
  to { transform: rotate(360deg); }
}

/* ---------- MQTT status chip ---------- */
.mqtt-chip {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  padding: 9px 16px 9px 12px;
  border-radius: 14px;
  border: 1px solid var(--color-border);
  background: var(--color-surface);
  box-shadow: var(--shadow-sm);
  cursor: pointer;
  transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
  flex-shrink: 0;
}
.mqtt-chip:hover {
  transform: translateY(-1px);
  box-shadow: var(--shadow);
}
.mqtt-chip-icon {
  display: grid;
  place-items: center;
  width: 32px;
  height: 32px;
  border-radius: 10px;
  background: var(--color-bg, #f1f5f9);
  color: var(--color-text-muted);
  flex-shrink: 0;
}
.mqtt-chip-icon svg {
  width: 17px;
  height: 17px;
}
.mqtt-chip-text {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  line-height: 1.25;
}
.mqtt-chip-label {
  font-size: 11px;
  color: var(--color-text-muted);
  font-weight: 500;
}
.mqtt-chip-state {
  font-size: 13px;
  font-weight: 700;
}
.mqtt-chip .status-dot {
  width: 9px;
  height: 9px;
  border-radius: 50%;
  background: var(--color-text-muted);
  flex-shrink: 0;
}
.mqtt-chip.is-online {
  border-color: var(--color-success);
}
.mqtt-chip.is-online .mqtt-chip-icon {
  background: var(--color-success-light);
  color: var(--color-success);
}
.mqtt-chip.is-online .mqtt-chip-state {
  color: var(--color-success);
}
.mqtt-chip.is-online .status-dot {
  background: var(--color-success);
  box-shadow: 0 0 0 3px var(--color-success-light);
}
.mqtt-chip.is-offline {
  border-color: var(--color-danger);
}
.mqtt-chip.is-offline .mqtt-chip-icon {
  background: var(--color-danger-light);
  color: var(--color-danger);
}
.mqtt-chip.is-offline .mqtt-chip-state {
  color: var(--color-danger);
}
.mqtt-chip.is-offline .status-dot {
  background: var(--color-danger);
  box-shadow: 0 0 0 3px var(--color-danger-light);
}

/* ---------- RFID modal empty / hero states ---------- */
.rfid-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 48px 24px;
  text-align: center;
  color: var(--color-text-muted);
}
.empty-icon {
  width: 60px;
  height: 60px;
  margin-bottom: 16px;
  opacity: 0.5;
}
.rfid-empty p {
  margin: 8px 0 4px;
  font-size: 15px;
  font-weight: 500;
  color: var(--color-text);
}
.rfid-empty small {
  font-size: 13px;
  color: var(--color-text-muted);
}
.topic-name {
  display: inline-block;
  margin: 8px 0;
  padding: 6px 12px;
  background: var(--color-bg);
  border: 1px solid var(--color-border);
  border-radius: 6px;
  font-family: 'Consolas', 'Monaco', monospace;
  font-size: 13px;
  color: #667eea;
}

.rfid-status-display {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 4px 0;
}
.rfid-hero {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 18px 20px;
  border-radius: 12px;
  border: 1px solid var(--color-border);
}
.rfid-hero.is-connected {
  background: var(--color-success-light);
  border-color: var(--color-success);
}
.rfid-hero.is-disconnected {
  background: var(--color-danger-light);
  border-color: var(--color-danger);
}
.rfid-hero-icon {
  display: grid;
  place-items: center;
  width: 48px;
  height: 48px;
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.6);
  flex-shrink: 0;
}
.rfid-hero-icon svg {
  width: 26px;
  height: 26px;
}
.rfid-hero.is-connected .rfid-hero-icon { color: var(--color-success); }
.rfid-hero.is-disconnected .rfid-hero-icon { color: var(--color-danger); }
.rfid-hero-text {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-width: 0;
}
.rfid-hero-label {
  font-size: 16px;
  font-weight: 700;
  color: var(--color-text);
}
.rfid-hero-gate {
  font-size: 13px;
  color: var(--color-text-muted);
}
.rfid-hero-badge {
  font-size: 12px;
  padding: 4px 12px;
  flex-shrink: 0;
}

.rfid-field-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 12px;
}
.rfid-field-box {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 12px 14px;
  background: var(--color-bg);
  border: 1px solid var(--color-border);
  border-radius: 8px;
}
.rfid-field-box-wide {
  grid-column: 1 / -1;
}
.rfid-field-box-label {
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-text-muted);
}
.rfid-field-box-value {
  font-size: 14px;
  font-weight: 500;
  color: var(--color-text);
}
.rfid-field-box-value.code {
  font-family: 'Consolas', 'Monaco', monospace;
  color: #667eea;
}

/* RFID Detail Modal */
.rfid-detail-summary {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 12px;
  padding: 16px;
  background: var(--color-bg);
  border: 1px solid var(--color-border);
  border-radius: 8px;
}
.rfid-detail-item {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.rfid-detail-label {
  font-size: 12px;
  font-weight: 600;
  color: var(--color-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.rfid-detail-value {
  font-size: 14px;
  font-weight: 500;
  color: var(--color-text);
}

@media (max-width: 720px) {
  .dashboard-head {
    flex-direction: column;
    align-items: stretch;
  }
  .mqtt-chip {
    align-self: flex-start;
  }
  .camera-grid {
    grid-template-columns: 1fr;
  }
  .activity-feed {
    max-height: 420px;
  }
  .rfid-hero {
    flex-wrap: wrap;
  }
  .rfid-field-grid {
    grid-template-columns: 1fr;
  }
  .rfid-detail-summary {
    grid-template-columns: 1fr;
  }
}
</style>