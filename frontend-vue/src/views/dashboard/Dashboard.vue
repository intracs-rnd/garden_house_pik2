<script setup>
import { onMounted, onUnmounted, ref, computed, watch, reactive } from 'vue'
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
const gateTotal = ref(0)


// MQTT untuk RFID Status
const RFID_STATUS_TOPIC = 'gate/in/rfid_status'
const GATE_EVENT_TOPIC     = 'gate/in/event'
const GATE_OUT_EVENT_TOPIC = 'gate/out/event'

// MQTT untuk Device Status (wildcard gate/+/status)
const DEVICE_STATUS_TOPIC = 'gate/+/status'

/**
 * Menyimpan status tiap device yang diterima dari topic gate/{device}/status.
 * Map: device → { device, status, receivedAt }
 * Disimpan sebagai Map agar update per-device tidak duplikat.
 */
const deviceStatusMap = ref(new Map())
const deviceStatusList = computed(() =>
    Array.from(deviceStatusMap.value.values()).sort((a, b) => {
      const devA = a.device || ''
      const devB = b.device || ''
      return devA.localeCompare(devB)
    })
)

// --- localStorage persistence untuk Status Device ---
const LS_DEVICE_STATUS_KEY = 'gh_pik2_device_status'

/** Muat status device dari localStorage saat halaman pertama dibuka */
function loadDeviceStatusFromStorage() {
  try {
    const raw = localStorage.getItem(LS_DEVICE_STATUS_KEY)
    if (!raw) return

    const saved = JSON.parse(raw)
    if (!Array.isArray(saved) || saved.length === 0) return

    // migrate data lama (nama_device -> device)
    const migrated = saved.map(([k, v]) => {
      if (v && v.nama_device && !v.device) {
        v.device = v.nama_device
        delete v.nama_device
      }
      return [k, v]
    })
    deviceStatusMap.value = new Map(migrated)
    console.log(`[MQTT] ${saved.length} status device dimuat dari localStorage.`)
  } catch (e) {
    console.warn('[MQTT] Gagal memuat status device dari localStorage:', e)
  }
}

/** Simpan status device ke localStorage (dipanggil tiap kali ada update) */
function saveDeviceStatusToStorage() {
  try {
    // Map tidak bisa langsung di-JSON.stringify, ubah ke array of entries dulu
    const entries = Array.from(deviceStatusMap.value.entries())
    localStorage.setItem(LS_DEVICE_STATUS_KEY, JSON.stringify(entries))
  } catch (e) {
    console.warn('[MQTT] Gagal menyimpan status device ke localStorage:', e)
  }
}

/** Hapus semua status device (dipanggil dari tombol Clear Data di widget) */
function clearDeviceStatus() {
  deviceStatusMap.value = new Map()
  try {
    localStorage.removeItem(LS_DEVICE_STATUS_KEY)
  } catch (e) {
    console.warn('[MQTT] Gagal menghapus status device dari localStorage:', e)
  }
}

// Muat data tersimpan segera saat komponen diinisialisasi
loadDeviceStatusFromStorage()


/**
 * Handler untuk topic gate/+/status.
 * Menerima JSON {device, status} dan update deviceStatusMap.
 */
function handleDeviceStatus(message, receivedTopic) {
  try {
    let device = ''
    let status = ''

    if (typeof message === 'object' && message !== null && message.device) {
      // Pesan sudah di-parse JSON oleh mqtt.js service
      device = message.device
      status = (message.status || '').toLowerCase()
    } else {
      // Fallback: ambil nama device dari URL topic
      const parts = (receivedTopic || '').split('/')
      device = parts[1] || 'unknown'
      status = typeof message === 'string' ? message.toLowerCase() : String(message).toLowerCase()
    }

    if (!device) return

    // Update atau tambahkan entry di Map
    deviceStatusMap.value = new Map(deviceStatusMap.value)
    deviceStatusMap.value.set(device, {
      device,
      status,
      receivedAt: new Date().toISOString(),
    })
    // simpan ke localStorage supaya tidak hilang saat reload
    saveDeviceStatusToStorage()
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
const { isConnected, error: mqttErr, connect: mqttConnect, subscribe: mqttSubscribe, unsubscribe: mqttUnsubscribe, disconnect: mqttDisconnect } = useMqtt(null, { autoConnect: false })

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
      await mqttSubscribe(GATE_OUT_EVENT_TOPIC, handleGateEvent, { qos: 0 })
      // Subscribe device status wildcard gate/+/status (QoS 1)
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
  // { id: 3, name: 'Kamera 3', enabled: true, src: import.meta.env.VITE_STREAM_URL_3 || 'http://localhost:1984/api/ws?src=cam3', gate_id: 'GATE_OUT_01' },
  // { id: 4, name: 'Kamera 4', enabled: true, src: import.meta.env.VITE_STREAM_URL_4 || 'http://localhost:1984/api/ws?src=cam4', gate_id: 'GATE_OUT_02' },
]

const cameras = ref(defaultCameras)

async function loadCameras() {
  try {
    const res = await cameraApi.getFeeds()
    const feeds = res.data?.cameras || []
    if (feeds.length) {
      // Kamera 3 dan 4 sementara tidak digunakan — batasi ke 2 kamera pertama
      cameras.value = feeds.slice(0, 2).map((c, i) => {
        const gateIds = ['GATE_IN_01', 'GATE_IN_02', 'GATE_OUT_01', 'GATE_OUT_02']
        return {
          id: i + 1,
          name: c.name,
          src: c.stream_url,
          enabled: c.enabled,
          gate_id: gateIds[i] || `GATE_${i + 1}`,
        }
      })
      // Kalau gate_id dari API beda dengan default, refresh totalnya
      loadGateTotals()
    }
  } catch {
    // Keep the .env fallbacks if the feed endpoint is unavailable.
  }
}

async function loadGateTotals() {
  try {
    const res = await gateApi.getManualControlTotal()
    gateTotal.value = res.data?.total ?? 0
  } catch {
    gateTotal.value = 0
  }
}

// --- CCTV Snapshot Slideshow (menggantikan Live Stream di dashboard) ---------
// Ambil 8 foto terbaru dari log_cctv, tampilkan sebagai slideshow dengan
// crossfade + Ken-Burns zoom, ganti slide otomatis tiap 4 detik.
const CCTV_SNAP_REFRESH_MS = 30_000
const CCTV_SLIDE_INTERVAL_MS = 4_000

const cctvSlides = ref([])       // [{ id, cctv, view_image_path, log_time, imageUrl, loading, error }]
const cctvRefreshing = ref(false)
const cctvLastRefreshed = ref(null)
const cctvActiveSlide = ref(0)   // index slide yang sedang tampil
const cctvSlidePrev = ref(-1)    // index slide sebelumnya (untuk crossfade out)
let cctvSnapshotTimer = null
let cctvSlideTimer = null

const uploadsApiUrl = (import.meta.env.VITE_UPLOADS_API_URL || 'http://192.168.214.163:4000/api/uploads').replace(/\/+$/, '')

async function fetchSlideImage(slide) {
  slide.loading = true
  slide.error = false
  try {
    const res = await fetch(uploadsApiUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ path: slide.view_image_path }),
    })
    if (!res.ok) throw new Error(`HTTP ${res.status}`)
    const blob = await res.blob()
    if (slide.imageUrl) URL.revokeObjectURL(slide.imageUrl)
    slide.imageUrl = URL.createObjectURL(blob)
    slide.loading = false
  } catch (err) {
    console.warn('[CCTV Slideshow] Gagal fetch gambar:', slide.cctv, err.message)
    slide.loading = false
    slide.error = true
  }
}

