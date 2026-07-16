<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
// Side-effect import: registers the <go2rtc-video> custom element.
import './video-rtc.js'

/**
 * Reusable low-latency live-stream player (go2rtc).
 *
 * The browser CANNOT play `rtsp://` directly, so `src` must be a go2rtc
 * WebSocket signalling URL (e.g. `ws://localhost:1984/api/ws?src=cam1`).
 * The vendored go2rtc web component negotiates WebRTC first (sub-second
 * latency, no more MediaMTX/HLS delay) and falls back to MSE/HLS/MJPEG.
 * `http(s)://` URLs are accepted and converted to `ws(s)://` automatically.
 */
const props = defineProps({
  src: { type: String, required: true },
  // Muted autoplay is required by browsers, but expose it just in case.
  muted: { type: Boolean, default: true },
  // Optional caption shown in the corner (e.g. the camera name).
  label: { type: String, default: '' },
})

const containerRef = ref(null)
const loading = ref(true)
const error = ref('')

// Text shown inside the loading overlay while the stream connects.
const statusText = computed(() => 'Menghubungkan ke stream...')

/** @type {HTMLElement & Record<string, any> | null} */
let player = null
let watchdog = null
let onPlaying = null

// If nothing actually plays within this window, go2rtc is almost certainly not
// running (or the camera is unreachable) — stop the endless spinner and say so.
const WATCHDOG_MS = 15000

function clearWatchdog() {
  if (watchdog) {
    clearTimeout(watchdog)
    watchdog = null
  }
}

function setError(message) {
  loading.value = false
  error.value = message
}

function teardown() {
  clearWatchdog()
  if (player) {
    if (onPlaying && player.video) player.video.removeEventListener('playing', onPlaying)
    // Detaching from the DOM triggers the component's own cleanup, but call
    // ondisconnect directly so the WebSocket/PeerConnection close immediately.
    try {
      if (typeof player.ondisconnect === 'function') player.ondisconnect()
    } catch (_e) {
      /* ignore */
    }
    if (player.parentNode) player.parentNode.removeChild(player)
    player = null
  }
  onPlaying = null
}

function initPlayer() {
  const container = containerRef.value
  if (!container) return

  teardown()
  loading.value = true
  error.value = ''

  if (!props.src) {
    setError('URL stream belum dikonfigurasi (cek VITE_STREAM_URL_1..4 di .env).')
    return
  }

  // A class extending HTMLElement can only be built via createElement with its
  // registered tag name — `new VideoRTC()` throws "Illegal constructor".
  player = document.createElement('go2rtc-video')
  // WebRTC first for the lowest latency, then graceful fallbacks.
  player.mode = 'webrtc,mse,hls,mjpeg'
  // CCTV feeds are video-only in this app; skip audio negotiation.
  player.media = 'video'
  player.background = false
  // Don't attach a permanent document 'visibilitychange' listener per player
  // (the component never removes it); avoids leaks with many mounts/remounts.
  player.visibilityCheck = false
  player.style.display = 'block'
  player.style.width = '100%'
  player.style.height = '100%'

  container.appendChild(player)

  // The component builds its <video> child synchronously on connect.
  const video = player.video
  if (video) {
    video.muted = props.muted
    onPlaying = () => {
      clearWatchdog()
      loading.value = false
    }
    video.addEventListener('playing', onPlaying)
  }

  // Point the component at the go2rtc WebSocket (it converts http -> ws).
  player.src = props.src

  // Fail fast (with guidance) instead of spinning forever on "connecting".
  watchdog = setTimeout(() => {
    if (loading.value) {
      setError(
        'Tidak dapat terhubung ke stream. Pastikan server go2rtc berjalan: ' +
          'buka folder streaming/ lalu jalankan start-stream.ps1.',
      )
    }
  }, WATCHDOG_MS)
}

function retry() {
  initPlayer()
}

onMounted(initPlayer)
onBeforeUnmount(teardown)
watch(() => props.src, initPlayer)
</script>

<template>
  <div class="live-stream">
    <div ref="containerRef" class="live-video"></div>

    <div v-if="loading && !error" class="live-overlay">
      <span class="live-spinner" aria-hidden="true"></span>
      <span>{{ statusText }}</span>
    </div>

    <div v-else-if="error" class="live-overlay live-overlay-error">
      <span>{{ error }}</span>
      <button type="button" class="live-retry" @click="retry">Coba lagi</button>
    </div>

    <span v-if="!loading && !error" class="live-badge">
      <span class="live-dot"></span> LIVE
    </span>

    <span v-if="label" class="live-label">{{ label }}</span>
  </div>
</template>

<style scoped>
.live-stream {
  position: relative;
  width: 100%;
  aspect-ratio: 16 / 9;
  background: #000;
  border-radius: var(--radius, 8px);
  overflow: hidden;
}
.live-video {
  width: 100%;
  height: 100%;
  display: block;
}
.live-video :deep(video) {
  width: 100%;
  height: 100%;
  object-fit: contain;
  display: block;
}
.live-overlay {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 16px;
  text-align: center;
  color: #e5e7eb;
  background: rgba(0, 0, 0, 0.55);
  font-size: 14px;
}
.live-overlay-error {
  color: #fca5a5;
}
.live-spinner {
  width: 30px;
  height: 30px;
  border: 3px solid rgba(255, 255, 255, 0.25);
  border-top-color: #fff;
  border-radius: 50%;
  animation: live-spin 0.7s linear infinite;
}
.live-retry {
  padding: 6px 14px;
  border: 1px solid #fff;
  border-radius: 6px;
  background: transparent;
  color: #fff;
  font-size: 13px;
  cursor: pointer;
}
.live-retry:hover {
  background: rgba(255, 255, 255, 0.15);
}
.live-badge {
  position: absolute;
  top: 10px;
  left: 10px;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 3px 8px;
  border-radius: 4px;
  background: rgba(0, 0, 0, 0.6);
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.5px;
}
.live-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #ef4444;
  animation: live-pulse 1.3s ease-in-out infinite;
}
.live-label {
  position: absolute;
  bottom: 10px;
  left: 10px;
  max-width: calc(100% - 20px);
  padding: 3px 9px;
  border-radius: 4px;
  background: rgba(0, 0, 0, 0.6);
  color: #fff;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.3px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
@keyframes live-spin {
  to {
    transform: rotate(360deg);
  }
}
@keyframes live-pulse {
  0%,
  100% {
    opacity: 1;
  }
  50% {
    opacity: 0.3;
  }
}
</style>
