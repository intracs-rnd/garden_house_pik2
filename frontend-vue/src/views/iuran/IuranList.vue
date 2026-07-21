<script setup>
import { onMounted, ref, computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useIuranStore } from '@/stores/iuran'
import { useKartuStore } from '@/stores/kartu'
import { useToast } from '@/composables/useToast'
import { extractErrorMessage } from '@/utils/helper'
import { formatDate } from '@/utils/formatter'
import PageHeader from '@/components/layout/Header.vue'
import Button from '@/components/common/Button.vue'
import DataTable from '@/components/common/DataTable.vue'
import Modal from '@/components/common/Modal.vue'

const auth = useAuthStore()
const store = useIuranStore()
const kartuStore = useKartuStore()
const toast = useToast()

// ─── Role helpers ─────────────────────────────────────────────────────────────
const isAdmin = computed(() => auth.isAdmin)
const isWarga = computed(() => !auth.isAdmin)

// ─── Tab aktif: 'tagihan' | 'riwayat' ────────────────────────────────────────
const activeTab = ref('tagihan')

// ─── Modal states ─────────────────────────────────────────────────────────────

// Form buat/edit tagihan (admin)
const formModal = ref(false)
const editTarget = ref(null)
const form = ref({ no_kk: '', periode: '', jumlah: '', deadline: '', keterangan: '' })
const formErrors = ref({})

// Konfirmasi hapus (admin)
const deleteTarget = ref(null)
const deleting = ref(false)

// Generate batch (admin)
const generateModal = ref(false)
const generateForm = ref({ periode: currentPeriode(), jumlah: '', deadline: '', keterangan: '' })
const generateResult = ref(null)

// Bayar iuran (warga)
const payTarget = ref(null)
const payForm = ref({ metode_bayar: 'transfer', catatan: '', nominal_transfer: '', rekening_tujuan: '', bukti_file: null })

// ─── Kolom tabel tagihan ──────────────────────────────────────────────────────
const tagihanColumns = computed(() => {
  const base = [
    { key: 'no_kk', label: 'No. KK', cellClass: 'fw-600' },
    { key: 'periode', label: 'Periode' },
    { key: 'jumlah', label: 'Jumlah (Rp)' },
    { key: 'deadline', label: 'Deadline' },
    { key: 'status', label: 'Status' },
    { key: 'keterangan', label: 'Keterangan' },
  ]
  // Warga tidak perlu kolom no_kk (sudah fix KK mereka)
  if (isWarga.value) {
    base.splice(0, 1) // hapus kolom no_kk
  }
  base.push({ key: 'aksi', label: 'Aksi', align: 'right' })
  return base
})

// Kolom tabel riwayat
const riwayatColumns = computed(() => {
  const base = [
    { key: 'no_kk', label: 'No. KK' },
    { key: 'periode', label: 'Periode' },
    { key: 'jumlah_bayar', label: 'Jumlah Bayar (Rp)' },
    { key: 'metode_bayar', label: 'Metode' },
    { key: 'dibayar_oleh', label: 'Dibayar Oleh' },
    { key: 'paid_at', label: 'Waktu Bayar' },
    { key: 'catatan', label: 'Catatan' },
  ]
  if (isWarga.value) {
    base.splice(0, 1) // hapus kolom no_kk
  }
  return base
})

// ─── Helpers ──────────────────────────────────────────────────────────────────
function currentPeriode() {
  const now = new Date()
  const y = now.getFullYear()
  const m = String(now.getMonth() + 1).padStart(2, '0')
  return `${y}-${m}`
}

function formatRupiah(val) {
  return Number(val || 0).toLocaleString('id-ID')
}

function statusVariant(status) {
  return { belum_bayar: 'warning', lunas: 'success', terlambat: 'danger' }[status] || 'muted'
}

function statusLabel(status) {
  return { belum_bayar: 'Belum Bayar', lunas: 'Lunas', terlambat: 'Terlambat' }[status] || '-'
}

// ─── Form buat/edit tagihan (admin) ──────────────────────────────────────────
function openCreate() {
  editTarget.value = null
  form.value = { no_kk: '', periode: currentPeriode(), jumlah: '', deadline: '', keterangan: '' }
  formErrors.value = {}
  formModal.value = true
}

