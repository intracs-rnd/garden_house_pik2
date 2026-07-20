<template>
  <div class="chart-container">
    <div class="chart-header-row">
      <div class="chart-header">
        <h3 class="chart-title">Trend Aktivitas 7 Hari Terakhir</h3>
        <p class="chart-subtitle">Data kendaraan masuk, keluar, dan total aktivitas</p>
      </div>
      <div class="chart-filter">
        <input 
          type="date" 
          v-model="selectedEndDate" 
          @change="handleDateChange" 
          class="date-input"
          :max="todayDateStr"
          title="Pilih tanggal akhir untuk 7 hari"
        />
        <button v-if="selectedEndDate && selectedEndDate !== todayDateStr" @click="resetDate" class="reset-btn" title="Reset ke hari ini">Reset</button>
      </div>
    </div>

    <div v-if="loading" class="chart-loading">
      <div class="spinner"></div>
      <p>Memuat data...</p>
    </div>

    <div v-else-if="error" class="chart-error">
      <p>{{ error }}</p>
      <button @click="loadData" class="retry-btn">Coba Lagi</button>
    </div>

    <div v-else-if="!trendsData.length" class="chart-empty">
      <p>Belum ada data aktivitas untuk 7 hari terakhir.</p>
    </div>

    <div v-else class="chart-wrapper">
      <canvas ref="chartCanvas"></canvas>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue'
import {
  Chart,
  LineController,
  BarController,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  BarElement,
  Title,
  Tooltip,
  Legend,
  Filler
} from 'chart.js'
import dashboardApi from '@/api/dashboard'
import { extractErrorMessage } from '@/utils/helper'

// Register Chart.js components
Chart.register(
    LineController,
    BarController,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    BarElement,
    Title,
    Tooltip,
    Legend,
    Filler
)

const chartCanvas = ref(null)
const chartInstance = ref(null)
const loading = ref(true)
const error = ref('')
const trendsData = ref([])

const props = defineProps({
  autoRefresh: {
    type: Boolean,
    default: true
  },
  refreshInterval: {
    type: Number,
    default: 30000 // 30 seconds
  }
})

