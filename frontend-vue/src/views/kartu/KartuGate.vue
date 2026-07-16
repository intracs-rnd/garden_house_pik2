<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useKartuStore } from '@/stores/kartu'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { extractErrorMessage, kartuReasonMeta } from '@/utils/helper'
import { formatDateTime } from '@/utils/formatter'
import PageHeader from '@/components/layout/Header.vue'
import Button from '@/components/common/Button.vue'
import DataTable from '@/components/common/DataTable.vue'

const store = useKartuStore()
const auth = useAuthStore()
const toast = useToast()

// Hanya peran dengan akses "Kelola" pada fitur Simulasi Gate yang boleh
// melakukan aksi tab in / tab out. Akses "Hanya lihat" hanya menampilkan
// halaman & riwayat tap tanpa bisa mengoperasikan gate.
const canOperate = computed(() => auth.canManage('kartu_gate'))

const cardNumber = ref('')
const gate = ref('')
const noPlat = ref('')
const processing = ref('')
const result = ref(null)
const history = ref([])
const loadingHistory = ref(false)

// Meta paginasi server-side untuk riwayat tap.
const historyMeta = reactive({ current_page: 1, per_page: 10, total: 0, last_page: 1 })

const historyColumns = [
  { key: 'time', label: 'Waktu' },
  { key: 'direction', label: 'Arah' },
  { key: 'card_number', label: 'Kartu' },
  { key: 'no_plat', label: 'No. Plat' },
  { key: 'granted', label: 'Hasil' },
]

// Status posisi terakhir tiap kartu: 'in' (sedang di dalam) atau 'out'.
// Dipakai untuk anti-passback: kartu yang sudah tab in tidak bisa tab in lagi
// sebelum melakukan tab out, dan sebaliknya.
const cardStatus = reactive({})

/** Arah terakhir kartu yang sedang diketik ('in' | 'out' | null). */
const currentCardState = computed(() => {
  const key = cardNumber.value.trim()
  return key ? cardStatus[key] || null : null
})

const canTabIn = computed(() => currentCardState.value !== 'in')
const canTabOut = computed(() => currentCardState.value === 'in')

/**
 * Penjelasan detail untuk tiap alasan penolakan akses dari backend.
 * Dipakai untuk memberi notifikasi yang jelas ke operator gate.
 */
const REASON_EXPLANATION = {
  blacklisted:
    'Kartu masuk daftar blacklist sehingga akses masuk/keluar diblokir. Hubungi admin untuk membuka blokir.',
  inactive:
    'Kartu berstatus Non Aktif sehingga tidak dapat digunakan untuk Tab In maupun Tab Out. Aktifkan kartu terlebih dahulu.',
  outstanding_payment:
    'Terdapat tunggakan pembayaran pada pemilik kartu. Selesaikan pembayaran untuk mengaktifkan akses.',
  not_yet_valid: 'Masa berlaku kartu belum dimulai, sehingga akses belum dapat diberikan.',
  expired: 'Masa berlaku kartu telah habis. Perpanjang masa berlaku untuk menggunakan kartu.',
  unknown_card: 'Nomor kartu tidak dikenali di sistem.',
}

function reasonExplanation(reason, fallback) {
  return REASON_EXPLANATION[reason] || fallback || 'Akses ditolak.'
}

function nowTime() {
  return new Intl.DateTimeFormat('id-ID', {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  }).format(new Date())
}

function buildRow({ id, direction, cardNumber: card, noPlat: plat, owner, granted, reason, tappedAt }) {
  return {
    id,
    direction,
    card_number: card,
    no_plat: plat || '—',
    owner: owner || '—',
    granted,
    reason,
    time: tappedAt ? formatDateTime(tappedAt) : nowTime(),
  }
}

