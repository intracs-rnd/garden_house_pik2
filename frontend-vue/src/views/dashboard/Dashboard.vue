<script setup>
import { onMounted, onUnmounted, ref, computed, watch } from 'vue'
import { RouterLink } from 'vue-router'
import dashboardApi from '@/api/dashboard'
import rfidApi from '@/api/rfid'
import cameraApi from '@/api/camera'
import { gateApi } from '@/api/gate'
import transactionApi from '@/api/transaction'
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
import DeviceStatusWidget from '@/components/dashboard/DeviceStatusWidget.vue'

const loading = ref(true)
const error = ref('')
const stats = ref(null)
const gate1Total = ref(0)
const gate2Total = ref(0)


// MQTT untuk RFID Status
const RFID_STATUS_TOPIC = 'gate/in/rfid_status'
const GATE_EVENT_TOPIC  = 'gate/in/event'

// MQTT untuk Device Status (wildcard get/+/status)
const DEVICE_STATUS_TOPIC = 'get/+/status'

/**
 * Menyimpan status tiap device yang diterima dari topic get/{nama_device}/status.
 * Map: nama_device → { nama_device, status, receivedAt }
 * Disimpan sebagai Map agar update per-device tidak duplikat.
 */
const deviceStatusMap = ref(new Map())
const deviceStatusList = computed(() =>
  Array.from(deviceStatusMap.value.values()).sort((a, b) =>
    a.nama_device.localeCompare(b.nama_device)
  )
)

/**
 * Handler untuk topic get/+/status.
 * Menerima JSON {nama_device, status} dan update deviceStatusMap.
 */
function handleDeviceStatus(message, receivedTopic) {
  try {
    let nama_device = ''
    let status = ''

    if (typeof message === 'object' && message !== null && message.nama_device) {
      // Pesan sudah di-parse JSON oleh mqtt.js service
      nama_device = message.nama_device
      status = (message.status || '').toLowerCase()
    } else {
      // Fallback: ambil nama device dari URL topic
      const parts = (receivedTopic || '').split('/')
      nama_device = parts[1] || 'unknown'
      status = typeof message === 'string' ? message.toLowerCase() : String(message).toLowerCase()
    }

    if (!nama_device) return

    // Update atau tambahkan entry di Map
    deviceStatusMap.value = new Map(deviceStatusMap.value)
    deviceStatusMap.value.set(nama_device, {
      nama_device,
      status,
      receivedAt: new Date().toISOString(),
    })
  } catch (e) {
    console.warn('[MQTT Device Status] Failed to parse message:', e, message)
  }
}

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

// --- Live MQTT Events (SCAN | GATE | VEHICLE) ----------------------------
// Maks item yang disimpan di memori (oldest will be dropped)
const MQTT_EVENT_MAX = 100

const mqttEvents = ref([])          // seluruh event
const mqttEventFilter = ref('ALL')  // 'ALL' | 'SCAN' | 'GATE' | 'VEHICLE'
const mqttEventCategories = ['ALL', 'SCAN', 'GATE', 'VEHICLE']

// Counter per category (direbuild dari events saat load)
const mqttEventCounts = ref({ SCAN: 0, GATE: 0, VEHICLE: 0 })

// --- localStorage persistence -------------------------------------------
const LS_EVENTS_KEY = 'gh_pik2_mqtt_events'
const LS_DATE_KEY   = 'gh_pik2_mqtt_events_date'

function getTodayStr() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/** Muat events dari localStorage saat halaman pertama dibuka */
function loadFromStorage() {
  try {
    const savedDate = localStorage.getItem(LS_DATE_KEY)
    const today = getTodayStr()

    // Beda hari → hapus data lama secara otomatis
    if (savedDate && savedDate !== today) {
      localStorage.removeItem(LS_EVENTS_KEY)
      localStorage.removeItem(LS_DATE_KEY)
      console.log('[MQTT] Data hari lama dihapus otomatis.')
      return
    }

    const raw = localStorage.getItem(LS_EVENTS_KEY)
    if (!raw) return

    const saved = JSON.parse(raw)
    if (!Array.isArray(saved) || saved.length === 0) return

    mqttEvents.value = saved

    // Rebuild counter dari events yang tersimpan
    const counts = { SCAN: 0, GATE: 0, VEHICLE: 0 }
    for (const evt of saved) {
      if (evt.category in counts) counts[evt.category]++
    }
    mqttEventCounts.value = counts
    console.log(`[MQTT] ${saved.length} events dimuat dari localStorage.`)
  } catch (e) {
    console.warn('[MQTT] Gagal memuat dari localStorage:', e)
  }
}

/** Simpan events ke localStorage (dipanggil setiap kali ada event baru) */
function saveToStorage() {
  try {
    localStorage.setItem(LS_EVENTS_KEY, JSON.stringify(mqttEvents.value))
    localStorage.setItem(LS_DATE_KEY, getTodayStr())
  } catch (e) {
    console.warn('[MQTT] Gagal menyimpan ke localStorage:', e)
  }
}