function openEdit(item) {
  editTarget.value = item
  form.value = {
    no_kk: item.no_kk,
    periode: item.periode,
    jumlah: item.jumlah,
    deadline: item.deadline ? item.deadline.substring(0, 10) : '',
    keterangan: item.keterangan || '',
  }
  formErrors.value = {}
  formModal.value = true
}

async function handleSave() {
  formErrors.value = {}
  try {
    if (editTarget.value) {
      await store.update(editTarget.value.id, form.value)
      toast.success('Tagihan berhasil diperbarui.')
    } else {
      await store.create(form.value)
      toast.success('Tagihan berhasil dibuat.')
    }
    formModal.value = false
  } catch (error) {
    const msg = extractErrorMessage(error, 'Gagal menyimpan tagihan.')
    if (error?.response?.data?.errors) {
      formErrors.value = error.response.data.errors
    }
    toast.error(msg)
  }
}

// ─── Hapus tagihan (admin) ────────────────────────────────────────────────────
function confirmDelete(item) {
  deleteTarget.value = item
}

async function handleDelete() {
  if (!deleteTarget.value) return
  deleting.value = true
  try {
    await store.remove(deleteTarget.value.id)
    toast.success('Tagihan berhasil dihapus.')
    deleteTarget.value = null
  } catch (error) {
    toast.error(extractErrorMessage(error, 'Gagal menghapus tagihan.'))
  } finally {
    deleting.value = false
  }
}

// ─── Generate batch (admin) ───────────────────────────────────────────────────
function openGenerate() {
  generateForm.value = { periode: currentPeriode(), jumlah: '', deadline: '', keterangan: '' }
  generateResult.value = null
  generateModal.value = true
}

async function handleGenerate() {
  generateResult.value = null
  try {
    const res = await store.generate(generateForm.value)
    generateResult.value = res.data
    toast.success(`Berhasil generate ${res.data.created} tagihan.`)
  } catch (error) {
    toast.error(extractErrorMessage(error, 'Gagal generate tagihan.'))
  }
}

// ─── Bayar iuran (warga) ─────────────────────────────────────────────────────
function openPay(item) {
  payTarget.value = item
  payForm.value = { metode_bayar: 'transfer', catatan: '', nominal_transfer: '', rekening_tujuan: '', bukti_file: null }
}

function onBuktiChange(e) {
  const file = e.target.files && e.target.files[0] ? e.target.files[0] : null
  payForm.value.bukti_file = file
}

async function handlePay() {
  if (!payTarget.value) return

  // Client-side validation: bukti file wajib
  if (!payForm.value.bukti_file) {
    toast.error('Mohon upload bukti pembayaran (gambar / PDF).')
    return
  }

  try {
    // Build FormData for file upload
    const fd = new FormData()
    fd.append('metode_bayar', payForm.value.metode_bayar)
    if (payForm.value.catatan) fd.append('catatan', payForm.value.catatan)
    if (payForm.value.nominal_transfer) fd.append('nominal_transfer', payForm.value.nominal_transfer)
    if (payForm.value.rekening_tujuan) fd.append('rekening_tujuan', payForm.value.rekening_tujuan)
    if (payForm.value.bukti_file) fd.append('bukti_bayar', payForm.value.bukti_file)

    const res = await store.pay(payTarget.value.id, fd)
    toast.success('Pembayaran iuran berhasil! Terima kasih.')
    payTarget.value = null

    // Jika backend mengembalikan daftar kartu yang diaktifkan, sinkronkan status kartu di frontend
    if (res && Array.isArray(res.activated_kartu_ids) && res.activated_kartu_ids.length > 0) {
      try {
        const kartuApi = (await import('@/api/kartu')).default
        for (const id of res.activated_kartu_ids) {
          try { await kartuApi.update(id, { status: 1 }) } catch (e) { /* ignore per-card failure */ }
        }
        // Refresh kartu list UI
        kartuStore.fetchList(1)
      } catch (e) {
        // Ignore sync errors, not critical for payment flow
      }
    }
  } catch (error) {
    // Prefer showing server-side validation message when available
    const serverErrors = error?.response?.data?.errors
    if (serverErrors) {
      const firstKey = Object.keys(serverErrors)[0]
      let firstMsg = serverErrors[firstKey][0]
      // If backend returned a translation key like "validation.uploaded",
      // show a friendlier local message instead of the raw key.
      if (typeof firstMsg === 'string' && firstMsg.startsWith('validation.')) {
        firstMsg = 'Upload bukti gagal. Pastikan file berformat JPG/JPEG/PNG/PDF dan berukuran maksimal 5MB.'
      }
      toast.error(firstMsg)
    } else {
      toast.error(extractErrorMessage(error, 'Gagal melakukan pembayaran.'))
    }
  }
}