function seedCardStatus(logs) {
  const seen = new Set()
  for (const log of logs) {
    const key = log.card_number
    if (!key || seen.has(key)) continue
    // Hanya tap yang diberikan akses yang mengubah posisi kartu.
    if (log.access_granted) {
      cardStatus[key] = Number(log.direction) === 1 ? 'in' : 'out'
      seen.add(key)
    }
  }
}

async function loadHistory() {
  loadingHistory.value = true
  try {
    const res = await store.fetchRecentLogs({
      page: historyMeta.current_page,
      per_page: historyMeta.per_page,
    })
    history.value = (res.data || []).map((log) =>
      buildRow({
        id: log.id,
        direction: Number(log.direction) === 1 ? 'in' : 'out',
        cardNumber: log.card_number,
        noPlat: log.no_plat,
        owner: log.kartu?.user?.name || log.user?.name,
        granted: log.access_granted,
        reason: log.reason,
        tappedAt: log.tapped_at,
      }),
    )
    if (res.meta) {
      historyMeta.current_page = res.meta.current_page
      historyMeta.per_page = res.meta.per_page
      historyMeta.total = res.meta.total
      historyMeta.last_page = res.meta.last_page
    }
    seedCardStatus(res.data || [])
  } catch (error) {
    // Non-blocking: live taps still work even if history fails to load.
    toast.error(extractErrorMessage(error, 'Gagal memuat riwayat tap.'))
  } finally {
    loadingHistory.value = false
  }
}

function changeHistoryPage(page) {
  historyMeta.current_page = page
  loadHistory()
}

function changeHistoryPerPage(perPage) {
  historyMeta.per_page = perPage
  historyMeta.current_page = 1
  loadHistory()
}

async function tap(direction) {
  if (!canOperate.value) {
    toast.error('Anda hanya memiliki akses lihat untuk fitur ini.')
    return
  }
  if (!cardNumber.value.trim()) {
    toast.error('Masukkan nomor kartu terlebih dahulu.')
    return
  }
  const key = cardNumber.value.trim()

  // Anti-passback: cegah tab in ganda / tab out tanpa tab in.
  if (direction === 'in' && cardStatus[key] === 'in') {
    toast.error('Kartu sudah Tab In. Lakukan Tab Out terlebih dahulu.')
    return
  }
  if (direction === 'out' && cardStatus[key] !== 'in') {
    toast.error('Kartu belum Tab In. Lakukan Tab In terlebih dahulu.')
    return
  }

  processing.value = direction
  result.value = null
  try {
    // Selalu kirim tap ke backend agar tercatat di log akses (termasuk saat
    // ditolak karena Blacklist / Non Aktif / masa berlaku habis). Backend yang
    // menentukan & menyimpan keputusan akses ke database.
    const res =
      direction === 'in'
        ? await store.tabIn(key, gate.value, noPlat.value)
        : await store.tabOut(key, gate.value, noPlat.value)

    // Lengkapi label status kartu (Blacklist / Non Aktif) untuk ditampilkan.
    const statusLabel =
      res.kartu?.status_label ||
      (res.reason === 'blacklisted'
        ? 'Blacklist'
        : res.reason === 'inactive'
          ? 'Non Aktif'
          : undefined)

    result.value = { ...res, direction, status_label: statusLabel }
    if (res.access_granted) {
      // Perbarui posisi kartu hanya ketika akses benar-benar diberikan.
      cardStatus[key] = direction
      toast.success(`${direction === 'in' ? 'Tab In' : 'Tab Out'} berhasil: ${res.message}`)
    } else {
      toast.error(`Akses ditolak: ${reasonExplanation(res.reason, res.message)}`)
    }
    // Muat ulang riwayat dari halaman pertama agar tap terbaru tampil di atas.
    historyMeta.current_page = 1
    loadHistory()
  } catch (error) {
    toast.error(extractErrorMessage(error, 'Gagal memproses kartu.'))
  } finally {
    processing.value = ''
  }
}

function reasonMeta(reason) {
  return kartuReasonMeta(reason)
}