function toLocalDateStr(date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

const todayDateStr = computed(() => toLocalDateStr(new Date()))
const selectedEndDate = ref(todayDateStr.value)

function handleDateChange() {
  loadData()
}

function resetDate() {
  selectedEndDate.value = todayDateStr.value
  loadData()
}

let refreshTimer = null

async function loadData() {
  loading.value = true
  error.value = ''

  try {
    const params = {}
    if (selectedEndDate.value) {
      params.end_date = selectedEndDate.value
    }
    const res = await dashboardApi.activityTrends(params)
    console.log('Activity Trends Response:', res)
    trendsData.value = res.data || []
    console.log('Trends Data:', trendsData.value)

    if (trendsData.value.length === 0) {
      console.warn('No trends data available')
    }
  } catch (err) {
    console.error('Failed to load activity trends:', err)
    error.value = extractErrorMessage(err, 'Gagal memuat data trend aktivitas.')
  } finally {
    // PENTING: loading harus false DULU sebelum kita coba render chart,
    // karena <canvas> baru muncul di DOM (v-else) setelah loading = false.
    loading.value = false
  }

  // Render chart HANYA setelah loading selesai & canvas sudah ter-mount di DOM.
  if (!error.value && trendsData.value.length > 0) {
    await nextTick()
    renderChart()
  } else if (chartInstance.value) {
    // Kalau data kosong/error, bersihkan chart lama biar tidak nyangkut.
    chartInstance.value.destroy()
    chartInstance.value = null
  }
}

function renderChart() {
  console.log('renderChart called', {
    hasCanvas: !!chartCanvas.value,
    dataLength: trendsData.value.length,
    data: trendsData.value
  })

  if (!chartCanvas.value) {
    console.error('Canvas element not found')
    return
  }

  if (trendsData.value.length === 0) {
    console.warn('No data to render')
    return
  }

  // Destroy existing chart
  if (chartInstance.value) {
    chartInstance.value.destroy()
  }

  // Prepare data
  const labels = trendsData.value.map(item => {
    const date = new Date(item.date)
    return date.toLocaleDateString('id-ID', { day: 'numeric', month: 'short' })
  })

  const vehiclesIn = trendsData.value.map(item => item.vehicles_in)
  const vehiclesOut = trendsData.value.map(item => item.vehicles_out)
  const vehiclesInside = trendsData.value.map(item => item.vehicles_inside)
  const totalActivities = trendsData.value.map(item => item.total_activities)

  // Create chart
  const ctx = chartCanvas.value.getContext('2d')
  chartInstance.value = new Chart(ctx, {
    type: 'line',
    data: {
      labels: labels,
      datasets: [
        {
          label: 'Kendaraan Masuk',
          data: vehiclesIn,
          borderColor: '#10b981',
          backgroundColor: 'rgba(16, 185, 129, 0.1)',
          borderWidth: 2,
          tension: 0.4,
          fill: true,
          pointRadius: 4,
          pointHoverRadius: 6
        },
        {
          label: 'Kendaraan Keluar',
          data: vehiclesOut,
          borderColor: '#ef4444',
          backgroundColor: 'rgba(239, 68, 68, 0.1)',
          borderWidth: 2,
          tension: 0.4,
          fill: true,
          pointRadius: 4,
          pointHoverRadius: 6
        },
        {
          label: 'Kendaraan Di Dalam',
          data: vehiclesInside,
          borderColor: '#f59e0b',
          backgroundColor: 'rgba(245, 158, 11, 0.1)',
          borderWidth: 2,
          tension: 0.4,
          fill: true,
          pointRadius: 4,
          pointHoverRadius: 6
        },
        {
          label: 'Total Aktivitas',
          data: totalActivities,
          borderColor: '#0891b2',
          backgroundColor: 'rgba(8, 145, 178, 0.1)',
          borderWidth: 2,
          tension: 0.4,
          fill: true,
          pointRadius: 4,
          pointHoverRadius: 6
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      aspectRatio: 2.5,
      plugins: {
        legend: {
          display: true,
          position: 'top',
          labels: {
            usePointStyle: true,
            padding: 15,
            font: {
              size: 12,
              family: 'system-ui, -apple-system, sans-serif'
            }
          }
        },
        tooltip: {
          mode: 'index',
          intersect: false,
          backgroundColor: 'rgba(0, 0, 0, 0.8)',
          padding: 12,
          titleColor: '#fff',
          bodyColor: '#fff',
          borderColor: '#374151',
          borderWidth: 1,
          displayColors: true,
          callbacks: {
            label: function(context) {
              return context.dataset.label + ': ' + context.parsed.y + ' kendaraan'
            }
          }
        }
      },
      scales: {
        x: {
          grid: {
            display: false
          },
          ticks: {
            font: {
              size: 11
            }
          }
        },
        y: {
          beginAtZero: true,
          grid: {
            color: 'rgba(0, 0, 0, 0.05)'
          },
          ticks: {
            stepSize: 1,
            font: {
              size: 11
            },
            callback: function(value) {
              return Number.isInteger(value) ? value : null
            }
          }
        }
      },
      interaction: {
        mode: 'nearest',
        axis: 'x',
        intersect: false
      }
    }
  })

  console.log('Chart instance created successfully:', chartInstance.value)
}

function startAutoRefresh() {
  if (!props.autoRefresh) return
  refreshTimer = setInterval(loadData, props.refreshInterval)
}

function stopAutoRefresh() {
  if (refreshTimer) {
    clearInterval(refreshTimer)
    refreshTimer = null
  }
}

onMounted(() => {
  console.log('ActivityTrendsChart mounted')
  loadData()
  startAutoRefresh()
})

onUnmounted(() => {
  stopAutoRefresh()
  if (chartInstance.value) {
    chartInstance.value.destroy()
  }
})

watch(() => props.autoRefresh, (newVal) => {
  if (newVal) {
    startAutoRefresh()
  } else {
    stopAutoRefresh()
  }
})
</script>

<style scoped>
.chart-container {
  background: white;
  border-radius: 12px;
  padding: 24px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.chart-header-row {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 20px;
  gap: 16px;
}

.chart-header {
  margin-bottom: 0;
}

.chart-title {
  font-size: 18px;
  font-weight: 600;
  color: #111827;
  margin: 0 0 4px 0;
}

.chart-subtitle {
  font-size: 14px;
  color: #6b7280;
  margin: 0;
}

.chart-filter {
  display: flex;
  align-items: center;
  gap: 8px;
}

.date-input {
  padding: 6px 12px;
  border: 1px solid #d1d5db;
  border-radius: 6px;
  font-size: 14px;
  color: #374151;
  background-color: #f9fafb;
  outline: none;
  transition: border-color 0.2s, box-shadow 0.2s;
}

.date-input:focus {
  border-color: #3b82f6;
  box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
}

.reset-btn {
  padding: 6px 12px;
  background: #f3f4f6;
  color: #4b5563;
  border: 1px solid #d1d5db;
  border-radius: 6px;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  transition: background 0.2s, color 0.2s;
}

.reset-btn:hover {
  background: #e5e7eb;
  color: #1f2937;
}

.chart-wrapper {
  position: relative;
  width: 100%;
}

.chart-loading {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 300px;
  color: #6b7280;
}

.spinner {
  width: 40px;
  height: 40px;
  border: 3px solid #e5e7eb;
  border-top-color: #3b82f6;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
  margin-bottom: 12px;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

.chart-error {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 300px;
  color: #ef4444;
}

.chart-error p {
  margin-bottom: 16px;
}

.chart-empty {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 300px;
  color: #6b7280;
  font-size: 14px;
}

.retry-btn {
  padding: 8px 16px;
  background: #3b82f6;
  color: white;
  border: none;
  border-radius: 6px;
  font-size: 14px;
  cursor: pointer;
  transition: background 0.2s;
}

.retry-btn:hover {
  background: #2563eb;
}
</style>