// ─── Pagination ───────────────────────────────────────────────────────────────
function changePage(page) { store.fetchList(page) }
function changePerPage(perPage) { store.setPerPage(perPage) }
function changeHistoryPage(page) { store.fetchHistory(page) }
function changeHistoryPerPage(perPage) { store.setHistoryPerPage(perPage) }

function applyFilters() { store.fetchList(1) }
function applyHistoryFilters() { store.fetchHistory(1) }

function switchTab(tab) {
  activeTab.value = tab
  if (tab === 'riwayat' && store.history.length === 0) {
    store.fetchHistory(1)
  }
}

// ─── Init ─────────────────────────────────────────────────────────────────────
onMounted(() => {
  store.fetchList(1)
})
</script>

<template>
  <div class="page">
    <PageHeader
        title="Iuran Perumahan"
        :subtitle="isAdmin ? 'Kelola tagihan iuran dan lihat riwayat pembayaran warga' : 'Lihat dan bayar iuran perumahan keluarga Anda'"
    >
      <template #actions>
        <Button v-if="isAdmin" variant="secondary" @click="openGenerate">
          ⚡ Generate Batch
        </Button>
        <Button v-if="isAdmin" variant="primary" @click="openCreate">
          + Tambah Tagihan
        </Button>
      </template>
    </PageHeader>

    <!-- Tab switch -->
    <div class="tab-bar">
      <button
          class="tab-btn"
          :class="{ active: activeTab === 'tagihan' }"
          @click="switchTab('tagihan')"
      >
        <span class="tab-icon">🧾</span>
        {{ isAdmin ? 'Tagihan Iuran' : 'Tagihan Saya' }}
      </button>
      <button
          class="tab-btn"
          :class="{ active: activeTab === 'riwayat' }"
          @click="switchTab('riwayat')"
      >
        <span class="tab-icon">📋</span>
        Riwayat Pembayaran
      </button>
    </div>

    <!-- ═══════════════ TAB: Tagihan ═══════════════ -->
    <div v-show="activeTab === 'tagihan'" class="card">
      <!-- Toolbar filter -->
      <div class="card-header toolbar">
        <input
            v-if="isAdmin"
            v-model="store.filters.no_kk"
            type="text"
            class="form-control search-input"
            placeholder="Filter No. KK..."
            @input="applyFilters"
        />
        <input
            v-model="store.filters.periode"
            type="month"
            class="form-control filter-select"
            @change="applyFilters"
        />
        <select v-model="store.filters.status" class="form-control filter-select" @change="applyFilters">
          <option value="">Semua Status</option>
          <option value="belum_bayar">Belum Bayar</option>
          <option value="lunas">Lunas</option>
          <option value="terlambat">Terlambat</option>
        </select>
        <Button variant="secondary" size="sm" @click="store.resetFilters()">Reset</Button>
      </div>

      <DataTable
          :columns="tagihanColumns"
          :rows="store.items"
          :loading="store.loading"
          :error="store.error"
          loading-text="Memuat tagihan iuran..."
          empty-text="Belum ada tagihan iuran."
          :page="store.meta.current_page"
          :per-page="store.meta.per_page"
          :total="store.meta.total"
          :last-page="store.meta.last_page"
          :per-page-options="[10, 15, 25, 50]"
          @change-page="changePage"
          @change-per-page="changePerPage"
      >
        <!-- No KK (admin only) -->
        <template #cell-no_kk="{ row }">
          <div>
            <span class="nokk-badge">{{ row.no_kk }}</span>
            <div v-if="row.kartus && row.kartus.length" class="kartu-list mt-8">
              <small v-for="k in row.kartus" :key="k.id" class="kartu-item">{{ k.card_number }}<span v-if="k.is_blacklisted" class="kartu-flag"> ⚠️</span></small>
            </div>
          </div>
        </template>

        <!-- Periode -->
        <template #cell-periode="{ row }">
          <span class="periode-text">{{ row.periode }}</span>
        </template>

        <!-- Jumlah -->
        <template #cell-jumlah="{ row }">
          <span class="amount">Rp {{ formatRupiah(row.jumlah) }}</span>
        </template>

        <!-- Deadline -->
        <template #cell-deadline="{ row }">
          <span :class="{ 'text-danger': row.is_overdue && row.status !== 'lunas' }">
            {{ formatDate(row.deadline) }}
            <span v-if="row.is_overdue && row.status !== 'lunas'" class="overdue-tag">Lewat!</span>
          </span>
        </template>

        <!-- Status -->
        <template #cell-status="{ row }">
          <span class="badge" :class="`badge-${statusVariant(row.status)}`">
            {{ statusLabel(row.status) }}
          </span>
        </template>

        <!-- Keterangan -->
        <template #cell-keterangan="{ row }">
          <span class="text-muted">{{ row.keterangan || '-' }}</span>
        </template>

        <!-- Aksi -->
        <template #cell-aksi="{ row }">
          <div class="table-actions" style="justify-content: flex-end">
            <!-- Warga: tombol bayar kalau belum lunas -->
            <template v-if="isWarga">
              <Button
                  v-if="row.status !== 'lunas'"
                  variant="primary"
                  size="sm"
                  :loading="store.paying && payTarget?.id === row.id"
                  @click="openPay(row)"
              >
                💳 Bayar Iuran
              </Button>
              <span v-else class="paid-badge">✅ Lunas</span>
            </template>

            <!-- Admin: edit & hapus -->
            <template v-if="isAdmin">
              <Button variant="secondary" size="sm" @click="openEdit(row)">Edit</Button>
              <Button variant="danger" size="sm" @click="confirmDelete(row)">Hapus</Button>
            </template>
          </div>
        </template>
      </DataTable>
    </div>

    <!-- ═══════════════ TAB: Riwayat ═══════════════ -->
    <div v-show="activeTab === 'riwayat'" class="card">
      <div class="card-header toolbar">
        <input
            v-if="isAdmin"
            v-model="store.historyFilters.no_kk"
            type="text"
            class="form-control search-input"
            placeholder="Filter No. KK..."
            @input="applyHistoryFilters"
        />
        <span v-else class="history-label">
          Riwayat pembayaran KK: <strong>{{ auth.user?.no_kk || '-' }}</strong>
        </span>
      </div>

      <DataTable
          :columns="riwayatColumns"
          :rows="store.history"
          :loading="store.historyLoading"
          :error="store.error"
          loading-text="Memuat riwayat pembayaran..."
          empty-text="Belum ada riwayat pembayaran."
          :page="store.historyMeta.current_page"
          :per-page="store.historyMeta.per_page"
          :total="store.historyMeta.total"
          :last-page="store.historyMeta.last_page"
          :per-page-options="[10, 15, 25, 50]"
          @change-page="changeHistoryPage"
          @change-per-page="changeHistoryPerPage"
      >
        <template #cell-no_kk="{ row }">
          <span class="nokk-badge">{{ row.no_kk }}</span>
        </template>
        <template #cell-periode="{ row }">
          {{ row.iuran_perumahan?.periode || '-' }}
        </template>
        <template #cell-jumlah_bayar="{ row }">
          <span class="amount">Rp {{ formatRupiah(row.jumlah_bayar) }}</span>
        </template>
        <template #cell-metode_bayar="{ row }">
          <span class="method-badge" :class="`method-${row.metode_bayar}`">
            {{ row.metode_bayar === 'transfer' ? '🏦 Transfer' : '💵 Cash' }}
          </span>
        </template>
        <template #cell-dibayar_oleh="{ row }">
          {{ row.paid_by_user?.name || '-' }}
        </template>
        <template #cell-paid_at="{ row }">
          <span class="datetime-text">{{ formatDate(row.paid_at) }}</span>
        </template>
        <template #cell-catatan="{ row }">
          <span class="text-muted">{{ row.catatan || '-' }}</span>
        </template>
      </DataTable>
    </div>

    <!-- ═══════════════ MODAL: Buat / Edit Tagihan (Admin) ═══════════════ -->
    <Modal
        :model-value="formModal"
        :title="editTarget ? 'Edit Tagihan Iuran' : 'Tambah Tagihan Iuran'"
        @update:model-value="formModal = false"
    >
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">No. KK <span class="required">*</span></label>
          <input v-model="form.no_kk" type="text" class="form-control" placeholder="Contoh: 3171234567890001" />
          <span v-if="formErrors.no_kk" class="form-error">{{ formErrors.no_kk[0] }}</span>
        </div>
        <div class="form-group">
          <label class="form-label">Periode <span class="required">*</span></label>
          <input v-model="form.periode" type="month" class="form-control" />
          <span v-if="formErrors.periode" class="form-error">{{ formErrors.periode[0] }}</span>
        </div>
        <div class="form-group">
          <label class="form-label">Jumlah Iuran (Rp) <span class="required">*</span></label>
          <input v-model="form.jumlah" type="number" min="0" class="form-control" placeholder="Contoh: 150000" />
          <span v-if="formErrors.jumlah" class="form-error">{{ formErrors.jumlah[0] }}</span>
        </div>
        <div class="form-group">
          <label class="form-label">Deadline <span class="required">*</span></label>
          <input v-model="form.deadline" type="date" class="form-control" />
          <span v-if="formErrors.deadline" class="form-error">{{ formErrors.deadline[0] }}</span>
        </div>
        <div class="form-group full-width">
          <label class="form-label">Keterangan (opsional)</label>
          <textarea v-model="form.keterangan" class="form-control" rows="2" placeholder="Catatan tambahan untuk tagihan ini..." />
        </div>
      </div>
      <template #footer>
        <Button variant="secondary" @click="formModal = false">Batal</Button>
        <Button variant="primary" :loading="store.saving" @click="handleSave">
          {{ editTarget ? 'Simpan Perubahan' : 'Buat Tagihan' }}
        </Button>
      </template>
    </Modal>

    <!-- ═══════════════ MODAL: Konfirmasi Hapus (Admin) ═══════════════ -->
    <Modal :model-value="!!deleteTarget" title="Hapus Tagihan" @update:model-value="deleteTarget = null">
      <p>
        Yakin ingin menghapus tagihan iuran periode
        <strong>{{ deleteTarget?.periode }}</strong>
        untuk KK <strong>{{ deleteTarget?.no_kk }}</strong>?
        Riwayat pembayaran terkait juga akan dihapus.
      </p>
      <template #footer>
        <Button variant="secondary" @click="deleteTarget = null">Batal</Button>
        <Button variant="danger" :loading="deleting" @click="handleDelete">Hapus</Button>
      </template>
    </Modal>

    <!-- ═══════════════ MODAL: Generate Batch (Admin) ═══════════════ -->
    <Modal :model-value="generateModal" title="Generate Tagihan Batch" @update:model-value="generateModal = false">
      <p class="modal-desc">
        Buat tagihan iuran secara otomatis untuk semua No. KK yang terdaftar di data warga.
        KK yang sudah memiliki tagihan untuk periode yang sama akan dilewati.
      </p>

      <!-- Hasil generate -->
      <div v-if="generateResult" class="generate-result">
        <div class="result-stat">
          <span class="stat-num created">{{ generateResult.created }}</span>
          <span class="stat-label">Tagihan Dibuat</span>
        </div>
        <div class="result-stat">
          <span class="stat-num skipped">{{ generateResult.skipped }}</span>
          <span class="stat-label">Dilewati</span>
        </div>
        <div class="result-stat">
          <span class="stat-num total">{{ generateResult.total_kk }}</span>
          <span class="stat-label">Total KK</span>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Periode <span class="required">*</span></label>
          <input v-model="generateForm.periode" type="month" class="form-control" />
        </div>
        <div class="form-group">
          <label class="form-label">Jumlah Iuran (Rp) <span class="required">*</span></label>
          <input v-model="generateForm.jumlah" type="number" min="0" class="form-control" placeholder="Contoh: 150000" />
        </div>
        <div class="form-group full-width">
          <label class="form-label">Deadline <span class="required">*</span></label>
          <input v-model="generateForm.deadline" type="date" class="form-control" />
        </div>
        <div class="form-group full-width">
          <label class="form-label">Keterangan (opsional)</label>
          <textarea v-model="generateForm.keterangan" class="form-control" rows="2" />
        </div>
      </div>
      <template #footer>
        <Button variant="secondary" @click="generateModal = false">Tutup</Button>
        <Button variant="primary" :loading="store.generating" @click="handleGenerate">
          ⚡ Generate Sekarang
        </Button>
      </template>
    </Modal>

    <!-- ═══════════════ MODAL: Bayar Iuran (Warga) ═══════════════ -->
    <Modal :model-value="!!payTarget" title="Bayar Iuran Perumahan" @update:model-value="payTarget = null">
      <div class="pay-scroll-area">
        <div class="pay-info-box">
          <div class="pay-info-row">
            <span class="pay-label">Periode</span>
            <span class="pay-value">{{ payTarget?.periode }}</span>
          </div>
          <div class="pay-info-row">
            <span class="pay-label">No. KK</span>
            <span class="pay-value">{{ payTarget?.no_kk }}</span>
          </div>
          <div class="pay-info-row">
            <span class="pay-label">Jumlah</span>
            <span class="pay-value amount-large">Rp {{ formatRupiah(payTarget?.jumlah) }}</span>
          </div>
          <div class="pay-info-row">
            <span class="pay-label">Deadline</span>
            <span class="pay-value" :class="{ 'text-danger': payTarget?.is_overdue }">
              {{ formatDate(payTarget?.deadline) }}
            </span>
          </div>
        </div>

        <div class="pay-form-grid">
          <div class="form-group">
            <label class="form-label">Metode Pembayaran</label>
            <select v-model="payForm.metode_bayar" class="form-control" disabled>
              <option value="transfer">🏦 Transfer Bank</option>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label">Nominal Transfer (opsional)</label>
            <input v-model="payForm.nominal_transfer" type="number" min="0" class="form-control" placeholder="Nominal yang Anda transfer" />
          </div>

          <div class="form-group full-width">
            <label class="form-label">Rekening Tujuan (opsional)</label>
            <input v-model="payForm.rekening_tujuan" type="text" class="form-control" placeholder="Contoh: BCA 1234567890 a.n. Koperasi Perumahan" />
          </div>

          <div class="form-group full-width">
            <label class="form-label">Upload Bukti Pembayaran <span class="required">*</span></label>
            <input type="file" accept="image/*,.pdf" class="form-control" @change="onBuktiChange" />
          </div>

          <div class="form-group full-width">
            <label class="form-label">Catatan (opsional)</label>
            <textarea v-model="payForm.catatan" class="form-control" rows="2" placeholder="Misal: transfer BCA xxxx" />
          </div>
        </div>

        <p class="pay-note">
          ℹ️ Pembayaran ini berlaku untuk seluruh anggota KK <strong>{{ payTarget?.no_kk }}</strong>.
        </p>
      </div>

      <template #footer>
        <Button variant="secondary" @click="payTarget = null">Batal</Button>
        <Button variant="primary" :loading="store.paying" @click="handlePay">
          💳 Konfirmasi Bayar
        </Button>
      </template>
    </Modal>
  </div>
