<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import { gateApi } from '@/api/gate'
import { extractErrorMessage } from '@/utils/helper'
import { formatDateTime } from '@/utils/formatter'
import PageHeader from '@/components/layout/Header.vue'
import ActivityTrendsChart from '@/components/dashboard/ActivityTrendsChart.vue'

const POLL_MS = 5000

const logGateActivityAll = ref([])
const logGateLoading = ref(true)
const logGateError = ref('')

async function loadLogGate() {
  try {
    const res = await gateApi.getAllLogs({ limit: 50 })
    logGateActivityAll.value = res.data?.logs || []
    logGateError.value = ''
  } catch (err) {
    logGateError.value = extractErrorMessage(err, 'Gagal memuat log gate.')
  } finally {
    logGateLoading.value = false
  }
}

let logGateTimer
onMounted(() => {
  loadLogGate()
  logGateTimer = setInterval(loadLogGate, POLL_MS)
})

onUnmounted(() => {
  clearInterval(logGateTimer)
})
</script>

<template>
  <div class="page">
    <PageHeader
      title="Log & Tren Aktivitas Gate"
      subtitle="Riwayat aktivitas gate secara real-time dan tren 7 hari terakhir"
    />

    <!-- Live Gate Logs -->
    <div class="card" style="margin-top: 8px;">
      <div class="card-header card-header-flex">
        <span class="card-header-title">
          <svg class="card-header-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 6h16M4 12h16m-7 6h7" />
          </svg>
          Log Aktivitas Gate
        </span>
        <span class="live-indicator"><span class="live-pulse"></span>Live</span>
      </div>

      <div class="card-body" style="padding: 0; overflow-x: auto; max-height: 420px; overflow-y: auto;">
        <table class="detail-table" style="width: 100%; min-width: 600px;">
          <thead style="position: sticky; top: 0; background: var(--color-bg-card, #fff); z-index: 1; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
            <tr>
              <th style="padding-left: 24px;">Waktu</th>
              <th>Gate ID</th>
              <th>Aksi</th>
              <th>Hasil</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="logGateLoading && !logGateActivityAll.length">
              <td colspan="4" class="detail-empty" style="text-align: center;"><span class="spinner"></span> Memuat Log Gate...</td>
            </tr>
            <tr v-else-if="logGateError && !logGateActivityAll.length">
              <td colspan="4" class="detail-empty text-danger" style="text-align: center;">{{ logGateError }}</td>
            </tr>
            <tr v-else-if="!logGateActivityAll.length">
              <td colspan="4" class="detail-empty" style="text-align: center;">Belum ada data Log Gate</td>
            </tr>
            <tr v-else v-for="log in logGateActivityAll" :key="log.id">
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
    </div>

    <!-- Tren Aktivitas 7 Hari Terakhir -->
    <ActivityTrendsChart :autoRefresh="true" :refreshInterval="30000" style="margin-top: 24px;" />
  </div>
</template>

<style scoped>
.card-header-flex {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.card-header-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 600;
  font-size: 15px;
}
.card-header-icon {
  width: 18px;
  height: 18px;
  opacity: 0.7;
}
.live-indicator {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 600;
  color: #16a34a;
  background: #dcfce7;
  padding: 4px 10px;
  border-radius: 999px;
}
.live-pulse {
  width: 8px;
  height: 8px;
  background: #16a34a;
  border-radius: 50%;
  animation: pulse 1.5s infinite;
}
@keyframes pulse {
  0%, 100% { opacity: 1; transform: scale(1); }
  50% { opacity: 0.5; transform: scale(1.3); }
}
.detail-table {
  border-collapse: collapse;
}
.detail-table th,
.detail-table td {
  padding: 10px 16px;
  font-size: 13px;
  border-bottom: 1px solid var(--color-border, #f1f5f9);
  text-align: left;
}
.detail-table th {
  font-weight: 600;
  color: var(--color-text-muted, #64748b);
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.detail-empty {
  color: var(--color-text-muted, #94a3b8);
  padding: 32px 16px !important;
  font-size: 14px;
}
.detail-time {
  color: var(--color-text-muted, #64748b);
  font-size: 12px;
  white-space: nowrap;
}
.detail-gate-id strong {
  font-size: 13px;
}
.text-danger {
  color: #ef4444;
}
.spinner {
  display: inline-block;
  width: 16px;
  height: 16px;
  border: 2px solid #e2e8f0;
  border-top-color: var(--color-primary, #4f46e5);
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
  vertical-align: middle;
  margin-right: 6px;
}
@keyframes spin {
  to { transform: rotate(360deg); }
}
</style>