// Muat data tersimpan segera saat komponen diinisialisasi
loadFromStorage()

/**
 * Parse pesan dari topic gate/in/event.
 * mqtt.js service sudah pre-parse pipe-string menjadi object:
 *   { gate_id, device_type, status, message, timestamp }
 * di mana:  device_type = kategori (SCAN/GATE/VEHICLE)
 *           status      = detail   (rfid tag / OPEN / CLEARED)
 *           message     = result   (ALLOW/DENY/SUCCESS/OK)
 * Jika diterima sebagai raw string, parse manual.
 */
function parseMqttGateEvent(raw) {
  if (typeof raw === 'object' && raw !== null) {
    // Pre-parsed oleh mqtt.js: device_type = category, status = detail, message = status
    if ('device_type' in raw) {
      return {
        gate_id:   raw.gate_id   || '',
        category:  (raw.device_type || '').toUpperCase(),
        detail:    raw.status    || '',   // field 'status' dari mqtt.js = detail sebenarnya
        status:    raw.message   || '',   // field 'message' dari mqtt.js = status sebenarnya
        timestamp: raw.timestamp || '',
      }
    }
    // Sudah dalam format yang benar (punya field 'category')
    return raw
  }
  // Raw string — parse pipe-delimited manual
  const parts = String(raw).split('|')
  if (parts.length < 5) return null
  const [gate_id, category, detail, status, timestamp] = parts
  return { gate_id, category: category.toUpperCase(), detail, status, timestamp }
}

/** Tambahkan event baru ke daftar mqttEvents (prepend, trim bila perlu) */
function pushMqttEvent(evt) {
  if (!evt) return
  const id = `${Date.now()}-${Math.random().toString(36).slice(2)}`
  mqttEvents.value.unshift({ id, ...evt, receivedAt: new Date().toISOString() })
  if (mqttEvents.value.length > MQTT_EVENT_MAX) {
    mqttEvents.value = mqttEvents.value.slice(0, MQTT_EVENT_MAX)
  }
  // Tambah counter
  const cat = evt.category
  if (cat in mqttEventCounts.value) {
    mqttEventCounts.value[cat]++
  }
  // Simpan ke localStorage
  saveToStorage()
}



/** Handler MQTT untuk topic gate/in/event */
function handleGateEvent(raw) {
  const evt = parseMqttGateEvent(raw)
  pushMqttEvent(evt)
}

// Filtered events berdasarkan tab
const filteredMqttEvents = computed(() => {
  if (mqttEventFilter.value === 'ALL') return mqttEvents.value
  return mqttEvents.value.filter(e => e.category === mqttEventFilter.value)
})

// Helper tampilan per kategori
function mqttEventMeta(evt) {
  switch (evt.category) {
    case 'SCAN':
      return {
        label: 'SCAN',
        colorClass: 'cat-scan',
        icon: 'M6 2h12a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zM9 7h6M9 11h6M9 15h4',
        statusClass: evt.status === 'ALLOW' ? 'badge-success' : (evt.status === 'DENY' ? 'badge-danger' : 'badge-muted'),
        detail: evt.detail,
        detailFull: evt.detail,
      }
    case 'GATE':
      return {
        label: 'GATE',
        colorClass: 'cat-gate',
        icon: 'M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z',
        statusClass: evt.status === 'SUCCESS' ? 'badge-success' : (evt.status === 'ERROR' ? 'badge-danger' : 'badge-warning'),
        detail: evt.detail,
        detailFull: evt.detail,
      }
    case 'VEHICLE':
      return {
        label: 'VEHICLE',
        colorClass: 'cat-vehicle',
        icon: 'M5 13l1.4-4.2A2 2 0 0 1 8.3 7.4h7.4a2 2 0 0 1 1.9 1.4L19 13M5 13a2 2 0 0 0-2 2v3.5a1 1 0 0 0 1 1h1.2M5 13h14M19 13a2 2 0 0 1 2 2v3.5a1 1 0 0 1-1 1h-1.2M7.5 19.5a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3zM16.5 19.5a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3z',
        statusClass: evt.status === 'OK' ? 'badge-success' : 'badge-muted',
        detail: evt.detail,
        detailFull: evt.detail,
      }
    default:
      return {
        label: evt.category || '?',
        colorClass: 'cat-unknown',
        icon: 'M12 8h.01M11 12h1v4h1M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
        statusClass: 'badge-muted',
        detail: evt.detail,
        detailFull: evt.detail,
      }
  }
}

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
      // Subscribe RFID status (QoS 1)
      await mqttSubscribe(RFID_STATUS_TOPIC, handleRfidStatus, { qos: 1 })
      // Subscribe gate events (QoS 0)
      await mqttSubscribe(GATE_EVENT_TOPIC, handleGateEvent, { qos: 0 })
      // Subscribe device status wildcard get/+/status (QoS 1)
      await mqttSubscribe(DEVICE_STATUS_TOPIC, handleDeviceStatus, { qos: 1 })
    }
  } catch (err) {
    mqttError.value = err
    console.error('Failed to init MQTT:', err)
  }
}