</template>

<style scoped>
/* ─── Tab bar ──────────────────────────────────────────────────────────────── */
.tab-bar {
  display: flex;
  gap: 4px;
  margin-bottom: 20px;
  border-bottom: 2px solid var(--color-border);
}

.tab-btn {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 10px 20px;
  background: none;
  border: none;
  border-bottom: 2px solid transparent;
  margin-bottom: -2px;
  cursor: pointer;
  font-size: 14px;
  font-weight: 500;
  color: var(--color-text-muted);
  transition: color 0.15s, border-color 0.15s;
}

.tab-btn:hover {
  color: var(--color-text);
}

.tab-btn.active {
  color: var(--color-primary);
  border-bottom-color: var(--color-primary);
}

.tab-icon {
  font-size: 16px;
}

/* ─── Toolbar ──────────────────────────────────────────────────────────────── */
.toolbar {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  align-items: center;
}

.search-input {
  max-width: 240px;
  flex: 1;
}

.filter-select {
  max-width: 180px;
}

/* ─── Cell styles ──────────────────────────────────────────────────────────── */
.nokk-badge {
  font-family: monospace;
  font-size: 13px;
  background: var(--color-bg);
  border: 1px solid var(--color-border);
  padding: 2px 6px;
  border-radius: 4px;
}