function directionLabel(direction) {
  return direction === 'in' ? 'Tab In (Masuk)' : 'Tab Out (Keluar)'
}

onMounted(loadHistory)
</script>

<template>
  <div class="page">
    <PageHeader
      title="Simulasi Gate"
      subtitle="Uji tab in / tab out kartu akses dan lihat keputusannya"
    />

    <div class="row">
      <!-- Tap panel -->
      <div class="card" style="flex: 1; min-width: 320px">
        <div class="card-header">Tap Kartu</div>
        <div class="card-body">
          <div v-if="!canOperate" class="readonly-note">
            Anda memiliki akses <strong>Hanya lihat</strong> pada fitur ini. Aksi Tab In / Tab Out
            dinonaktifkan.
          </div>
          <div class="form-group">
            <label class="form-label">Nomor Kartu (UID)</label>
            <input
              v-model="cardNumber"
              type="text"
              class="form-control"
              placeholder="Scan / ketik nomor kartu"
              @keyup.enter="tap('in')"
            />
          </div>
          <div class="form-group">
            <label class="form-label">Gerbang (opsional)</label>
            <input
              v-model="gate"
              type="text"
              class="form-control"
              placeholder="Contoh: Gerbang Utama"
            />
          </div>
          <div class="form-group">
            <label class="form-label">Nomor Plat (opsional)</label>
            <input
              v-model="noPlat"
              type="text"
              class="form-control"
              placeholder="Contoh: B 1234 XYZ"
            />
          </div>
          <div class="tap-actions">
            <Button
              variant="primary"
              :loading="processing === 'in'"
              :disabled="!canOperate || !!processing || !canTabIn"
              @click="tap('in')"
            >
              → Tab In
            </Button>
            <Button
              variant="secondary"
              :loading="processing === 'out'"
              :disabled="!canOperate || !!processing || !canTabOut"
              @click="tap('out')"
            >
              Tab Out ←
            </Button>
          </div>

          <p v-if="cardNumber.trim()" class="card-state">
            Posisi kartu:
            <strong :class="currentCardState === 'in' ? 'state-in' : 'state-out'">
              {{ currentCardState === 'in' ? 'Di dalam (sudah Tab In)' : 'Di luar (siap Tab In)' }}
            </strong>
          </p>

          <!-- Decision result -->
          <div
            v-if="result"
            class="decision"
            :class="result.access_granted ? 'decision-ok' : 'decision-deny'"
          >
            <div class="decision-head">
              <span class="decision-icon">{{ result.access_granted ? '✓' : '✕' }}</span>
              <div>
                <strong>{{ result.access_granted ? 'Akses Diberikan' : 'Akses Ditolak' }}</strong>
                <small>{{ directionLabel(result.direction) }}</small>
              </div>
              <span class="badge" :class="`badge-${reasonMeta(result.reason).variant}`">
                {{ reasonMeta(result.reason).label }}
              </span>
            </div>
            <p class="decision-message">{{ result.message }}</p>

            <div v-if="result.kartu" class="decision-details">
              <div class="detail">
                <span>Nomor Kartu</span>
                <strong>{{ result.kartu.card_number }}</strong>
              </div>
              <div class="detail">
                <span>Pemilik</span>
                <strong>{{ result.kartu.user?.name || '-' }}</strong>
              </div>
              <div class="detail">
                <span>Masa Berlaku</span>
                <strong>
                  {{ result.kartu.valid_until ? formatDateTime(result.kartu.valid_until) : '-' }}
                </strong>
              </div>
              <div class="detail">
                <span>Masa Tenggang</span>
                <strong>{{ result.kartu.grace_days ? `${result.kartu.grace_days} hari` : '-' }}</strong>
              </div>
            </div>
            <p v-else class="decision-unknown">Kartu tidak terdaftar dalam sistem.</p>
          </div>
        </div>
      </div>

      <!-- Tap history -->
      <div class="card" style="flex: 1; min-width: 320px">
        <div class="card-header card-header-flex">
          <span>Riwayat Tap Terbaru</span>
          <span class="muted-count">{{ historyMeta.total }}</span>
        </div>
        <DataTable
          :columns="historyColumns"
          :rows="history"
          row-key="id"
          :loading="loadingHistory"
          :page="historyMeta.current_page"
          :per-page="historyMeta.per_page"
          :total="historyMeta.total"
          :last-page="historyMeta.last_page"
          empty-text="Belum ada aktivitas tap."
          loading-text="Memuat riwayat tap..."
          @change-page="changeHistoryPage"
          @change-per-page="changeHistoryPerPage"
        >
          <template #cell-direction="{ row }">
            <span class="badge" :class="row.direction === 'in' ? 'badge-success' : 'badge-info'">
              {{ row.direction === 'in' ? 'Masuk' : 'Keluar' }}
            </span>
          </template>
          <template #cell-card_number="{ row }">
            <div class="fw-600">{{ row.card_number }}</div>
            <small class="text-muted">{{ row.owner }}</small>
          </template>
          <template #cell-no_plat="{ row }">
            {{ row.no_plat }}
          </template>
          <template #cell-granted="{ row }">
            <span class="badge" :class="row.granted ? 'badge-success' : 'badge-danger'">
              {{ row.granted ? 'Boleh' : 'Ditolak' }}
            </span>
            <div><small class="text-muted">{{ reasonMeta(row.reason).label }}</small></div>
          </template>
        </DataTable>
      </div>
    </div>
  </div>