/** Reset semua event MQTT, counter, dan localStorage */
function clearMqttEvents() {
  mqttEvents.value = []
  mqttEventCounts.value = { SCAN: 0, GATE: 0, VEHICLE: 0 }
  localStorage.removeItem(LS_EVENTS_KEY)
  localStorage.removeItem(LS_DATE_KEY)
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
  } finally {
    loadGateTotals()
  }
}

async function loadGateTotals() {
  if (cameras.value.length > 0) {
    try {
      const res1 = await gateApi.getLogsByGateId(cameras.value[0].gate_id, { per_page: 1 })
      gate1Total.value = res1.data?.pagination?.total || 0
    } catch (e) {
      console.error('Failed to load total for Kamera 1:', e)
    }
  }
  if (cameras.value.length > 1) {
    try {
      const res2 = await gateApi.getLogsByGateId(cameras.value[1].gate_id, { per_page: 1 })
      gate2Total.value = res2.data?.pagination?.total || 0
    } catch (e) {
      console.error('Failed to load total for Kamera 2:', e)
    }
  }
}

// --- Live Kendaraan In/Out (dari log_gate + gate_manual_control via MQTT) ---
// Polling dari /api/gate/live-activity yang menggabungkan event RFID otomatis
// (log_gate) dan kontrol manual operator (gate_manual_control).
const auth = useAuthStore()

const ACTIVITY_POLL_MS = 5000

const vehicleActivityAll = ref([])
const activitySummary = ref({ today_total: 0, today_auto: 0, today_manual: 0 })
const activityLoading = ref(true)
const activityError = ref('')
const activityPage = ref(1)
const activityPerPage = 5

// Filter tanggal untuk feed aktivitas. '' = tampilkan semua log yang termuat.
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
  loadActivity()
})

/**
 * Map baris dari log_gate / gate_manual_control ke feed item.
 * - action 'OPEN'  → type 'in'
 * - action 'CLOSE' → type 'out'
 * - result 'SUCCESS' → granted true
 * - control_type 'auto' = RFID otomatis, 'manual' = operator
 */
function mapLog(log) {
  const isManual  = log.control_type === 'manual'
  const isOpen    = String(log.action).toUpperCase() === 'OPEN'
  const granted   = String(log.result).toUpperCase() === 'SUCCESS'
  const eventTs   = log.event_ts || log.created_at || null
  return {
    id:           log.id,
    type:         isOpen ? 'in' : 'out',
    plate:        log.nomor_plat || null,
    name:         isManual ? (log.user_name || 'Operator') : 'RFID Auto',
    gate:         log.gate_id || '',
    granted,
    isManual,
    controlType:  log.control_type || 'auto',
    action:       log.action || '',
    result:       log.result || '',
    time:         eventTs ? formatDateTime(eventTs) : '',
    rawEventTs:   eventTs,
    // raw row untuk detail modal
    raw:          log,
  }
}

async function loadActivity() {
  try {
    const params = { limit: 1000 }
    if (selectedDate.value) params.date = selectedDate.value
    const res = await gateApi.getLiveActivity(params)
    const payload = res.data?.data || res.data || []
    vehicleActivityAll.value = Array.isArray(payload) ? payload.map(mapLog) : []
    activitySummary.value = res.data?.summary || { today_total: 0, today_auto: 0, today_manual: 0 }
    activityError.value = ''
  } catch (err) {
    activityError.value = extractErrorMessage(err, 'Gagal memuat aktivitas gate.')
  } finally {
    activityLoading.value = false
  }
}

// Filter aktivitas berdasarkan tanggal (jika filter aktif)
const filteredActivity = computed(() => {
  const targetDate = selectedDate.value || todayDateStr.value
  return vehicleActivityAll.value.filter(
    (item) => item.rawEventTs && toLocalDateStr(new Date(item.rawEventTs)) === targetDate,
  )
})

// Untuk filter hari ini (digunakan oleh cards)
const todayActivity = computed(() => {
  const today = todayDateStr.value
  return vehicleActivityAll.value.filter(
    (item) => item.rawEventTs && toLocalDateStr(new Date(item.rawEventTs)) === today,
  )
})

// Counter masuk (OPEN SUCCESS) dan keluar (CLOSE SUCCESS) pada tanggal aktif
const vehicleInCount = computed(
  () => filteredActivity.value.filter((item) => item.type === 'in').length,
)

