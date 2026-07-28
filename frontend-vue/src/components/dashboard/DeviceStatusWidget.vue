<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
  /**
   * List of device statuses received from MQTT topic get/+/status
   * Each item: { nama_device: string, status: 'online'|'offline', receivedAt: string }
   */
  devices: {
    type: Array,
    default: () => [],
  },
  mqttConnected: {
    type: Boolean,
    default: false,
  },
})

const searchQuery = ref('')

const filteredDevices = computed(() => {
  if (!searchQuery.value.trim()) return props.devices
  const q = searchQuery.value.toLowerCase()
  return props.devices.filter((d) => d.nama_device.toLowerCase().includes(q))
})

const onlineCount = computed(() => props.devices.filter((d) => d.status === 'online').length)
const offlineCount = computed(() => props.devices.filter((d) => d.status === 'offline').length)

function formatTime(iso) {
  if (!iso) return ''
  try {
    return new Date(iso).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
  } catch {
    return iso
  }
}
</script>

<template>
  <div class="device-status-widget">
    <!-- Header -->
    <div class="dsw-header">
      <div class="dsw-header-left">
        <span class="dsw-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="2" y="3" width="20" height="14" rx="2" />
            <path d="M8 21h8M12 17v4" />
          </svg>
        </span>
        <span class="dsw-title">Status Device</span>
      </div>
      <div class="dsw-badges">
        <span class="dsw-badge is-online">
          <span class="dsw-dot"></span>
          {{ onlineCount }} Online
        </span>
        <span class="dsw-badge is-offline">
          {{ offlineCount }} Offline
        </span>
      </div>
    </div>

    <!-- Topic info -->
    <div class="dsw-topic-row">
      <span class="dsw-topic-label">Topic:</span>
      <code class="dsw-topic-code">get/+/status</code>
      <span class="dsw-conn-dot" :class="mqttConnected ? 'is-online' : 'is-offline'">
        <span class="dsw-dot"></span>
        {{ mqttConnected ? 'Live' : 'Offline' }}
      </span>
    </div>

    <!-- Search -->
    <div v-if="devices.length > 4" class="dsw-search">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="11" cy="11" r="8" /><path d="M21 21l-4.35-4.35" />
      </svg>
      <input v-model="searchQuery" type="text" placeholder="Cari device..." class="dsw-search-input" />
    </div>

    <!-- Empty state -->
    <div v-if="devices.length === 0" class="dsw-empty">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <rect x="2" y="3" width="20" height="14" rx="2" />
        <path d="M8 21h8M12 17v4" />
      </svg>
      <span class="dsw-empty-title">Belum ada data device</span>
      <span class="dsw-empty-sub">
        Menunggu pesan MQTT dari topic <code>get/+/status</code>
      </span>
    </div>

    <!-- Device list -->
    <div v-else class="dsw-list">
      <TransitionGroup name="dsw-anim" tag="div">
        <div
          v-for="device in filteredDevices"
          :key="device.nama_device"
          class="dsw-item"
          :class="device.status === 'online' ? 'is-online' : 'is-offline'"
        >
          <!-- Status icon -->
          <div class="dsw-item-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="2" y="3" width="20" height="14" rx="2" />
              <path d="M8 21h8M12 17v4" />
            </svg>
          </div>

          <!-- Device info -->
          <div class="dsw-item-info">
            <span class="dsw-item-name">{{ device.nama_device }}</span>
            <span class="dsw-item-time">{{ formatTime(device.receivedAt) }}</span>
          </div>

          <!-- Status badge -->
          <div class="dsw-item-status">
            <span class="dsw-status-dot" :class="device.status === 'online' ? 'is-online' : 'is-offline'"></span>
            <span class="dsw-status-label">{{ device.status }}</span>
          </div>
        </div>
      </TransitionGroup>

      <div v-if="filteredDevices.length === 0 && searchQuery" class="dsw-no-result">
        Tidak ditemukan device "<strong>{{ searchQuery }}</strong>"
      </div>
    </div>
  </div>
</template>

<style scoped>
.device-status-widget {
  display: flex;
  flex-direction: column;
  gap: 0;
  height: 100%;
}

/* Header */
.dsw-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 18px 10px;
  border-bottom: 1px solid var(--color-border, #e2e8f0);
}
.dsw-header-left {
  display: flex;
  align-items: center;
  gap: 8px;
}
.dsw-icon {
  display: grid;
  place-items: center;
  width: 28px;
  height: 28px;
  background: #ede9fe;
  border-radius: 6px;
  color: #7c3aed;
}
.dsw-icon svg {
  width: 16px;
  height: 16px;
}
.dsw-title {
  font-size: 14px;
  font-weight: 600;
  color: var(--color-text, #1e293b);
}
.dsw-badges {
  display: flex;
  align-items: center;
  gap: 6px;
}
.dsw-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 8px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 600;
}
.dsw-badge.is-online {
  background: #dcfce7;
  color: #16a34a;
}
.dsw-badge.is-offline {
  background: #fee2e2;
  color: #dc2626;
}