async function loadCctvSnapshots() {
  cctvRefreshing.value = true
  try {
    const res = await transactionApi.getLatestCctvSnapshots()
    const records = res.data || []

    // Build a map of existing slides keyed by view_image_path so we can reuse
    // already-fetched blob URLs and avoid re-downloading unchanged images.
    const existingMap = new Map(cctvSlides.value.map(s => [s.view_image_path, s]))

    const nextSlides = records.map(rec => {
      if (existingMap.has(rec.view_image_path)) {
        const existing = existingMap.get(rec.view_image_path)
        // Retry fetch if previous attempt failed
        if (existing.error) fetchSlideImage(existing)
        return existing
      }
      // New photo — create slide and fetch image
      const slide = reactive({
        id: rec.id,
        cctv: rec.cctv,
        view_image_path: rec.view_image_path,
        log_time: rec.log_time,
        imageUrl: null,
        loading: true,
        error: false,
      })
      fetchSlideImage(slide)
      return slide
    })

    // Revoke blob URLs for slides that are no longer in the latest set
    const nextPaths = new Set(nextSlides.map(s => s.view_image_path))
    cctvSlides.value.forEach(s => {
      if (!nextPaths.has(s.view_image_path) && s.imageUrl) {
        URL.revokeObjectURL(s.imageUrl)
      }
    })

    cctvSlides.value = nextSlides

    // Reset index bila out-of-bounds
    if (cctvActiveSlide.value >= cctvSlides.value.length) {
      cctvActiveSlide.value = 0
      cctvSlidePrev.value = -1
    }
    cctvLastRefreshed.value = new Date()
  } catch (err) {
    console.warn('[CCTV Slideshow] Gagal memuat daftar foto:', err.message)
  } finally {
    cctvRefreshing.value = false
  }
}

function advanceSlide() {
  if (cctvSlides.value.length < 2) return
  cctvSlidePrev.value = cctvActiveSlide.value
  cctvActiveSlide.value = (cctvActiveSlide.value + 1) % cctvSlides.value.length
}

function goToSlide(idx) {
  if (idx === cctvActiveSlide.value) return
  cctvSlidePrev.value = cctvActiveSlide.value
  cctvActiveSlide.value = idx
  // Reset auto-advance timer agar tidak lompat terlalu cepat
  if (cctvSlideTimer) {
    clearInterval(cctvSlideTimer)
    cctvSlideTimer = setInterval(advanceSlide, CCTV_SLIDE_INTERVAL_MS)
  }
}

function startCctvSnapshots() {
  loadCctvSnapshots()
  cctvSnapshotTimer = setInterval(loadCctvSnapshots, CCTV_SNAP_REFRESH_MS)
  cctvSlideTimer = setInterval(advanceSlide, CCTV_SLIDE_INTERVAL_MS)
}

function stopCctvSnapshots() {
  if (cctvSnapshotTimer) { clearInterval(cctvSnapshotTimer); cctvSnapshotTimer = null }
  if (cctvSlideTimer)    { clearInterval(cctvSlideTimer);    cctvSlideTimer    = null }
  cctvSlides.value.forEach(s => { if (s.imageUrl) URL.revokeObjectURL(s.imageUrl) })
}

// Kamera pertama saja yang dipakai untuk kontrol gate
const cam1 = computed(() => cameras.value[0] || null)

// --- Live Kendaraan In/Out (dari log_gate + gate_manual_control via MQTT) ---
// Polling dari /api/gate/live-activity yang menggabungkan event RFID otomatis
// (log_gate) dan kontrol manual operator (gate_manual_control).
const auth = useAuthStore()

const canControlGate = computed(() => auth.canManage('dashboard') || auth.canManage('kartu_gate'))

const ACTIVITY_POLL_MS = 15000

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
    // Always filter by the active date (today when none selected) so the server
    // returns only the relevant day's rows — avoids loading 1000 records from all
    // dates and filtering client-side.
    const targetDate = selectedDate.value || todayDateStr.value
    const params = { limit: 200, date: targetDate }
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
const gateCaptureValidated = ref(false)
const gateCaptureLoading = ref(false)
const gateCaptureImages = ref([]) // gambar hasil capture dari CCTV API

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
// 1 -> Cari Kendaraan, 2 -> Validasi CCTV, 3 -> Konfirmasi Buka Gate
const gateModalTitle = computed(() => {
  const camPart = gateCamera.value ? ' · ' + gateCamera.value.name : ''
  if (!gateTransactionData.value) return `Cari Kendaraan${camPart}`
  if (!gateCaptureValidated.value) return `Validasi CCTV${camPart}`
  return `Konfirmasi Buka Gate${camPart}`
})

function isGateReaderOnline(cam) {
  // Find the gate associated with this camera and check if reader is online
  const gateId = cam.gate_id || cam.id // Fallback ke cam.id jika gate_id tidak ada
  const gate = rfidGates.value.find(g => g.gate_id === gateId || g.id === gateId)
  return gate ? gate.is_online : true // Default true jika gate tidak ditemukan
}