const vehicleOutCount = computed(
  () => filteredActivity.value.filter((item) => item.type === 'out').length,
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

// --- Detail Pop-up saat item aktivitas diklik --------------------------------
const activityDetailModal = ref(false)
const activityDetailItem  = ref(null)

function openActivityDetail(item) {
  activityDetailItem.value = item
  activityDetailModal.value = true
}

// --- RFID gate reader connection status -------------------------------------
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

// Aktivitas gate hari ini — didapat dari summary backend (today_total dari
// log_gate + gate_manual_control) jika tersedia, fallback ke hitung lokal.
const todayActivityCount = computed(() =>
  activitySummary.value.today_total || todayActivity.value.length
)



const kendaraanDiDalamCount = computed(() => {
  const targetDate = selectedDate.value || todayDateStr.value
  const scoped = vehicleActivityAll.value.filter(
    (item) => item.rawEventTs && toLocalDateStr(new Date(item.rawEventTs)) === targetDate,
  )
  const inCount = scoped.filter((item) => item.type === 'in').length
  const outCount = scoped.filter((item) => item.type === 'out').length
  return Math.max(inCount - outCount, 0)
})

const activityCardCount = computed(() =>
  selectedDate.value ? filteredActivity.value.length : todayActivityCount.value
)

const activityCardSubtitle = computed(() =>
  selectedDate.value
    ? `Total event gate pada ${selectedDate.value}`
    : 'Total event gate hari ini'
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
const gateSearching = ref(false)
const gateTransactionData = ref(null)
const gateImages = ref([])
const gateImageLoading = ref(false)

const imagePreviewModal = ref(false)
const previewImageUrl = ref('')

function openImagePreview(image) {
  if (image.url) {
    previewImageUrl.value = image.url
    imagePreviewModal.value = true
  } else if (image.base64) {
    previewImageUrl.value = `data:image/jpeg;base64,${image.base64}`
    imagePreviewModal.value = true
  }
}

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
    const plateInput = gateForm.value.nomor_plat.trim().toUpperCase()
    console.log('🔍 Searching for plate:', plateInput)
    const response = await transactionApi.getActiveTransaction(plateInput)

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

    // Ambil maksimal 4 gambar saja yang paling terbaru
    const finalImagePaths = imagePaths.slice(0, 4)

    console.log('📷 Total image paths to fetch:', finalImagePaths.length, finalImagePaths)

    if (finalImagePaths.length > 0) {
      gateImageLoading.value = true

      // Fetch all images in parallel
      const imagePromises = finalImagePaths.map(async (path, idx) => {
        try {
          console.log(`🌐 [${idx + 1}/${finalImagePaths.length}] Fetching image:`, path)
          const imageData = await transactionApi.fetchImage(path)
          console.log(`✅ [${idx + 1}/${finalImagePaths.length}] Image data received:`, {
            success: imageData.success,
            hasUrl: !!imageData.url,
            hasBase64: !!imageData.base64,
            base64Length: imageData.base64 ? imageData.base64.length : 0,
            urlPreview: imageData.url ? imageData.url.substring(0, 50) : null
          })
          return imageData
        } catch (err) {
          console.error(`❌ [${idx + 1}/${finalImagePaths.length}] Failed to fetch:`, path, err)
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

  // Publish gate action ke MQTT dan log dengan nomor plat + gambar
  publishGateAction(gateCamera.value.gate_id, gateAction.value === 'open', {
    nomor_plat: gateForm.value.nomor_plat,
    // Sertakan path gambar yang sudah ada di data transaksi agar tersimpan di log
    view_image_path: gateTransactionData.value?.view_image_path || null,
    entry_image_1: gateTransactionData.value?.entry_image_1 ?? gateTransactionData.value?.entry_image1 ?? null,
    entry_image_2: gateTransactionData.value?.entry_image_2 ?? gateTransactionData.value?.entry_image2 ?? null,
    entry_image_3: gateTransactionData.value?.entry_image_3 ?? gateTransactionData.value?.entry_image3 ?? null,
    entry_image_4: gateTransactionData.value?.entry_image_4 ?? gateTransactionData.value?.entry_image4 ?? null,
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

function hasImages(log) {
  return !!(log.view_image_path || log.entry_image_1 || log.entry_image_2 || log.entry_image_3 || log.entry_image_4)
}

const logImagesModal = ref(false)
const logImagesData = ref([])
const logImagesLoading = ref(false)
const logImagesError = ref('')

async function openLogImagesModal(log) {
  logImagesModal.value = true
  logImagesData.value = []
  logImagesLoading.value = true
  logImagesError.value = ''
  
  const paths = []
  if (log.view_image_path) paths.push(log.view_image_path)
  const entryImages = [
    log.entry_image_1,
    log.entry_image_2,
    log.entry_image_3,
    log.entry_image_4,
  ].filter(p => p != null && p !== '')
  paths.push(...entryImages)
  
  const uniquePaths = [...new Set(paths)].slice(0, 4)
  
  if (uniquePaths.length === 0) {
    logImagesLoading.value = false
    return
  }
  
  try {
    const promises = uniquePaths.map(async (path) => {
      try {
         return await transactionApi.fetchImage(path)
      } catch(err) {
         return { success: false, path, error: err.message }
      }
    })
    logImagesData.value = await Promise.all(promises)
  } catch (err) {
    logImagesError.value = extractErrorMessage(err, 'Gagal memuat gambar')
  } finally {
    logImagesLoading.value = false
  }
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
    label: 'Riwayat Kamera 1',
    value: gate1Total.value,
    to: null,
    color: '#10b981', // emerald
    icon: 'M23 7l-7 5 7 5V7z M3 5h11a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2H3a2 2 0 0 1 -2 -2V7a2 2 0 0 1 2 -2z',
    subtitle: 'Total Riwayat Gate Kamera 1',
    onClick: () => { if (cameras.value[0]) openDetailModal(cameras.value[0]) },
  },
  {
    label: 'Riwayat Kamera 2',
    value: gate2Total.value,
    to: null,
    color: '#f59e0b', // amber
    icon: 'M23 7l-7 5 7 5V7z M3 5h11a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2H3a2 2 0 0 1 -2 -2V7a2 2 0 0 1 2 -2z',
    subtitle: 'Total Riwayat Gate Kamera 2',
    onClick: () => { if (cameras.value[1]) openDetailModal(cameras.value[1]) },
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
<!--      <button-->
<!--          type="button"-->
<!--          class="mqtt-chip"-->
<!--          :class="mqttConnected ? 'is-online' : 'is-offline'"-->
<!--          :title="mqttConnected ? `Terhubung ke ${RFID_STATUS_TOPIC}` : 'MQTT tidak terhubung'"-->
<!--          @click="mqttDetailModal = true"-->
<!--      >-->
<!--        <span class="mqtt-chip-icon">-->
<!--          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">-->
<!--            <rect x="2" y="4" width="20" height="16" rx="2" />-->
<!--            <path d="M7 15h.01M11 15h2" />-->
<!--          </svg>-->
<!--        </span>-->
<!--        <span class="mqtt-chip-text">-->
<!--          <span class="mqtt-chip-label">RFID Reader</span>-->
<!--          <span class="mqtt-chip-state">{{ mqttConnected ? 'Online' : 'Offline' }}</span>-->
<!--        </span>-->
<!--        <span class="status-dot"></span>-->
<!--      </button>-->
<!--      </button>-->
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
            @click="card.onClick ? card.onClick() : null"
            :style="card.onClick ? 'cursor: pointer;' : ''"
        >
          <div class="stat-icon" :style="{ background: card.color }">
            <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path :d="card.icon" />
            </svg>
          </div>
          <div class="stat-meta">
            <span class="stat-value">{{ card.raw ? card.value : formatNumber(card.value) }}</span>
            <span class="stat-label">{{ card.label }}</span>
            <span v-if="card.subtitle" class="stat-subtitle">{{ card.subtitle }}</span>
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

        <!-- Live Aktivitas Kendaraan (MQTT) -->
        <div class="card live-activity">
          <div class="card-header card-header-flex">
            <span class="card-header-title">
              <svg class="card-header-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 12h-4l-3 9L9 3l-3 9H2" />
              </svg>
              Live Aktivitas Kendaraan
            </span>
            <span class="live-indicator" :class="{ 'is-offline': !mqttConnected }">
              <span class="live-pulse"></span>
              {{ mqttConnected ? 'Live' : 'Offline' }}
            </span>
          </div>

          <!-- MQTT Event Counter Summary -->
          <div class="mqtt-summary">
            <div class="mqtt-sum-item cat-scan">
              <span class="mqtt-sum-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2h12a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zM9 7h6M9 11h6M9 15h4" /></svg>
              </span>
              <span class="mqtt-sum-val">{{ mqttEventCounts.SCAN }}</span>
              <span class="mqtt-sum-lbl">SCAN</span>
            </div>
            <div class="mqtt-sum-divider"></div>
            <div class="mqtt-sum-item cat-gate">
              <span class="mqtt-sum-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" /></svg>
              </span>
              <span class="mqtt-sum-val">{{ mqttEventCounts.GATE }}</span>
              <span class="mqtt-sum-lbl">GATE</span>
            </div>
            <div class="mqtt-sum-divider"></div>
            <div class="mqtt-sum-item cat-vehicle">
              <span class="mqtt-sum-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l1.4-4.2A2 2 0 0 1 8.3 7.4h7.4a2 2 0 0 1 1.9 1.4L19 13M5 13a2 2 0 0 0-2 2v3.5a1 1 0 0 0 1 1h1.2M5 13h14M19 13a2 2 0 0 1 2 2v3.5a1 1 0 0 1-1 1h-1.2M7.5 19.5a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3zM16.5 19.5a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3z" /></svg>
              </span>
              <span class="mqtt-sum-val">{{ mqttEventCounts.VEHICLE }}</span>
              <span class="mqtt-sum-lbl">VEHICLE</span>
            </div>
          </div>

          <!-- Category Filter Tabs -->
          <div class="mqtt-tabs">
            <button
              v-for="cat in mqttEventCategories"
              :key="cat"
              type="button"
              class="mqtt-tab"
              :class="{ 'is-active': mqttEventFilter === cat, [`tab-${cat.toLowerCase()}`]: true }"
              @click="mqttEventFilter = cat"
            >
              {{ cat === 'ALL' ? 'Semua' : cat }}
              <span v-if="cat !== 'ALL'" class="mqtt-tab-badge">{{ mqttEventCounts[cat] }}</span>
            </button>
            
          </div>

          <!-- MQTT Event Feed -->
          <div class="activity-feed">
            <!-- Offline state -->
            <div v-if="!mqttConnected && mqttEvents.length === 0" class="mqtt-offline-state">
              <div class="mqtt-offline-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M1 6l11 11M5 3a16 16 0 0 1 16 16M5 9a10 10 0 0 1 10 10M5 15a4 4 0 0 1 4 4" />
                </svg>
              </div>
              <span class="mqtt-offline-title">MQTT tidak terhubung</span>
              <span class="mqtt-offline-sub">Topic: <code>{{ GATE_EVENT_TOPIC }}</code></span>
              <span v-if="mqttError" class="mqtt-offline-err">{{ mqttError?.message || mqttError }}</span>
            </div>

            <!-- Empty (connected but no events yet) -->
            <div v-else-if="filteredMqttEvents.length === 0" class="activity-empty">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="width:32px;height:32px;opacity:0.35;margin-bottom:6px;">
                <path d="M22 12h-4l-3 9L9 3l-3 9H2" />
              </svg>
              <span>{{ mqttConnected ? 'Menunggu event dari MQTT...' : 'Belum ada event' }}</span>
            </div>

            <!-- Event list -->
            <TransitionGroup v-else name="activity" tag="div">
              <div
                v-for="evt in filteredMqttEvents"
                :key="evt.id"
                class="mqtt-event-item"
                :class="mqttEventMeta(evt).colorClass"
              >
                <!-- Category icon -->
                <span class="mqtt-event-cat-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path :d="mqttEventMeta(evt).icon" />
                  </svg>
                </span>

                <!-- Body: 2 rows -->
                <div class="mqtt-event-body">
                  <!-- Row 1: Gate ID + timestamp -->
                  <div class="mqtt-event-row1">
                    <span class="mqtt-event-gate">{{ evt.gate_id }}</span>
                    <span class="mqtt-event-time">{{ evt.timestamp?.split(' ')[1]?.slice(0,8) || '' }}</span>
                  </div>
                  <!-- Row 2: detail + badges -->
                  <div class="mqtt-event-row2">
                    <span class="mqtt-event-detail" :title="mqttEventMeta(evt).detailFull">
                      {{ mqttEventMeta(evt).detail }}
                    </span>
                    <div class="mqtt-event-badges">
                      <span class="mqtt-event-cat-label">{{ evt.category }}</span>
                      <span class="badge mqtt-event-status" :class="mqttEventMeta(evt).statusClass">
                        {{ evt.status }}
                      </span>
                    </div>
                  </div>
                </div>
              </div>
            </TransitionGroup>
          </div>


        </div>

      </div>

      <!-- Device Status Widget (MQTT get/+/status) -->
      <div class="card" style="margin-top: 24px;">
        <DeviceStatusWidget
          :devices="deviceStatusList"
          :mqtt-connected="mqttConnected"
        />
      </div>

      <!-- Live Kontrol Manual Gate Full Width Data Table -->
      <!-- <div class="card" style="margin-top: 24px;">
        <div class="card-header card-header-flex">
          <span class="card-header-title">
            <svg class="card-header-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
              <path d="M7 11V7a5 5 0 0 1 10 0v4" />
            </svg>
            Live Gate Manual Control
          </span>
          <span class="live-indicator"><span class="live-pulse"></span>Live</span>
        </div>
        
        <div class="card-body" style="padding: 0; overflow-x: auto; max-height: 400px; overflow-y: auto;">
          <table class="detail-table" style="width: 100%; min-width: 800px;">
            <thead style="position: sticky; top: 0; background: var(--color-bg-card, #fff); z-index: 1; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
              <tr>
                <th style="padding-left: 24px;">Waktu</th>
                <th>Gate ID</th>
                <th>Nomor Plat</th>
                <th>Pengguna</th>
                <th>Aksi</th>
                <th>Hasil</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="logManualLoading && !logManualActivityAll.length">
                <td colspan="6" class="detail-empty" style="text-align: center;"><span class="spinner"></span> Memuat Log Manual Control...</td>
              </tr>
              <tr v-else-if="logManualError && !logManualActivityAll.length">
                <td colspan="6" class="detail-empty text-danger" style="text-align: center;">{{ logManualError }}</td>
              </tr>
              <tr v-else-if="!logManualActivityAll.length">
                <td colspan="6" class="detail-empty" style="text-align: center;">Belum ada data Log Manual Control</td>
              </tr>
              <tr v-else v-for="log in logManualActivityAll" :key="log.id">
                <td class="detail-time" style="padding-left: 24px;">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 14px; height: 14px; margin-right: 4px; vertical-align: text-bottom; opacity: 0.7;">
                    <circle cx="12" cy="12" r="10" /><polyline points="12 6 12 12 16 14" />
                  </svg>
                  {{ log.event_ts ? formatDateTime(log.event_ts) : '-' }}
                </td>
                <td class="detail-gate-id">
                  <strong>{{ log.gate_id || 'Unknown Gate' }}</strong>
                </td>
                <td>
                  <span class="badge badge-secondary">{{ log.nomor_plat || '-' }}</span>
                </td>
                <td>
                  {{ log.user_name || '-' }}
                </td>
                <td>
                  <span class="badge" :class="log.action === 'OPEN' ? 'badge-success' : 'badge-info'">
                    <svg v-if="log.action === 'OPEN'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 12px; height: 12px; margin-right: 4px; display: inline-block;"><path d="M5 21V7l7-4 7 4v14M9 21v-6h6v6" /></svg>
                    {{ log.action }}
                  </span>
                </td>
                <td>
                  <span class="badge" :class="log.result === 'SUCCESS' ? 'badge-success' : (log.result === 'ERROR' ? 'badge-danger' : 'badge-warning')">
                    {{ log.result }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div> -->

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
                     style="width: 100%; height: 100%; object-fit: cover; cursor: pointer;"
                     @click="openImagePreview(image)"
                />
                <!-- Display image from base64 (add data URI prefix) -->
                <img v-else-if="image.base64"
                     :src="`data:image/jpeg;base64,${image.base64}`"
                     :alt="`Entry Image ${index + 1}`"
                     @error="handleImageError"
                     @load="() => console.log('Base64 image loaded:', index)"
                     style="width: 100%; height: 100%; object-fit: cover; cursor: pointer;"
                     @click="openImagePreview(image)"
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
              <th style="width: 80px; text-align: center;">Gambar</th>
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
              <td style="text-align: center;">
                <Button size="sm" variant="secondary" @click="openLogImagesModal(log)" :disabled="!hasImages(log)" style="padding: 4px 8px;" :title="hasImages(log) ? 'Lihat Gambar' : 'Tidak ada gambar'">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;">
                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                    <circle cx="8.5" cy="8.5" r="1.5"></circle>
                    <polyline points="21 15 16 10 5 21"></polyline>
                  </svg>
                </Button>
              </td>
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

      <!-- Modal: Detail Aktivitas Gate (log_gate / gate_manual_control) -->
      <Modal
          v-model="activityDetailModal"
          :title="activityDetailItem ? `Detail Aktivitas · ${activityDetailItem.gate}` : 'Detail Aktivitas'"
      >
        <div v-if="activityDetailItem" class="gate-activity-detail">
          <!-- Hero: status besar -->
          <div
              class="activity-detail-hero"
              :class="activityDetailItem.granted ? 'is-success' : 'is-danger'"
          >
            <div class="activity-detail-hero-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path v-if="activityDetailItem.type === 'in'" d="M5 12h14M13 6l6 6-6 6" />
                <path v-else d="M19 12H5M11 18l-6-6 6-6" />
              </svg>
            </div>
            <div class="activity-detail-hero-text">
              <span class="activity-detail-hero-label">
                {{ activityDetailItem.type === 'in' ? 'Kendaraan Masuk' : 'Kendaraan Keluar' }}
              </span>
              <span class="activity-detail-hero-gate">{{ activityDetailItem.gate || '—' }}</span>
            </div>
            <span class="badge activity-detail-hero-badge" :class="activityDetailItem.granted ? 'badge-success' : 'badge-danger'">
              {{ activityDetailItem.result }}
            </span>
          </div>

          <!-- Grid info -->
          <div class="activity-detail-grid">
            <div class="activity-detail-item">
              <span class="activity-detail-label">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;display:inline-block;vertical-align:-2px;margin-right:4px;">
                  <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                </svg>
                Waktu
              </span>
              <span class="activity-detail-value">{{ activityDetailItem.time || '—' }}</span>
            </div>

            <div class="activity-detail-item">
              <span class="activity-detail-label">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;display:inline-block;vertical-align:-2px;margin-right:4px;">
                  <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                </svg>
                Gate ID
              </span>
              <span class="activity-detail-value">{{ activityDetailItem.gate || '—' }}</span>
            </div>

            <div class="activity-detail-item">
              <span class="activity-detail-label">Aksi</span>
              <span class="activity-detail-value">
                <span class="badge" :class="activityDetailItem.action === 'OPEN' ? 'badge-success' : 'badge-info'">
                  {{ activityDetailItem.action || '—' }}
                </span>
              </span>
            </div>

            <div class="activity-detail-item">
              <span class="activity-detail-label">Hasil</span>
              <span class="activity-detail-value">
                <span class="badge" :class="activityDetailItem.granted ? 'badge-success' : 'badge-danger'">
                  {{ activityDetailItem.result || '—' }}
                </span>
              </span>
            </div>

            <div class="activity-detail-item">
              <span class="activity-detail-label">Tipe Kontrol</span>
              <span class="activity-detail-value">
                <span class="badge" :class="activityDetailItem.isManual ? 'badge-warning' : 'badge-muted'">
                  {{ activityDetailItem.isManual ? 'Manual Operator' : 'RFID Otomatis' }}
                </span>
              </span>
            </div>

            <div class="activity-detail-item" v-if="activityDetailItem.plate">
              <span class="activity-detail-label">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;display:inline-block;vertical-align:-2px;margin-right:4px;">
                  <rect x="1" y="6" width="22" height="12" rx="2"/><path d="M5 10h.01M19 10h.01M9 14h6"/>
                </svg>
                Nomor Plat
              </span>
              <span class="activity-detail-value activity-detail-plate">{{ activityDetailItem.plate }}</span>
            </div>

            <div class="activity-detail-item" v-if="activityDetailItem.isManual && activityDetailItem.name">
              <span class="activity-detail-label">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;display:inline-block;vertical-align:-2px;margin-right:4px;">
                  <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                </svg>
                Operator
              </span>
              <span class="activity-detail-value">{{ activityDetailItem.name }}</span>
            </div>
          </div>
        </div>

        <template #footer>
          <Button variant="secondary" type="button" @click="activityDetailModal = false">Tutup</Button>
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

      <!-- Modal: Gambar Riwayat Gate -->
      <Modal v-model="logImagesModal" title="Gambar Riwayat Gate">
        <div v-if="logImagesLoading" class="gate-images-loading">
          <span class="spinner"></span>
          <span>Memuat gambar...</span>
        </div>
        <div v-else-if="logImagesError" class="alert alert-danger">{{ logImagesError }}</div>
        <div v-else-if="logImagesData.length > 0" class="gate-images">
          <div class="images-grid">
            <div v-for="(image, index) in logImagesData" :key="index" class="image-item">
              <img v-if="image.url"
                   :src="image.url"
                   :alt="`Entry Image ${index + 1}`"
                   @error="handleImageError"
                   style="width: 100%; height: 100%; object-fit: cover; cursor: pointer;"
                   @click="openImagePreview(image)"
              />
              <img v-else-if="image.base64"
                   :src="`data:image/jpeg;base64,${image.base64}`"
                   :alt="`Entry Image ${index + 1}`"
                   @error="handleImageError"
                   style="width: 100%; height: 100%; object-fit: cover; cursor: pointer;"
                   @click="openImagePreview(image)"
              />
              <div v-else class="image-placeholder">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 32px; height: 32px; margin-bottom: 8px; opacity: 0.5;">
                  <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                  <circle cx="8.5" cy="8.5" r="1.5"></circle>
                  <polyline points="21 15 16 10 5 21"></polyline>
                </svg>
                <div style="font-size: 11px; color: var(--color-text-muted);">
                  {{ image.success === false ? 'Gagal memuat' : 'Tidak tersedia' }}
                </div>
              </div>
            </div>
          </div>
        </div>
        <div v-else class="gate-images-empty">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="width: 24px; height: 24px; opacity: 0.5;">
            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
            <circle cx="8.5" cy="8.5" r="1.5"></circle>
            <polyline points="21 15 16 10 5 21"></polyline>
          </svg>
          <span>Tidak ada gambar tersedia</span>
        </div>
        <template #footer>
          <Button variant="secondary" type="button" @click="logImagesModal = false">Tutup</Button>
        </template>
      </Modal>

      <!-- Modal: Image Preview -->
      <Modal v-model="imagePreviewModal" title="Preview Gambar" style="z-index: 1060;">
        <div style="display: flex; justify-content: center; align-items: center; max-height: 70vh;">
          <img :src="previewImageUrl" style="max-width: 100%; max-height: 100%; object-fit: contain;" alt="Preview" />
        </div>
        <template #footer>
          <Button variant="secondary" type="button" @click="imagePreviewModal = false">Tutup</Button>
        </template>
      </Modal>
    </template>
  </div>
</template>

<style scoped src="./Dashboard.css"></style>