/* Topic row */
.dsw-topic-row {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 6px 18px;
  background: #f8fafc;
  border-bottom: 1px solid var(--color-border, #e2e8f0);
  font-size: 11px;
  flex-wrap: wrap;
}
.dsw-topic-label {
  color: var(--color-text-muted, #64748b);
  font-weight: 500;
}
.dsw-topic-code {
  background: #ede9fe;
  color: #7c3aed;
  padding: 1px 6px;
  border-radius: 4px;
  font-size: 11px;
}
.dsw-conn-dot {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  margin-left: auto;
  font-weight: 600;
  font-size: 11px;
}
.dsw-conn-dot.is-online { color: #16a34a; }
.dsw-conn-dot.is-offline { color: #dc2626; }

/* Animated status dot */
.dsw-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: currentColor;
  animation: dsw-pulse 2s infinite;
}
@keyframes dsw-pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.4; }
}

/* Search */
.dsw-search {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 18px;
  border-bottom: 1px solid var(--color-border, #e2e8f0);
}
.dsw-search svg {
  width: 14px;
  height: 14px;
  color: var(--color-text-muted, #94a3b8);
  flex-shrink: 0;
}
.dsw-search-input {
  flex: 1;
  border: none;
  outline: none;
  font-size: 13px;
  background: transparent;
  color: var(--color-text, #1e293b);
}
.dsw-search-input::placeholder {
  color: var(--color-text-muted, #94a3b8);
}

/* Empty state */
.dsw-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 36px 24px;
  gap: 8px;
  text-align: center;
  flex: 1;
}
.dsw-empty svg {
  width: 36px;
  height: 36px;
  color: var(--color-text-muted, #94a3b8);
  opacity: 0.5;
  margin-bottom: 4px;
}
.dsw-empty-title {
  font-size: 13px;
  font-weight: 600;
  color: var(--color-text-muted, #64748b);
}
.dsw-empty-sub {
  font-size: 12px;
  color: var(--color-text-muted, #94a3b8);
}
.dsw-empty-sub code {
  background: #f1f5f9;
  padding: 1px 5px;
  border-radius: 3px;
  font-size: 11px;
}

/* Device list */
.dsw-list {
  flex: 1;
  overflow-y: auto;
  padding: 6px 0;
}

.dsw-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 18px;
  border-left: 3px solid transparent;
  transition: background 0.15s ease;
}
.dsw-item:hover {
  background: #f8fafc;
}
.dsw-item.is-online {
  border-left-color: #22c55e;
}
.dsw-item.is-offline {
  border-left-color: #ef4444;
}

.dsw-item-icon {
  display: grid;
  place-items: center;
  width: 32px;
  height: 32px;
  border-radius: 8px;
  flex-shrink: 0;
}
.dsw-item.is-online .dsw-item-icon {
  background: #dcfce7;
  color: #16a34a;
}
.dsw-item.is-offline .dsw-item-icon {
  background: #fee2e2;
  color: #dc2626;
}
.dsw-item-icon svg {
  width: 16px;
  height: 16px;
}

.dsw-item-info {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-width: 0;
}
.dsw-item-name {
  font-size: 13px;
  font-weight: 600;
  color: var(--color-text, #1e293b);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.dsw-item-time {
  font-size: 11px;
  color: var(--color-text-muted, #94a3b8);
  margin-top: 1px;
}

.dsw-item-status {
  display: flex;
  align-items: center;
  gap: 5px;
  flex-shrink: 0;
}
.dsw-status-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
}
.dsw-status-dot.is-online {
  background: #22c55e;
  box-shadow: 0 0 0 2px rgba(34, 197, 94, 0.25);
  animation: dsw-pulse 2s infinite;
}
.dsw-status-dot.is-offline {
  background: #ef4444;
}
.dsw-status-label {
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.03em;
}
.dsw-item.is-online .dsw-status-label { color: #16a34a; }
.dsw-item.is-offline .dsw-status-label { color: #dc2626; }

/* No result */
.dsw-no-result {
  text-align: center;
  padding: 20px;
  font-size: 13px;
  color: var(--color-text-muted, #94a3b8);
}

/* Transition animation */
.dsw-anim-enter-active,
.dsw-anim-leave-active {
  transition: all 0.3s ease;
}
.dsw-anim-enter-from {
  opacity: 0;
  transform: translateX(-12px);
}
.dsw-anim-leave-to {
  opacity: 0;
  transform: translateX(12px);
}
</style>