function isOutGate(cam) {
  return (cam?.gate_id || '').toUpperCase().includes('OUT')
}

function openGateModal(cam) {
  if (!canControlGate.value) return
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
  gateCaptureValidated.value = false
  gateCaptureImages.value = []
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

    // Collect image paths with labels from the backend-resolved list.
    // Each item: { path, label, source: 'MR'|'CCTV'|'ANPR', direction: 'entry'|'exit' }
    let imagePaths = []

    if (Array.isArray(response.data.resolved_images) && response.data.resolved_images.length) {
      imagePaths = response.data.resolved_images
          .filter(item => item && item.path)
          .map(item => ({
            path: item.path,
            label: item.label,
            source: item.source || 'MR',
            direction: item.direction || 'entry',
          }))
    } else {
      // Fallback: legacy flat fields
      if (response.data.view_image_path) {
        imagePaths.push({ path: response.data.view_image_path, label: 'CCTV (Masuk)', source: 'CCTV', direction: 'entry' })
      }
      if (response.data.exit_view_image_path) {
        imagePaths.push({ path: response.data.exit_view_image_path, label: 'CCTV (Keluar)', source: 'CCTV', direction: 'exit' })
      }
    }

    // Deduplicate by path while keeping the first (most specific) label.
    const seenPaths = new Set()
    const finalImagePaths = imagePaths.filter(item => {
      if (seenPaths.has(item.path)) return false
      seenPaths.add(item.path)
      return true
    })

    if (finalImagePaths.length > 0) {
      gateImageLoading.value = true

      // Fetch all images in parallel
      const imagePromises = finalImagePaths.map(async (item) => {
        try {
          const imageData = await transactionApi.fetchImage(item.path)
          return { ...imageData, label: item.label, source: item.source, direction: item.direction }
        } catch (err) {
          return { success: false, path: item.path, label: item.label, source: item.source, direction: item.direction, error: err.message }
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

  const viewPath = gateImages.value.find(img => img.source === 'CCTV')?.path || null
  const anprPaths = gateImages.value.filter(img => img.source === 'ANPR' || img.source === 'MR').map(img => img.path)

  // Publish gate action ke MQTT dan log dengan nomor plat + gambar
  publishGateAction('VISITOR_OUT', gateAction.value === 'open', {
    log_gate_id: gateCamera.value.gate_id, // disimpan ke DB untuk filter per gate
    nomor_plat: gateForm.value.nomor_plat,
    // Simpan kode transaksi agar Riwayat Gate bisa resolve ulang seluruh gambar (MR/CCTV/ANPR)
    code_transaction: gateTransactionData.value?.code_transaction || null,
    // Masukkan gambar CCTV ke view_image_path, sisanya (baik entry maupun exit) ke slot entry_image_1 s/d 4
    view_image_path: viewPath,
    entry_image_1: anprPaths[0] || null,
    entry_image_2: anprPaths[1] || null,
    entry_image_3: anprPaths[2] || null,
    entry_image_4: anprPaths[3] || null,
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

// --- Capture dari CCTV sebelum Buka Gate ---
async function captureFromCctv() {
  if (!gateCamera.value) return

  const device = 'DASHBOARD-OUT'

  gateCaptureLoading.value = true
  try {
    const response = await transactionApi.cctvCapture(device)
    const data = response
    console.log('📷 CCTV capture response:', data)

    const captured = []

    // Format: { anpr: "base64...", view: "base64..." }
    if (data.anpr) {
      captured.push({ base64: data.anpr, label: 'ANPR', source: 'ANPR', success: true })
    }
    if (data.view) {
      captured.push({ base64: data.view, label: 'View', source: 'CCTV', success: true })
    }

    // Format: { data: { anpr: "...", view: "..." } }
    if (data.data?.anpr) {
      captured.push({ base64: data.data.anpr, label: 'ANPR', source: 'ANPR', success: true })
    }
    if (data.data?.view) {
      captured.push({ base64: data.data.view, label: 'View', source: 'CCTV', success: true })
    }

    // Format: array images
    if (Array.isArray(data.images)) {
      data.images.forEach(img => {
        captured.push({
          base64: img.base64 || null,
          url: img.url || null,
          label: img.label || img.type || 'Capture',
          source: img.type || 'CCTV',
          success: true,
        })
      })
    }

    gateCaptureImages.value = captured
    // Juga tambahkan ke gateImages direction exit untuk disimpan ke log gate
    const newImages = captured.map(img => ({ ...img, direction: 'exit' }))
    if (newImages.length > 0) {
      gateImages.value = [...gateImages.value, ...newImages]
    }

    // Set flags=1 pada log_cctv record terbaru yang baru di-insert Node-RED
    try {
      await transactionApi.setLogCctvFlags()
      console.log('✅ log_cctv flags=1 set on latest record')
    } catch (flagErr) {
      console.warn('⚠️ Gagal set flags log_cctv:', flagErr.message)
    }

    gateCaptureValidated.value = true
    toastSuccess('Capture CCTV berhasil, silakan konfirmasi untuk membuka gate')
  } catch (err) {
    console.error('❌ Capture CCTV error:', err)
    toastError(`Gagal capture dari CCTV: ${err.message}`)
  } finally {
    gateCaptureLoading.value = false
  }
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

const detailGateFilterDate = ref('')
const detailGateFilterPlate = ref('')

async function loadDetailGateLogs(page = 1) {
  detailGateLoading.value = true
  detailGateError.value = ''

  try {
    const params = {
      page,
      per_page: detailGatePerPage,
    }
    
    if (detailGateFilterDate.value) params.date = detailGateFilterDate.value
    if (detailGateFilterPlate.value) params.nomor_plat = detailGateFilterPlate.value

    const res = await gateApi.getLogsByGateId(detailCamera.value.gate_id, params)
    detailGateLogs.value = res.data?.logs || []
    detailGatePagination.value = res.data?.pagination || {}
    detailGatePage.value = page
  } catch (err) {
    detailGateError.value = extractErrorMessage(err, 'Gagal memuat riwayat gate.')
  } finally {
    detailGateLoading.value = false
  }
}

function resetDetailGateFilter() {
  detailGateFilterDate.value = ''
  detailGateFilterPlate.value = ''
  loadDetailGateLogs(1)
}

async function openDetailModal(cam) {
  detailCamera.value = cam
  detailGateLogs.value = []
  detailGatePage.value = 1
  detailGateFilterDate.value = ''
  detailGateFilterPlate.value = ''
  detailModal.value = true
  await loadDetailGateLogs(1)
}

function hasImages(log) {
  return !!(log.code_transaction || log.view_image_path || log.entry_image_1 || log.entry_image_2 || log.entry_image_3 || log.entry_image_4)
}

const logImagesModal = ref(false)
const logImagesData = ref([])
const logImagesLoading = ref(false)
const logImagesError = ref('')
const logImagesLog = ref(null)       // log baris yang sedang dibuka
const logImagesSubmitting = ref(false)

async function openLogImagesModal(log) {
  logImagesModal.value = true
  logImagesData.value = []
  logImagesLoading.value = true
  logImagesError.value = ''
  logImagesLog.value = log
  logImagesSubmitting.value = false

  // Kumpulkan path gambar beserta label + sumber + arah.
  let pathItems = []

  // Prioritas: jika ada code_transaction, resolve ulang SELURUH gambar (MR/CCTV/ANPR,
  // masuk & keluar) langsung dari transaksi agar konsisten dengan modal konfirmasi.
  if (log.code_transaction) {
    try {
      const resp = await transactionApi.getByCode(log.code_transaction)
      const resolved = resp?.data?.resolved_images
      if (Array.isArray(resolved) && resolved.length) {
        pathItems = resolved
            .filter(item => item && item.path)
            .map(item => ({
              path: item.path,
              label: item.label,
              source: item.source || 'MR',
              direction: item.direction || 'entry',
            }))
      }
    } catch (err) {
      console.warn('⚠️ Gagal resolve gambar dari code_transaction, fallback ke data tersimpan:', err?.message)
    }
  }

  // Fallback: pakai gambar snapshot yang tersimpan di baris gate_manual_control.
  if (pathItems.length === 0) {
    if (log.view_image_path) {
      pathItems.push({ path: log.view_image_path, label: 'CCTV (Masuk)', source: 'CCTV', direction: 'entry' })
    }
    for (let i = 1; i <= 4; i++) {
      const p = log['entry_image_' + i]
      if (p != null && p !== '') {
        pathItems.push({ path: p, label: 'Gambar ' + i, source: 'MR', direction: 'entry' })
      }
    }
  }

  // Deduplikasi berdasarkan path, tanpa batasan jumlah
  const seen = new Set()
  const uniqueItems = pathItems.filter(({ path }) => {
    if (seen.has(path)) return false
    seen.add(path)
    return true
  })

  if (uniqueItems.length === 0) {
    logImagesLoading.value = false
    return
  }

  try {
    const promises = uniqueItems.map(async ({ path, label, source, direction }) => {
      try {
        const imageData = await transactionApi.fetchImage(path)
        return { ...imageData, label, source, direction }
      } catch (err) {
        return { success: false, path, label, source, direction, error: err.message }
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
    label: 'Total Riwayat Gate',
    value: gateTotal.value,
    to: { name: 'reports.index' },
    color: '#10b981',
    icon: 'M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z M9 22V12h6v10',
    subtitle: 'Total log aktivitas gate',
  },
  ...(canControlGate.value ? [{
    label: 'Kontrol Gate',
    value: null,
    to: null,
    color: '#ef4444',
    icon: 'M7 11V7a5 5 0 0 1 9.9-1 M5 11h14a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-6a2 2 0 0 1 2-2z',
    subtitle: cam1.value ? (isGateReaderOnline(cam1.value) ? 'Reader Online — Siap dikontrol' : 'Reader Offline') : 'Tekan untuk membuka',
    onClick: () => cam1.value && openGateModal(cam1.value),
    isAction: true,
  }] : []),
])

// animasi card
const animatedCardValues = ref({})

function animateCardValue(key, from, to, duration = 700) {
  if (from === to) return
  const startTime = performance.now()
  function tick(now) {
    const progress = Math.min((now - startTime) / duration, 1)
    const eased = progress === 1 ? 1 : 1 - Math.pow(2, -10 * progress)
    const current = Math.round(from + (to - from) * eased)
    animatedCardValues.value = { ...animatedCardValues.value, [key]: current }
    if (progress < 1) requestAnimationFrame(tick)
  }
  requestAnimationFrame(tick)
}

watch(
    cards,
    (newCards) => {
      newCards.forEach((card) => {
        if (!(card.label in animatedCardValues.value)) {
          animatedCardValues.value[card.label] = 0
        }
        const prev = animatedCardValues.value[card.label]
        if (prev !== card.value) {
          animateCardValue(card.label, prev, card.value)
        }
      })
    },
    { immediate: true, deep: true },
)

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
  loadGateTotals()
  startActivityFeed()
  startRfidStatus()
  startCctvSnapshots()
  initMqtt()
})

onUnmounted(() => {
  clearInterval(activityTimer)
  clearInterval(rfidTimer)
  stopCctvSnapshots()
  // Cleanup MQTT subscriptions to prevent memory leaks on component remount
  mqttUnsubscribe(RFID_STATUS_TOPIC)
  mqttUnsubscribe(GATE_EVENT_TOPIC)
  mqttUnsubscribe(GATE_OUT_EVENT_TOPIC)
  mqttUnsubscribe(DEVICE_STATUS_TOPIC)
  mqttDisconnect()
})
</script>

<template>
  <div class="page">
    <div class="dashboard-head">
      <PageHeader title="Dashboard" subtitle="Ringkasan data sistem Garden House PIK2" />
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
            :class="{ 'stat-card-action': card.isAction }"
            @click="card.onClick ? card.onClick() : null"
            :style="card.onClick ? 'cursor: pointer;' : ''"
        >
          <div class="stat-icon" :style="{ background: card.color }">
            <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path :d="card.icon" />
            </svg>
          </div>
          <div class="stat-meta">
            <span v-if="card.isAction" class="stat-value stat-value-action">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;flex-shrink:0;">
                <path d="M7 11V7a5 5 0 0 1 9.9-1"/>
                <path d="M5 11h14a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-6a2 2 0 0 1 2-2z"/>
              </svg>
              Buka Gate
            </span>
            <span v-else class="stat-value" :key="card.label + '-val'">
              {{ formatNumber(animatedCardValues[card.label] ?? 0) }}
            </span>
            <span class="stat-label">{{ card.label }}</span>
            <span v-if="card.subtitle" class="stat-subtitle">{{ card.subtitle }}</span>
            <span v-if="card.isAction" class="gate-tap-hint">Ketuk untuk membuka</span>
          </div>
          <svg v-if="card.to" class="stat-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9 18l6-6-6-6" />
          </svg>
          <svg v-else-if="card.isAction" class="stat-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
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

      <!-- Foto CCTV Terkini + Kendaraan In/Out + Status Device -->
      <div class="row live-row">
        <!-- Slideshow foto CCTV terbaru -->
        <div class="card live-cctv">
          <div class="card-header card-header-flex">
            <span class="card-header-title">
              <svg class="card-header-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                <circle cx="8.5" cy="8.5" r="1.5"/>
                <polyline points="21 15 16 10 5 21"/>
              </svg>
              Foto CCTV Terkini
            </span>
            <div class="snapshot-header-right">
              <span v-if="cctvLastRefreshed" class="stream-count">
                {{ cctvLastRefreshed.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) }}
              </span>
              <button
                class="snap-refresh-btn"
                :class="{ 'is-spinning': cctvRefreshing }"
                :disabled="cctvRefreshing"
                title="Refresh foto"
                @click="loadCctvSnapshots"
              >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                  <polyline points="23 4 23 10 17 10"/>
                  <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                </svg>
              </button>
            </div>
          </div>

          <div class="card-body snap-card-body">

            <!-- ── Slideshow ── -->
            <div class="cctv-slideshow">

              <!-- Empty / loading state (sebelum foto pertama dimuat) -->
              <div v-if="cctvSlides.length === 0" class="snap-empty">
                <span v-if="cctvRefreshing" class="snap-spinner"></span>
                <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="width:36px;height:36px;opacity:.35;">
                  <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                  <circle cx="8.5" cy="8.5" r="1.5"/>
                  <polyline points="21 15 16 10 5 21"/>
                </svg>
                <span class="snap-empty-text">{{ cctvRefreshing ? 'Memuat foto…' : 'Belum ada foto CCTV' }}</span>
              </div>

              <!-- Slide stack -->
              <template v-else>
                <div
                  v-for="(slide, idx) in cctvSlides"
                  :key="slide.id"
                  class="cctv-slide"
                  :class="{
                    'is-active':  idx === cctvActiveSlide,
                    'is-leaving': idx === cctvSlidePrev,
                  }"
                >
                  <!-- Loading skeleton -->
                  <div v-if="slide.loading" class="snap-loading">
                    <span class="snap-spinner"></span>
                  </div>
                  <!-- Error -->
                  <div v-else-if="slide.error" class="snap-error">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" style="width:28px;height:28px;opacity:.4;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                  </div>
                  <!-- Foto -->
                  <img
                    v-else-if="slide.imageUrl"
                    :src="slide.imageUrl"
                    :alt="`CCTV ${slide.cctv}`"
                    class="slide-img"
                    :class="idx === cctvActiveSlide ? 'ken-burns' : ''"
                  />
                </div>

                <!-- Overlay: kamera + waktu -->
                <div class="slide-overlay">
                  <span class="slide-cam-badge">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:11px;height:11px;flex-shrink:0;">
                      <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>
                    </svg>
                    {{ cctvSlides[cctvActiveSlide]?.cctv || 'CCTV' }}
                  </span>
                  <span v-if="cctvSlides[cctvActiveSlide]?.log_time" class="slide-time-badge">
                    {{ formatDateTime(cctvSlides[cctvActiveSlide].log_time) }}
                  </span>
                </div>

                <!-- Dot indicators -->
                <div class="slide-dots">
                  <button
                    v-for="(_, i) in cctvSlides"
                    :key="i"
                    class="slide-dot"
                    :class="{ active: i === cctvActiveSlide }"
                    @click="goToSlide(i)"
                    :aria-label="`Foto ${i + 1}`"
                  />
                </div>

                <!-- Prev / Next arrows -->
                <button class="slide-arrow slide-arrow-prev" @click="goToSlide((cctvActiveSlide - 1 + cctvSlides.length) % cctvSlides.length)" aria-label="Sebelumnya">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
                </button>
                <button class="slide-arrow slide-arrow-next" @click="goToSlide((cctvActiveSlide + 1) % cctvSlides.length)" aria-label="Selanjutnya">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                </button>
              </template>
            </div>

          </div>
        </div>

        <!-- Kolom kanan: Live Aktivitas + Status Device ditumpuk agar tidak perlu scroll halaman di desktop -->
        <div class="live-side">
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
                <span v-if="mqttError" class="mqtt-offline-err">{{ mqttError?.message || mqttError }}</span>
              </div>

              <!-- Empty (connected but no events yet) -->
              <div v-else-if="filteredMqttEvents.length === 0" class="activity-empty">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="width:32px;height:32px;opacity:0.35;margin-bottom:6px;">
                  <path d="M22 12h-4l-3 9L9 3l-3 9H2" />
                </svg>
                <span>{{ mqttConnected ? 'Menunggu event...' : 'Belum ada event' }}</span>
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

          <!-- Status Device (MQTT gate/+/status) -->
          <div class="card live-device-status">
            <DeviceStatusWidget
                :devices="deviceStatusList"
                :mqtt-connected="mqttConnected"
                @clear="clearDeviceStatus"
            />
          </div>
        </div>

      </div>

      <!-- Modal: Buka / Tutup Gate (frontend only) -->
      <Modal v-model="gateModal" :title="gateModalTitle" :size="gateTransactionData ? 'full' : 'lg'">
        <div class="gate-steps">
          <span class="gate-step" :class="{ 'is-active': !gateTransactionData, 'is-done': gateTransactionData }">
            <span class="gate-step-num">
              <svg v-if="gateTransactionData" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12" /></svg>
              <template v-else>1</template>
            </span>
            Cari Plat
          </span>
          <span class="gate-step-line" :class="{ 'is-done': gateTransactionData }"></span>
          <span class="gate-step" :class="{ 'is-active': gateTransactionData && !gateCaptureValidated, 'is-done': gateCaptureValidated }">
            <span class="gate-step-num">
              <svg v-if="gateCaptureValidated" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12" /></svg>
              <template v-else>2</template>
            </span>
            Validasi CCTV
          </span>
          <span class="gate-step-line" :class="{ 'is-done': gateCaptureValidated }"></span>
          <span class="gate-step" :class="{ 'is-active': gateCaptureValidated }">
            <span class="gate-step-num">3</span>
            Buka Gate
          </span>
        </div>
        <p class="gate-hint">
          {{ !gateTransactionData
            ? 'Masukkan nomor plat kendaraan dan klik Cari untuk validasi.'
            : !gateCaptureValidated
              ? 'Data transaksi ditemukan. Klik "Validasi" untuk mengambil gambar CCTV terkini sebelum membuka gate.'
              : 'Gambar CCTV berhasil diambil. Periksa detail di bawah, lalu klik "Buka Gate" untuk membuka.' }}
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
                  @click="gateTransactionData = null; gateImages = []; gateForm.nomor_plat = ''; gateCaptureValidated = false; gateCaptureImages = []"
                  style="white-space: nowrap;"
              >
                Reset
              </Button>
            </div>
            <span v-if="gateErrors.nomor_plat" class="form-error">{{ gateErrors.nomor_plat }}</span>
          </div>
        </form>

        <!-- Empty / Searching state (sebelum data transaksi ditemukan) -->
        <div v-if="!gateTransactionData" class="gate-empty-state">
          <div v-if="gateSearching" class="gate-empty-searching">
            <span class="gate-empty-radar">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            </span>
            <p class="gate-empty-title">Mencari kendaraan…</p>
            <p class="gate-empty-sub">Memvalidasi nomor plat & mengambil data transaksi.</p>
          </div>
          <template v-else>
            <div class="gate-empty-illustration">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M14 16H9m10 0h3v-3.15a1 1 0 0 0-.84-.99L16 11l-2.7-3.6a1 1 0 0 0-.8-.4H5.24a2 2 0 0 0-1.8 1.1l-.8 1.63A6 6 0 0 0 2 12.42V16h2"/><circle cx="6.5" cy="16.5" r="2.5"/><circle cx="16.5" cy="16.5" r="2.5"/></svg>
            </div>
            <p class="gate-empty-title">Cari data kendaraan</p>
            <p class="gate-empty-sub">Masukkan nomor plat di atas, lalu klik <b>Cari</b> untuk menampilkan detail transaksi beserta gambar CCTV, ANPR, dan MR.</p>
            <div class="gate-empty-tips">
              <div class="gate-empty-tip">
                <span class="gate-empty-tip-icon tip-cctv">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m22 8-6 4 6 4V8Z"/><rect x="2" y="6" width="14" height="12" rx="2"/></svg>
                </span>
                <span>Gambar CCTV masuk & keluar</span>
              </div>
              <div class="gate-empty-tip">
                <span class="gate-empty-tip-icon tip-anpr">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M7 9v6M12 9v6M17 9v6"/></svg>
                </span>
                <span>Snapshot plat dari ANPR</span>
              </div>
              <div class="gate-empty-tip">
                <span class="gate-empty-tip-icon tip-mr">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                </span>
                <span>Foto kendaraan dari MR</span>
              </div>
            </div>
            <div class="gate-empty-hintbar">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px;flex-shrink:0;"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
              <span>Tekan <b>Enter</b> pada kolom nomor plat untuk mencari lebih cepat.</span>
            </div>
          </template>
        </div>

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
            <div class="info-item">
              <span class="info-label">Sumber Gambar Masuk:</span>
              <span class="badge" :style="gateTransactionData.category === 'request_capture' ? 'background:#7c3aed;color:#fff;' : 'background:#0284c7;color:#fff;'">
                {{ gateTransactionData.category === 'request_capture' ? '📷 Request Capture (CCTV)' : gateTransactionData.category === 'get_capture' ? '🔍 Get Capture (ANPR)' : gateTransactionData.category || '-' }}
              </span>
            </div>
            <div class="info-item" v-if="gateTransactionData.category_exit">
              <span class="info-label">Sumber Gambar Keluar:</span>
              <span class="badge" :style="gateTransactionData.category_exit === 'request_capture' ? 'background:#7c3aed;color:#fff;' : 'background:#0284c7;color:#fff;'">
                {{ gateTransactionData.category_exit === 'request_capture' ? '📷 Request Capture (CCTV)' : gateTransactionData.category_exit === 'get_capture' ? '🔍 Get Capture (ANPR)' : gateTransactionData.category_exit }}
              </span>
            </div>
          </div>

          <!-- Images Display: Entry + Exit side-by-side -->
          <div class="gate-images-columns">
          <!-- Images Display: Entry -->
          <div class="gate-images-section">
            <div class="gate-images-section-header">
              <span class="gate-images-section-icon gate-images-section-icon--entry">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
              </span>
              <span class="gate-images-section-title">Gambar Masuk</span>
              <span v-if="gateTransactionData.category" class="gate-images-source-badge" :class="gateTransactionData.category === 'request_capture' ? 'source-cctv' : 'source-anpr'">
                {{ gateTransactionData.category === 'request_capture' ? 'CCTV' : 'ANPR' }}
              </span>
            </div>

            <div v-if="gateImageLoading" class="gate-images-loading">
              <span class="spinner"></span>
              <span>Memuat gambar...</span>
            </div>
            <template v-else>
              <!-- Entry images: MR + CCTV + ANPR -->
              <div class="images-grid">
                <template v-for="(image, index) in gateImages" :key="'entry-' + index">
                  <div v-if="image.direction === 'entry'" class="image-item" style="position: relative;">
                    <div class="image-label-overlay" :class="image.source === 'CCTV' ? 'label-cctv' : image.source === 'MR' ? 'label-mr' : 'label-anpr'">
                      {{ image.label }}
                    </div>
                    <img v-if="image.url" :src="image.url" :alt="image.label" @error="handleImageError" style="width:100%;height:100%;object-fit:cover;cursor:pointer;" @click="openImagePreview(image)" />
                    <img v-else-if="image.base64" :src="`data:image/jpeg;base64,${image.base64}`" :alt="image.label" @error="handleImageError" style="width:100%;height:100%;object-fit:cover;cursor:pointer;" @click="openImagePreview(image)" />
                    <div v-else class="image-placeholder">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:32px;height:32px;margin-bottom:8px;opacity:0.5;"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                      <div style="font-size:11px;color:var(--color-text-muted);">{{ image.success === false ? 'Gagal memuat' : 'Tidak tersedia' }}</div>
                    </div>
                  </div>
                </template>
                <div v-if="!gateImages.some(img => img.direction === 'entry')" class="image-placeholder" style="aspect-ratio:16/9;min-height:90px;">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="width:24px;height:24px;opacity:0.35;"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                  <span style="font-size:11px;margin-top:6px;">Tidak ada gambar masuk</span>
                </div>
              </div>
            </template>
          </div>

          <!-- Images Display: Exit -->
          <div class="gate-images-section" v-if="gateImages.some(img => img.direction === 'exit') || gateTransactionData.category_exit">
            <div class="gate-images-section-header">
              <span class="gate-images-section-icon gate-images-section-icon--exit">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
              </span>
              <span class="gate-images-section-title">Gambar Keluar</span>
              <span v-if="gateTransactionData.category_exit" class="gate-images-source-badge" :class="gateTransactionData.category_exit === 'request_capture' ? 'source-cctv' : 'source-anpr'">
                {{ gateTransactionData.category_exit === 'request_capture' ? 'CCTV' : 'ANPR' }}
              </span>
            </div>

            <div class="images-grid">
              <template v-for="(image, index) in gateImages" :key="'exit-' + index">
                <div v-if="image.direction === 'exit'" class="image-item" style="position: relative;">
                  <div class="image-label-overlay" :class="image.source === 'CCTV' ? 'label-cctv' : image.source === 'MR' ? 'label-mr' : 'label-anpr'">
                    {{ image.label }}
                  </div>
                  <img v-if="image.url" :src="image.url" :alt="image.label" @error="handleImageError" style="width:100%;height:100%;object-fit:cover;cursor:pointer;" @click="openImagePreview(image)" />
                  <img v-else-if="image.base64" :src="`data:image/jpeg;base64,${image.base64}`" :alt="image.label" @error="handleImageError" style="width:100%;height:100%;object-fit:cover;cursor:pointer;" @click="openImagePreview(image)" />
                  <div v-else class="image-placeholder">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:32px;height:32px;margin-bottom:8px;opacity:0.5;"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                    <div style="font-size:11px;color:var(--color-text-muted);">{{ image.success === false ? 'Gagal memuat' : 'Tidak tersedia' }}</div>
                  </div>
                </div>
              </template>
              <div v-if="!gateImages.some(img => img.direction === 'exit')" class="image-placeholder" style="aspect-ratio:16/9;min-height:90px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="width:24px;height:24px;opacity:0.35;"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                <span style="font-size:11px;margin-top:6px;">Belum ada gambar keluar</span>
              </div>
            </div>
          </div>
          </div>
        </div>

        <!-- Section: Gambar Validasi CCTV (muncul setelah tombol Validasi diklik) -->
        <div v-if="gateCaptureValidated || gateCaptureLoading" class="gate-capture-section">
          <div class="gate-capture-header">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;flex-shrink:0;">
              <path d="m22 8-6 4 6 4V8Z"/><rect x="2" y="6" width="14" height="12" rx="2"/>
            </svg>
            <span>Gambar Validasi CCTV</span>
            <span class="gate-capture-badge">DASHBOARD-OUT</span>
          </div>

          <!-- Loading state -->
          <div v-if="gateCaptureLoading" class="gate-capture-loading">
            <span class="spinner"></span>
            <span>Mengambil gambar dari CCTV…</span>
          </div>

          <!-- Images hasil capture -->
<!--          <template v-else>-->
<!--            <div v-if="gateCaptureImages.length === 0" class="gate-capture-empty">-->
<!--              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="width:28px;height:28px;opacity:0.35;"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>-->
<!--              <span>Tidak ada gambar diterima dari CCTV</span>-->
<!--            </div>-->
<!--            <div v-else class="gate-capture-images">-->
<!--              <div v-for="(img, i) in gateCaptureImages" :key="'cap-' + i" class="gate-capture-img-wrap">-->
<!--                <div class="image-label-overlay" :class="img.source === 'CCTV' ? 'label-cctv' : 'label-anpr'">{{ img.label }}</div>-->
<!--                <img-->
<!--                    v-if="img.base64"-->
<!--                    :src="`data:image/jpeg;base64,${img.base64}`"-->
<!--                    :alt="img.label"-->
<!--                    style="width:100%;height:100%;object-fit:cover;cursor:pointer;"-->
<!--                    @click="openImagePreview(img)"-->
<!--                    @error="handleImageError"-->
<!--                />-->
<!--                <img-->
<!--                    v-else-if="img.url"-->
<!--                    :src="img.url"-->
<!--                    :alt="img.label"-->
<!--                    style="width:100%;height:100%;object-fit:cover;cursor:pointer;"-->
<!--                    @click="openImagePreview(img)"-->
<!--                    @error="handleImageError"-->
<!--                />-->
<!--                <div v-else class="image-placeholder">-->
<!--                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:28px;height:28px;opacity:0.5;"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>-->
<!--                  <span style="font-size:11px;margin-top:6px;">Gagal memuat</span>-->
<!--                </div>-->
<!--              </div>-->
<!--            </div>-->
<!--          </template>-->
        </div>

        <div v-if="gatePublishError" class="alert alert-danger" style="margin-top: 12px;">
          {{ gatePublishError }}
        </div>

        <template #footer>
          <Button variant="secondary" type="button" @click="gateModal = false" :disabled="gateSubmitting || gateConnecting || gateCaptureLoading">Batal</Button>
          <!-- Step 2: Validasi - ambil gambar CCTV terkini sebelum buka gate -->
          <Button
              v-if="gateTransactionData && !gateCaptureValidated"
              variant="warning"
              type="button"
              :loading="gateCaptureLoading"
              @click="captureFromCctv"
          >
            <span v-if="gateCaptureLoading">Mengambil Gambar...</span>
            <span v-else>Validasi</span>
          </Button>
          <!-- Step 3: Buka Gate - setelah capture CCTV divalidasi -->
          <Button
              v-if="gateTransactionData && gateCaptureValidated"
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
          size="full"
      >
        <div class="detail-history">
          <!-- Filter Riwayat Gate -->
          <div class="filter-section" style="display: flex; gap: 10px; margin-bottom: 15px; align-items: flex-end; flex-wrap: wrap;">
            <div class="form-group" style="margin-bottom: 0;">
              <label class="form-label" style="font-size: 12px; margin-bottom: 4px;">Tanggal</label>
              <input type="date" class="form-control" v-model="detailGateFilterDate" style="font-size: 13px; padding: 6px 10px; height: 34px;" />
            </div>
            <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 150px;">
              <label class="form-label" style="font-size: 12px; margin-bottom: 4px;">Nomor Plat</label>
              <input type="text" class="form-control" placeholder="Cari Plat..." v-model="detailGateFilterPlate" style="font-size: 13px; padding: 6px 10px; height: 34px;" @keyup.enter="loadDetailGateLogs(1)" />
            </div>
            <div style="display: flex; gap: 8px;">
              <Button variant="primary" type="button" @click="loadDetailGateLogs(1)" style="height: 34px; padding: 0 12px;">Cari</Button>
              <Button variant="secondary" type="button" @click="resetDetailGateFilter" style="height: 34px; padding: 0 12px;">Reset</Button>
            </div>
          </div>

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
      <Modal
          v-model="logImagesModal"
          :size="logImagesData.length > 4 ? 'full' : 'lg'"
          :title="logImagesLog ? `Gambar Riwayat Gate · ${logImagesLog.gate_id || ''}` : 'Gambar Riwayat Gate'"
      >
        <!-- Info ringkas log -->
        <div v-if="logImagesLog" class="log-images-meta">
          <span v-if="logImagesLog.nomor_plat" class="log-images-plate">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;display:inline-block;vertical-align:-2px;margin-right:4px;"><rect x="1" y="6" width="22" height="12" rx="2"/><path d="M5 10h.01M19 10h.01M9 14h6"/></svg>
            {{ logImagesLog.nomor_plat }}
          </span>
          <span class="badge" :class="logImagesLog.action === 'OPEN' ? 'badge-success' : 'badge-info'">{{ logImagesLog.action }}</span>
          <span class="badge" :class="logImagesLog.result === 'SUCCESS' ? 'badge-success' : 'badge-danger'">{{ logImagesLog.result }}</span>
          <span v-if="logImagesLog.event_ts" class="log-images-time">{{ formatDateTime(logImagesLog.event_ts) }}</span>
          <span v-if="logImagesLog.code_transaction" class="log-images-time" style="font-weight:600;">{{ logImagesLog.code_transaction }}</span>
        </div>

        <div v-if="logImagesLoading" class="gate-images-loading">
          <span class="spinner"></span>
          <span>Memuat gambar...</span>
        </div>
        <div v-else-if="logImagesError" class="alert alert-danger">{{ logImagesError }}</div>
        <div v-else-if="logImagesData.length > 0" class="gate-images">
          <div class="gate-images-columns">
            <!-- Gambar Masuk -->
            <div class="gate-images-section" v-if="logImagesData.some(img => img.direction === 'entry')">
              <div class="gate-images-section-header">
                <span class="gate-images-section-icon gate-images-section-icon--entry">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                </span>
                <span class="gate-images-section-title">Gambar Masuk</span>
              </div>
              <div class="images-grid">
                <template v-for="(image, index) in logImagesData" :key="'hist-entry-' + index">
                  <div v-if="image.direction === 'entry'" class="image-item" style="position:relative;">
                    <div v-if="image.label" class="image-label-overlay" :class="image.source === 'CCTV' ? 'label-cctv' : image.source === 'MR' ? 'label-mr' : 'label-anpr'">
                      {{ image.label }}
                    </div>
                    <img v-if="image.url" :src="image.url" :alt="image.label || `Gambar ${index + 1}`" @error="handleImageError" style="width:100%;height:100%;object-fit:cover;cursor:pointer;" @click="openImagePreview(image)" />
                    <img v-else-if="image.base64" :src="`data:image/jpeg;base64,${image.base64}`" :alt="image.label || `Gambar ${index + 1}`" @error="handleImageError" style="width:100%;height:100%;object-fit:cover;cursor:pointer;" @click="openImagePreview(image)" />
                    <div v-else class="image-placeholder">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:32px;height:32px;margin-bottom:8px;opacity:0.5;"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                      <div style="font-size:11px;color:var(--color-text-muted);">{{ image.success === false ? 'Gagal memuat' : 'Tidak tersedia' }}</div>
                    </div>
                  </div>
                </template>
              </div>
            </div>

            <!-- Gambar Keluar -->
            <div class="gate-images-section" v-if="logImagesData.some(img => img.direction === 'exit')">
              <div class="gate-images-section-header">
                <span class="gate-images-section-icon gate-images-section-icon--exit">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                </span>
                <span class="gate-images-section-title">Gambar Keluar</span>
              </div>
              <div class="images-grid">
                <template v-for="(image, index) in logImagesData" :key="'hist-exit-' + index">
                  <div v-if="image.direction === 'exit'" class="image-item" style="position:relative;">
                    <div v-if="image.label" class="image-label-overlay" :class="image.source === 'CCTV' ? 'label-cctv' : image.source === 'MR' ? 'label-mr' : 'label-anpr'">
                      {{ image.label }}
                    </div>
                    <img v-if="image.url" :src="image.url" :alt="image.label || `Gambar ${index + 1}`" @error="handleImageError" style="width:100%;height:100%;object-fit:cover;cursor:pointer;" @click="openImagePreview(image)" />
                    <img v-else-if="image.base64" :src="`data:image/jpeg;base64,${image.base64}`" :alt="image.label || `Gambar ${index + 1}`" @error="handleImageError" style="width:100%;height:100%;object-fit:cover;cursor:pointer;" @click="openImagePreview(image)" />
                    <div v-else class="image-placeholder">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:32px;height:32px;margin-bottom:8px;opacity:0.5;"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                      <div style="font-size:11px;color:var(--color-text-muted);">{{ image.success === false ? 'Gagal memuat' : 'Tidak tersedia' }}</div>
                    </div>
                  </div>
                </template>
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
          <Button variant="secondary" type="button" @click="logImagesModal = false" :disabled="logImagesSubmitting">Tutup</Button>
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