/* Kartu list under No. KK */
.kartu-list { margin-top: 6px; display:flex; gap:6px; flex-wrap:wrap }
.kartu-item { display:inline-block; font-size:12px; color:var(--color-text-muted); background:var(--color-bg); border:1px solid var(--color-border); padding:2px 6px; border-radius:4px }
.kartu-flag { color:var(--color-danger); margin-left:4px; font-weight:700 }

.periode-text {
  font-weight: 600;
  color: var(--color-primary);
}

.amount {
  font-weight: 600;
  color: var(--color-text);
}

.overdue-tag {
  display: inline-block;
  margin-left: 4px;
  font-size: 10px;
  font-weight: 700;
  color: var(--color-danger);
  background: rgba(239, 68, 68, 0.1);
  padding: 1px 5px;
  border-radius: 3px;
  text-transform: uppercase;
}

.text-danger {
  color: var(--color-danger);
}

.text-muted {
  color: var(--color-text-muted);
  font-size: 13px;
}

.paid-badge {
  font-size: 13px;
  color: var(--color-success, #16a34a);
  font-weight: 500;
}

.method-badge {
  font-size: 12px;
  padding: 2px 8px;
  border-radius: 4px;
}
.method-transfer {
  background: rgba(99, 102, 241, 0.1);
  color: #6366f1;
}
.method-cash {
  background: rgba(22, 163, 74, 0.1);
  color: #16a34a;
}

.datetime-text {
  font-size: 13px;
  color: var(--color-text-muted);
}

/* ─── History label ─────────────────────────────────────────────────────────── */
.history-label {
  font-size: 14px;
  color: var(--color-text-muted);
}

/* ─── Modal: form grid ──────────────────────────────────────────────────────── */
.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;
}