</template>

<style scoped>
.row {
  display: flex;
  gap: 20px;
  flex-wrap: wrap;
}
.tap-actions {
  display: flex;
  gap: 12px;
  margin-top: 4px;
}
.readonly-note {
  margin-bottom: 16px;
  padding: 10px 14px;
  border-radius: var(--radius-sm);
  background: #fffbeb;
  border: 1px solid #fde68a;
  color: #92400e;
  font-size: 13px;
}
.tap-actions :deep(button) {
  flex: 1;
}
.muted-count {
  color: var(--color-text-muted);
  font-size: 13px;
}
.card-state {
  margin: 12px 0 0;
  font-size: 13px;
  color: var(--color-text-muted);
}
.card-state .state-in {
  color: var(--color-success, #16a34a);
}
.card-state .state-out {
  color: var(--color-text, #334155);
}.decision {
  margin-top: 20px;
  padding: 16px;
  border-radius: var(--radius);
  border: 1px solid var(--color-border);
}
.decision-ok {
  background: rgba(22, 163, 74, 0.06);
  border-color: rgba(22, 163, 74, 0.35);
}
.decision-deny {
  background: rgba(220, 38, 38, 0.06);
  border-color: rgba(220, 38, 38, 0.35);
}
.decision-head {
  display: flex;
  align-items: center;
  gap: 12px;
}
.decision-head strong {
  display: block;
  font-size: 15px;
}
.decision-head small {
  color: var(--color-text-muted);
  font-size: 12px;
}
.decision-head .badge {
  margin-left: auto;
}
.decision-icon {
  display: grid;
  place-items: center;
  width: 34px;
  height: 34px;
  border-radius: 50%;
  font-size: 18px;
  font-weight: 700;
  color: #fff;
  flex-shrink: 0;
}
.decision-ok .decision-icon {
  background: var(--color-success, #16a34a);
}
.decision-deny .decision-icon {
  background: var(--color-danger, #dc2626);
}
.decision-message {
  margin: 12px 0 0;
  font-size: 14px;
}
.decision-details {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 10px;
  margin-top: 14px;
  padding-top: 14px;
  border-top: 1px dashed var(--color-border);
}
.detail {
  display: flex;
  flex-direction: column;
}
.detail span {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-text-muted);
}
.detail strong {
  font-size: 14px;
}
.decision-unknown {
  margin: 12px 0 0;
  color: var(--color-text-muted);
  font-size: 13px;
}
</style>
