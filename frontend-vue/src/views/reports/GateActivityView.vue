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

<style scoped src="./Reports/GateActivityView.css"></style>
