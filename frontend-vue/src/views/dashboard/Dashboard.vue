<script setup>
import { onMounted, onUnmounted, ref, computed } from 'vue'
import { RouterLink } from 'vue-router'
import dashboardApi from '@/api/dashboard'
import rfidApi from '@/api/rfid'
import cameraApi from '@/api/camera'
import { gateApi } from '@/api/gate'
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
  console.log('🔔 RFID Status received:', message)
  console.log('📊 Message type:', typeof message)
  console.log('📋 Parsed data:', {
    gate_id: message.gate_id || message.gate,
    device_type: message.device_type || message.type,
    status: message.status,
    message: message.message || message.reason,
    timestamp: message.timestamp
  })

  // Update RFID Status card display
  rfidStatusData.value = {
    gate_id: message.gate_id || message.gate || null,
    device_type: message.device_type || message.type || 'RFID',
    status: message.status || null,
    message: message.message || message.reason || null,
    timestamp: message.timestamp || new Date().toISOString(),
  }

  console.log('✅ rfidStatusData updated:', rfidStatusData.value)

  // Note: Badge status di-update oleh loadRfidConnStatus() polling
  // yang fetch dari database log_rfid_conn
}

async function initMqtt() {
  console.log('🚀 Initializing MQTT...')
  try {
    await mqttConnect()
    console.log('✅ MQTT Connected!')
    mqttConnected.value = isConnected.value
    if (mqttConnected.value) {
      console.log(`📡 Subscribing to ${RFID_STATUS_TOPIC} with QoS 1...`)
      // Subscribe dengan QoS 1 (wajib)
      await mqttSubscribe(RFID_STATUS_TOPIC, handleRfidStatus, { qos: 1 })
      console.log(`✅ Subscribed to ${RFID_STATUS_TOPIC} with QoS 1`)
      console.log('⏳ Waiting for retained message (if any)...')
    }
  } catch (err) {
    mqttError.value = err
    console.error('❌ Failed to init MQTT:', err)
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

const vehicleActivity = ref([])
const vehicleActivityAll = ref([])
const vehicleInCount = ref(0)
const vehicleOutCount = ref(0)
const activityLoading = ref(true)
const activityError = ref('')
const activityPage = ref(1)
const activityPerPage = 15
const activityPagination = ref({
  total: 0,
  pages: 0,
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
  }
}

async function loadActivity() {
  try {
    const res = await store.fetchRecentLogs({ per_page: 200 })
    const logs = res.data || []
    vehicleActivityAll.value = logs.map(mapLog)
    
    // Counters reflect actual (granted) entries/exits in the recent window.
    vehicleInCount.value = logs.filter((l) => Number(l.direction) === 1 && l.access_granted).length
    vehicleOutCount.value = logs.filter((l) => Number(l.direction) === 2 && l.access_granted).length
    
    // Set pagination
    activityPagination.value = {
      total: logs.length,
      pages: Math.ceil(logs.length / activityPerPage),
    }
    
    // Show current page
    updateActivityPage(1)
    activityError.value = ''
  } catch (err) {
    activityError.value = extractErrorMessage(err, 'Gagal memuat aktivitas kendaraan.')
  } finally {
    activityLoading.value = false
  }
}

function updateActivityPage(page) {
  activityPage.value = page
  const start = (page - 1) * activityPerPage
  const end = start + activityPerPage
  vehicleActivity.value = vehicleActivityAll.value.slice(start, end)
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

const gateActionLabel = computed(() => (gateAction.value === 'open' ? 'Buka Gate' : 'Tutup Gate'))
const gateModalTitle = computed(
    () => `Kontrol Gate${gateCamera.value ? ' · ' + gateCamera.value.name : ''}`,
)

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
  gateModal.value = true
}

function validateGateForm() {
  const errors = {}
  if (!gateForm.value.nomor_plat.trim()) errors.nomor_plat = 'Nomor plat wajib diisi.'
  gateErrors.value = errors
  return Object.keys(errors).length === 0
}

function submitGateAction() {
  if (!validateGateForm()) return
  if (!gateCamera.value) {
    alert('Camera tidak ditemukan')
    return
  }

  gateSubmitting.value = true

  // Publish gate action ke MQTT
  publishGateAction(gateCamera.value.gate_id, gateAction.value === 'open')
    .then((success) => {
      if (success) {
        // Close modal and show success message
        gateModal.value = false
        
        // Show success notification based on action
        if (gateAction.value === 'open') {
          toastSuccess('Gate berhasil di buka')
        } else {
          toastSuccess('Gate berhasil di tutup')
        }
        
        console.log(
          `✅ Gate ${gateAction.value} command sent for ${gateCamera.value.gate_id}`
        )
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
    <PageHeader title="Dashboard" subtitle="Ringkasan data sistem GH PIK2" />

    <Loader v-if="loading" text="Memuat statistik..." />
    <div v-else-if="error" class="alert alert-danger">{{ error }}</div>

    <template v-else>
      <!-- Toolbar: akses cepat ke status RFID Reader (MQTT) tanpa card besar -->
      <div class="dashboard-toolbar">
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
          <span class="status-dot"></span>
          <span class="mqtt-chip-label">RFID Reader </span>
          <span class="mqtt-chip-state">{{ mqttConnected ? 'Online' : 'Offline' }}</span>
        </button>
      </div>

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
            <span class="stat-value">{{ formatNumber(card.value) }}</span>
            <span class="stat-label">{{ card.label }}</span>
          </div>
        </component>
      </div>

      <!-- Live CCTV + Kendaraan In/Out -->
      <div class="row live-row">
        <!-- Live CCTV streams -->
        <div class="card live-cctv">
          <div class="card-header card-header-flex">
            <span>Live CCTV</span>
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
                <LiveStream v-else :src="cam.src" :label="cam.name" />
                <div class="camera-controls">
                  <!-- Camera 1, 2: Show kontrol gate button; Camera 3, 4: Hidden -->
                  <template v-if="cam.id <= 2">
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
                  </template>
                  <!-- Camera 3, 4: Buttons commented out
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
                  -->
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Live Kendaraan In/Out -->
        <div class="card live-activity">
          <div class="card-header card-header-flex">
            <span>Live Kendaraan In / Out</span>
            <span class="header-status">
<!--              <span-->
              <!--                  class="rfid-conn"-->
              <!--                  :class="rfidAllOnline ? 'is-online' : 'is-offline'"-->
              <!--                  :title="rfidError || `RFID ${rfidSummary.online}/${rfidSummary.total} terhubung`"-->
              <!--              >-->
              <!--                <span class="status-dot"></span>RFID {{ rfidSummary.online }}/{{ rfidSummary.total }}-->
              <!--              </span>-->
              <span class="live-indicator"><span class="live-pulse"></span>Live</span>
            </span>
          </div>
<!--          <div v-if="rfidGates.length" class="rfid-gates">-->
<!--            <button-->
<!--                v-for="gate in rfidGates"-->
<!--                :key="gate.gate_id"-->
<!--                type="button"-->
<!--                class="rfid-gate"-->
<!--                :class="gate.is_online ? 'is-online' : 'is-offline'"-->
<!--                :title="`Klik untuk melihat detail · ${gate.status_label}${gate.detail ? ' · ' + gate.detail : ''}${gate.event_ts ? ' · ' + formatDateTime(gate.event_ts) : ''}`"-->
<!--                @click="openRfidDetail(gate)"-->
<!--            >-->
<!--              <span class="status-dot"></span>-->
<!--              <span class="rfid-gate-id">{{ gate.gate_id }}</span>-->
<!--              <span class="rfid-gate-status">{{ gate.status_label }}</span>-->
<!--            </button>-->
<!--          </div>-->
          <div class="activity-summary">
            <div class="summary-item">
              <span class="summary-label">Masuk</span>
              <span class="summary-value text-in">{{ formatNumber(vehicleInCount) }}</span>
            </div>
            <div class="summary-divider"></div>
            <div class="summary-item">
              <span class="summary-label">Keluar</span>
              <span class="summary-value text-out">{{ formatNumber(vehicleOutCount) }}</span>
            </div>
          </div>
          <div class="activity-feed">
            <div v-if="activityLoading && !vehicleActivity.length" class="activity-empty">
              Memuat aktivitas...
            </div>
            <div v-else-if="activityError && !vehicleActivity.length" class="activity-empty">
              {{ activityError }}
            </div>
            <div v-else-if="!vehicleActivity.length" class="activity-empty">
              Belum ada aktivitas tap.
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
                    <span
                        class="activity-status"
                        :class="item.active ? 'is-active' : 'is-inactive'"
                        :title="item.active ? 'Kartu aktif' : 'Kartu tidak aktif'"
                    >
                      <span class="status-dot"></span>{{ item.statusLabel }}
                    </span>
                  </span>
                  <span class="activity-meta">{{ item.name }}<template v-if="item.gate"> · {{ item.gate }}</template><template v-if="!item.granted"> · {{ kartuReasonMeta(item.reason).label }}</template></span>
                </div>
                <div class="activity-side">
                  <span
                      class="badge"
                      :class="item.granted ? (item.type === 'in' ? 'badge-success' : 'badge-info') : 'badge-danger'"
                  >
                    {{ item.granted ? (item.type === 'in' ? 'Masuk' : 'Keluar') : 'Ditolak' }}
                  </span>
                  <span class="activity-time">{{ item.time }}</span>
                </div>
              </div>
            </TransitionGroup>
          </div>
          <!-- Pagination untuk Activity Feed -->
          <div v-if="!activityLoading && vehicleActivity.length > 0 && activityPagination.pages > 1" style="margin-top: 16px; padding: 0 16px;">
            <div class="pagination-info" style="margin-bottom: 12px; font-size: 0.9em; color: #666; text-align: center;">
              Menampilkan {{ (activityPage - 1) * activityPerPage + 1 }} - {{ Math.min(activityPage * activityPerPage, activityPagination.total) }} dari {{ activityPagination.total }} aktivitas
            </div>
            <div class="pagination-buttons" style="display: flex; gap: 8px; justify-content: center;">
              <Button 
                size="sm" 
                variant="secondary" 
                :disabled="activityPage === 1"
                @click="updateActivityPage(activityPage - 1)"
              >
                ← Sebelumnya
              </Button>
              <span style="align-self: center; padding: 0 8px; font-size: 0.9em;">
                {{ activityPage }} / {{ activityPagination.pages }}
              </span>
              <Button 
                size="sm" 
                variant="secondary" 
                :disabled="activityPage >= activityPagination.pages"
                @click="updateActivityPage(activityPage + 1)"
              >
                Selanjutnya →
              </Button>
            </div>
          </div>
        </div>
      </div>

      <!-- Modal: Buka / Tutup Gate (frontend only) -->
      <Modal v-model="gateModal" :title="gateModalTitle">
        <div class="gate-toggle" role="tablist">
          <button
              type="button"
              class="gate-toggle-btn"
              :class="{ 'is-active is-open': gateAction === 'open' }"
              @click="gateAction = 'open'"
          >
            Buka Gate
          </button>
          <!-- Close gate button commented out for now
          <button
              type="button"
              class="gate-toggle-btn"
              :class="{ 'is-active is-close': gateAction === 'close' }"
              @click="gateAction = 'close'"
          >
            Tutup Gate
          </button>
          -->
        </div>
        <p class="gate-hint">
          Masukkan nomor plat kendaraan
        </p>
        <form class="gate-form" @submit.prevent="submitGateAction">
          <div class="form-group">
            <label class="form-label">Nomor Plat <span class="req">*</span></label>
            <input
                v-model="gateForm.nomor_plat"
                type="text"
                class="form-control plate-input"
                :class="{ 'is-invalid': gateErrors.nomor_plat }"
                placeholder="B 1234 XYZ"
            />
            <span v-if="gateErrors.nomor_plat" class="form-error">{{ gateErrors.nomor_plat }}</span>
          </div>
        </form>
        
        <div v-if="gateCamera" class="gate-live-cctv">
          <label class="form-label">Live CCTV</label>
          <LiveStream :src="gateCamera.src" />
        </div>

        <div v-if="gatePublishError" class="alert alert-danger" style="margin-top: 12px;">
          {{ gatePublishError }}
        </div>

        <template #footer>
          <Button variant="secondary" type="button" @click="gateModal = false" :disabled="gateSubmitting || gateConnecting">Batal</Button>
          <Button
              :variant="gateAction === 'open' ? 'primary' : 'danger'"
              type="button"
              :loading="gateSubmitting || gateConnecting"
              @click="submitGateAction"
          >
            <span v-if="gateConnecting">⏳ Connecting MQTT...</span>
            <span v-else-if="gateSubmitting">📤 Sending...</span>
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
          <div v-if="detailGateLoading" class="detail-empty">
            ⏳ Memuat riwayat...
          </div>
          <div v-else-if="detailGateError" class="alert alert-danger">
            {{ detailGateError }}
          </div>
          <div v-else-if="!detailGateLogs.length" class="detail-empty">
            Belum ada riwayat gate.
          </div>
          <table v-else class="detail-table">
            <thead>
            <tr>
              <th>Waktu</th>
              <th>Gate ID</th>
              <th>Aksi</th>
              <th>Hasil</th>
            </tr>
            </thead>
            <tbody>
            <tr v-for="log in detailGateLogs" :key="log.id">
              <td class="detail-time">{{ formatDateTime(log.event_ts) }}</td>
              <td class="detail-gate-id">{{ log.gate_id }}</td>
              <td>
                <span class="badge" :class="log.action === 'OPEN' ? 'badge-success' : 'badge-info'">
                  {{ log.action }}
                </span>
              </td>
              <td>
                <span class="badge badge-success">
                  {{ log.result }}
                </span>
              </td>
            </tr>
            </tbody>
          </table>
          <!-- Pagination -->
          <div v-if="!detailGateLoading && detailGateLogs.length > 0" style="margin-top: 16px; text-align: center;">
            <div class="pagination-info" style="margin-bottom: 12px; font-size: 0.9em; color: #666;">
              Menampilkan {{ (detailGatePage - 1) * detailGatePerPage + 1 }} - {{ Math.min(detailGatePage * detailGatePerPage, detailGatePagination.total) }} dari {{ detailGatePagination.total }} records
            </div>
            <div class="pagination-buttons" style="display: flex; gap: 8px; justify-content: center;">
              <Button 
                size="sm" 
                variant="secondary" 
                :disabled="detailGatePage === 1 || detailGateLoading"
                @click="loadDetailGateLogs(detailGatePage - 1)"
              >
                ← Sebelumnya
              </Button>
              <span style="align-self: center; padding: 0 8px;">
                Halaman {{ detailGatePage }} dari {{ detailGatePagination.last_page }}
              </span>
              <Button 
                size="sm" 
                variant="secondary" 
                :disabled="!detailGatePagination.has_more || detailGateLoading"
                @click="loadDetailGateLogs(detailGatePage + 1)"
              >
                Selanjutnya →
              </Button>
            </div>
          </div>
        </div>
        <template #footer>
          <Button variant="secondary" type="button" @click="detailModal = false">Tutup</Button>
        </template>
      </Modal>

      <!-- Modal: RFID Gate Connection Detail -->
      <!-- Modal: RFID Gate Detail (tanpa riwayat log) -->
      <Modal
          v-model="rfidDetailModal"
          :title="`Detail RFID Gate${rfidDetailGate ? ' · ' + rfidDetailGate.gate_id : ''}`"
      >
        <div v-if="rfidDetailGate" class="rfid-detail-summary">
          <div class="rfid-detail-item">
            <span class="rfid-detail-label">Gate ID:</span>
            <span class="rfid-detail-value">{{ rfidDetailGate.gate_id }}</span>
          </div>
          <div class="rfid-detail-item">
            <span class="rfid-detail-label">Status Saat Ini:</span>
            <span class="rfid-detail-value">
        <span
            class="badge"
            :class="rfidDetailGate.is_online ? 'badge-success' : 'badge-danger'"
        >
          {{ rfidDetailGate.status_label }}
        </span>
      </span>
          </div>
          <div v-if="rfidDetailGate.detail" class="rfid-detail-item">
            <span class="rfid-detail-label">Detail:</span>
            <span class="rfid-detail-value">{{ rfidDetailGate.detail }}</span>
          </div>
          <div v-if="rfidDetailGate.event_ts" class="rfid-detail-item">
            <span class="rfid-detail-label">Terakhir Update:</span>
            <span class="rfid-detail-value">{{ formatDateTime(rfidDetailGate.event_ts) }}</span>
          </div>
        </div>
        <template #footer>
          <Button variant="secondary" type="button" @click="closeRfidDetail">Tutup</Button>
        </template>
      </Modal>

      <!-- Modal: Detail Status RFID Reader (MQTT), diakses lewat chip di toolbar atas -->
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
              {{ rfidStatusData.status === 'CONNECTED' ? '✓ Online' : '✗ Offline' }}
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
.stat-card {
  display: flex;
  align-items: center;
  gap: 22px;
  padding: 28px;
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  box-shadow: var(--shadow-sm);
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
a.stat-card:hover {
  transform: translateY(-2px);
  box-shadow: var(--shadow);
}
.stat-icon {
  display: grid;
  place-items: center;
  width: 68px;
  height: 68px;
  border-radius: 16px;
  flex-shrink: 0;
}
.stat-icon svg {
  width: 34px;
  height: 34px;
}
.stat-meta {
  display: flex;
  flex-direction: column;
}
.stat-value {
  font-size: 36px;
  font-weight: 700;
  line-height: 1.1;
}
.stat-label {
  font-size: 15px;
  color: var(--color-text-muted);
}
.status-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 0;
  border-bottom: 1px solid var(--color-border);
}
.status-row:last-child {
  border-bottom: none;
}

/* Live CCTV 2x2 grid */
.card-header-flex {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.stream-count {
  font-size: 12px;
  font-weight: 500;
  color: var(--color-text-muted);
  background: var(--color-bg, #f1f5f9);
  border: 1px solid var(--color-border);
  border-radius: 999px;
  padding: 2px 10px;
}
.camera-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 20px;
}
.camera-tile {
  border: 1px solid var(--color-border);
  border-radius: var(--radius, 8px);
  overflow: hidden;
  background: #000;
  box-shadow: var(--shadow-sm);
  transition: box-shadow 0.15s ease, transform 0.15s ease;
}
.camera-tile:hover {
  transform: translateY(-2px);
  box-shadow: var(--shadow);
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
  width: 38px;
  height: 38px;
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

/* Gate action form */
.gate-toggle {
  display: flex;
  gap: 6px;
  padding: 4px;
  margin-bottom: 16px;
  background: var(--color-bg, #f1f5f9);
  border: 1px solid var(--color-border);
  border-radius: 10px;
}
.gate-toggle-btn {
  flex: 1;
  padding: 8px 12px;
  border: none;
  border-radius: 7px;
  background: transparent;
  font-size: 13px;
  font-weight: 600;
  color: var(--color-text-muted);
  cursor: pointer;
  transition: background 0.15s ease, color 0.15s ease;
}
.gate-toggle-btn:hover {
  color: var(--color-text);
}
.gate-toggle-btn.is-active.is-open {
  background: var(--color-success, #16a34a);
  color: #fff;
}
.gate-toggle-btn.is-active.is-close {
  background: var(--color-danger, #dc2626);
  color: #fff;
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
.opt {
  color: var(--color-text-muted);
  font-weight: 400;
  font-size: 12px;
}

/* Detail transaction history */
.detail-history {
  max-height: 420px;
  overflow-y: auto;
}
.detail-empty {
  padding: 28px 12px;
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
  padding: 9px 10px;
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
.detail-table tr:last-child td {
  border-bottom: none;
}
.detail-time {
  color: var(--color-text-muted);
  font-variant-numeric: tabular-nums;
}
.detail-plate {
  font-weight: 600;
  letter-spacing: 0.02em;
}

/* Live CCTV + Kendaraan In/Out row */
.live-row {
  align-items: stretch;
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
.header-status {
  display: inline-flex;
  align-items: center;
  gap: 10px;
}
.rfid-conn {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.02em;
  padding: 2px 8px;
  border-radius: 999px;
}
.rfid-conn .status-dot,
.rfid-gate .status-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  flex-shrink: 0;
}
.rfid-conn.is-online {
  color: var(--color-success);
  background: var(--color-success-light);
}
.rfid-conn.is-online .status-dot {
  background: var(--color-success);
}
.rfid-conn.is-offline {
  color: var(--color-danger);
  background: var(--color-danger-light);
}
.rfid-conn.is-offline .status-dot {
  background: var(--color-danger);
}

/* Per-gate RFID connection strip */
.rfid-gates {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  padding: 10px 20px;
  border-bottom: 1px solid var(--color-border);
}
.rfid-gate {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  padding: 3px 10px;
  border-radius: 999px;
  border: 1px solid var(--color-border);
  background: var(--color-bg, #f1f5f9);
  cursor: pointer;
  transition: all 0.15s ease;
}
.rfid-gate:hover {
  transform: translateY(-1px);
  box-shadow: var(--shadow-sm);
  border-color: var(--color-primary);
}
.rfid-gate-id {
  font-weight: 600;
  letter-spacing: 0.02em;
}
.rfid-gate-status {
  color: var(--color-text-muted);
}
.rfid-gate.is-online .status-dot {
  background: var(--color-success);
}
.rfid-gate.is-offline .status-dot {
  background: var(--color-danger);
}
.rfid-gate.is-offline .rfid-gate-status {
  color: var(--color-danger);
}

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

/* In/Out summary */
.activity-summary {
  display: flex;
  align-items: center;
  padding: 20px 24px;
  border-bottom: 1px solid var(--color-border);
}
.summary-item {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
}
.summary-label {
  font-size: 14px;
  color: var(--color-text-muted);
}
.summary-value {
  font-size: 32px;
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

/* Activity feed */
.activity-feed {
  flex: 1;
  min-height: 0;
  max-height: 620px;
  overflow-y: auto;
  padding: 10px 14px;
}
.activity-item {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 14px 10px;
  border-radius: var(--radius-sm);
  transition: background 0.15s ease;
}
.activity-item:hover {
  background: var(--color-bg);
}
.activity-item.is-denied {
  box-shadow: inset 3px 0 0 0 var(--color-danger);
}
.activity-empty {
  padding: 28px 12px;
  text-align: center;
  font-size: 13px;
  color: var(--color-text-muted);
}
.activity-icon {
  display: grid;
  place-items: center;
  width: 42px;
  height: 42px;
  border-radius: 50%;
  flex-shrink: 0;
}
.activity-icon svg {
  width: 22px;
  height: 22px;
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
  min-width: 0;
  flex: 1;
}
.activity-plate {
  font-size: 16px;
  font-weight: 600;
  letter-spacing: 0.02em;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.activity-type-badge {
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.02em;
  padding: 1px 6px;
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
.activity-meta {
  font-size: 13px;
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
.activity-time {
  font-size: 12px;
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

/* Toolbar + chip akses cepat Status RFID Reader (MQTT) */
.dashboard-toolbar {
  display: flex;
  justify-content: flex-end;
  margin: -8px 0 20px;
}
.mqtt-chip {
  display: inline-flex;
  align-items: center;
  gap: 9px;
  padding: 8px 16px 8px 12px;
  border-radius: 999px;
  border: 1px solid var(--color-border);
  background: var(--color-surface);
  box-shadow: var(--shadow-sm);
  font-size: 13px;
  font-weight: 600;
  color: var(--color-text);
  cursor: pointer;
  transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
}
.mqtt-chip:hover {
  transform: translateY(-1px);
  box-shadow: var(--shadow);
}
.mqtt-chip-icon {
  display: grid;
  place-items: center;
  width: 22px;
  height: 22px;
  color: var(--color-text-muted);
  flex-shrink: 0;
}
.mqtt-chip-icon svg {
  width: 15px;
  height: 15px;
}
.mqtt-chip .status-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--color-text-muted);
  flex-shrink: 0;
}
.mqtt-chip-label {
  color: var(--color-text-muted);
  font-weight: 500;
}
.mqtt-chip-state {
  font-weight: 700;
}
.mqtt-chip.is-online {
  border-color: var(--color-success);
}
.mqtt-chip.is-online .mqtt-chip-icon {
  color: var(--color-success);
}
.mqtt-chip.is-online .status-dot {
  background: var(--color-success);
  box-shadow: 0 0 0 3px var(--color-success-light);
}
.mqtt-chip.is-online .mqtt-chip-state {
  color: var(--color-success);
}
.mqtt-chip.is-offline {
  border-color: var(--color-danger);
}
.mqtt-chip.is-offline .mqtt-chip-icon {
  color: var(--color-danger);
}
.mqtt-chip.is-offline .status-dot {
  background: var(--color-danger);
  box-shadow: 0 0 0 3px var(--color-danger-light);
}
.mqtt-chip.is-offline .mqtt-chip-state {
  color: var(--color-danger);
}

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
  width: 64px;
  height: 64px;
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

/* Hero status (baru) */
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

/* Field grid (baru) */
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
.rfid-detail-container {
  display: flex;
  flex-direction: column;
  gap: 24px;
}
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
.rfid-detail-history {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.rfid-detail-title {
  margin: 0;
  font-size: 16px;
  font-weight: 600;
  color: var(--color-text);
}

@media (max-width: 720px) {
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
  .dashboard-toolbar {
    justify-content: flex-start;
  }
  .mqtt-chip-label {
    display: none;
  }
}
</style>