.form-grid .full-width {
  grid-column: 1 / -1;
}

.modal-desc {
  font-size: 14px;
  color: var(--color-text-muted);
  margin-bottom: 16px;
  line-height: 1.6;
}

.required {
  color: var(--color-danger);
}

.form-error {
  display: block;
  font-size: 12px;
  color: var(--color-danger);
  margin-top: 4px;
}

/* ─── Modal: generate result ────────────────────────────────────────────────── */
.generate-result {
  display: flex;
  gap: 16px;
  padding: 16px;
  background: var(--color-bg);
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  margin-bottom: 20px;
}

.result-stat {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
}

.stat-num {
  font-size: 28px;
  font-weight: 700;
}

.stat-num.created { color: var(--color-success, #16a34a); }
.stat-num.skipped { color: var(--color-warning, #d97706); }
.stat-num.total   { color: var(--color-primary); }

.stat-label {
  font-size: 12px;
  color: var(--color-text-muted);
}

/* ─── Modal: bayar iuran ────────────────────────────────────────────────────── */

/* Area konten yang bisa discroll, supaya footer (tombol Konfirmasi Bayar)
   tetap terlihat walau tinggi konten melebihi viewport. Sesuaikan max-height
   ini kalau tinggi header+footer Modal.vue berbeda. */
.pay-scroll-area {
  max-height: min(70vh, 560px);
  overflow-y: auto;
  overflow-x: hidden;
  padding-right: 4px;
  margin-right: -4px;
}

/* Scrollbar tipis biar tidak norak */
.pay-scroll-area::-webkit-scrollbar {
  width: 6px;
}
.pay-scroll-area::-webkit-scrollbar-thumb {
  background: var(--color-border);
  border-radius: 3px;
}

.pay-info-box {
  background: var(--color-bg);
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  padding: 16px;
  margin-bottom: 16px;
}

.pay-info-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 6px 0;
  border-bottom: 1px solid var(--color-border);
}

.pay-info-row:last-child {
  border-bottom: none;
}

.pay-label {
  font-size: 13px;
  color: var(--color-text-muted);
}

.pay-value {
  font-size: 14px;
  font-weight: 500;
}

.amount-large {
  font-size: 18px;
  font-weight: 700;
  color: var(--color-primary);
}

/* Grid 2 kolom untuk field pembayaran, biar lebih ringkas & tidak terlalu tinggi */
.pay-form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;
}

.pay-form-grid .full-width {
  grid-column: 1 / -1;
}

.pay-note {
  margin-top: 16px;
  font-size: 13px;
  color: var(--color-text-muted);
  background: rgba(99, 102, 241, 0.06);
  border: 1px solid rgba(99, 102, 241, 0.2);
  border-radius: var(--radius-sm);
  padding: 10px 12px;
  line-height: 1.6;
}

.mt-16 {
  margin-top: 16px;
}

/* ─── Responsive ────────────────────────────────────────────────────────────── */
@media (max-width: 600px) {
  .form-grid,
  .pay-form-grid {
    grid-template-columns: 1fr;
  }

  .tab-btn {
    padding: 8px 14px;
    font-size: 13px;
  }

  .generate-result {
    flex-direction: column;
  }

  .pay-scroll-area {
    max-height: 65vh;
  }
}
</style>