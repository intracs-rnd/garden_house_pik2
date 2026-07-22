<script setup>
import { onMounted, ref, computed } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import { useKartuStore } from '@/stores/kartu'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import {
  debounce,
  extractErrorMessage,
  KARTU_STATUS,
  KARTU_STATUS_OPTIONS,
  kartuReasonMeta,
} from '@/utils/helper'
import { formatDateTime } from '@/utils/formatter'
import PageHeader from '@/components/layout/Header.vue'
import Button from '@/components/common/Button.vue'
import DataTable from '@/components/common/DataTable.vue'
import Modal from '@/components/common/Modal.vue'

const router = useRouter()
const store = useKartuStore()
const auth = useAuthStore()
const toast = useToast()

const deleteTarget = ref(null)
const deleting = ref(false)

const blacklistTarget = ref(null)
const blacklistReason = ref('')
const blacklisting = ref(false)

const reasonTarget = ref(null)

const columns = computed(() => {
  const base = [
    { key: 'card_number', label: 'Nomor Kartu', cellClass: 'fw-600' },
    { key: 'rfid_tag', label: 'RFID Tag' },
    { key: 'nama', label: 'Nama' },
    { key: 'pemilik', label: 'Pemilik' },
    { key: 'masa_berlaku', label: 'Masa Berlaku' },
    { key: 'tenggang', label: 'Tenggang' },
    { key: 'iuran', label: 'Iuran' },
    { key: 'status', label: 'Status' },
    { key: 'akses', label: 'Akses' },
    { key: 'blacklist_reason', label: 'Keterangan Blacklist' },
  ]
  if (auth.canManage('kartu') && store.activeTab === 'active') {
    base.push({ key: 'aksi', label: 'Aksi', align: 'right' })
  }
  return base
})

// Active filter count, used to show/hide the "reset filter" affordance
const activeFilterCount = computed(() => {
  if (store.activeTab !== 'active') return 0
  let count = 0
  if (store.filters.search) count++
  if (store.filters.status !== '' && store.filters.status !== undefined && store.filters.status !== null) count++
  if (store.filters.is_blacklisted !== '' && store.filters.is_blacklisted !== undefined && store.filters.is_blacklisted !== null) count++
  return count
})

const blacklistedCount = computed(() => store.items.filter((i) => i.is_blacklisted).length)

// Filtered items based on active tab
const filteredItems = computed(() => {
  if (store.activeTab === 'deleted') {
    return store.items.filter((i) => i.is_deleted)
  }
  return store.items.filter((i) => !i.is_deleted)
})

// Display total based on active tab
const displayTotal = computed(() => {
  // Use the paginated total from the API so the deleted tab shows the full
  // count across all pages instead of only the current page's items.
  return store.meta.total
})

const searchPlaceholder = computed(() =>
    store.activeTab === 'deleted'
        ? 'Cari di kartu yang telah dihapus...'
        : 'Cari nomor kartu, nama, pemilik...'
)

const onSearch = debounce(() => store.fetchList(1), 400)

function applyFilters() {
  store.fetchList(1)
}

function resetFilters() {
  store.filters.search = ''
  store.filters.status = ''
  store.filters.is_blacklisted = ''
  store.fetchList(1)
}

function switchTab(tab) {
  if (store.activeTab === tab) return
  store.setActiveTab(tab)
  // Reset per_page to default 10 when switching to deleted tab
  if (tab === 'deleted' && store.meta.per_page !== 10) {
    store.setPerPage(10)
  } else {
    store.fetchList(1)
  }
}

function changePage(page) {
  store.fetchList(page)
}

function changePerPage(perPage) {
  store.setPerPage(perPage)
}

function statusMeta(status) {
  return KARTU_STATUS[status] || { label: 'Tidak diketahui', variant: 'muted' }
}

function accessMeta(item) {
  // New policy: if there's an outstanding payment (iuran.status === 'terlambat')
  // but the current date is still within the grace period (row.grace_days after deadline),
  // access should be allowed with reason "Masa tenggang". If grace expired, access denied with "Ada tunggakan".
  const iuran = item.iuran
  const graceDays = Number(item.grace_days || 0)
  if (iuran && iuran.exists && iuran.status === 'terlambat') {
    const deadline = iuran.deadline ? new Date(iuran.deadline) : null
    if (deadline && graceDays > 0) {
      const graceEnd = new Date(deadline)
      graceEnd.setDate(graceEnd.getDate() + graceDays)
      const now = new Date()
      if (now <= graceEnd) {
        const meta = kartuReasonMeta('grace_period')
        return { allowed: true, label: meta.label, variant: meta.variant }
      }
      // grace expired => deny due to outstanding payment
      const meta = kartuReasonMeta('outstanding_payment')
      return { allowed: false, label: meta.label, variant: meta.variant }
    }
    // no deadline/grace info -> deny by default for outstanding payment
    const meta = kartuReasonMeta('outstanding_payment')
    return { allowed: false, label: meta.label, variant: meta.variant }
  }
  // fallback to gate-provided decision
  const reason = item.access?.reason
  const meta = kartuReasonMeta(reason)
  return {
    allowed: item.access?.allowed ?? false,
    label: meta.label,
    variant: item.access?.allowed ? meta.variant : 'danger',
  }
}

// Compute status display taking into account expired grace -> show Non Aktif when grace passed
function statusMetaWithIuran(row) {
  const iuran = row.iuran
  const graceDays = Number(row.grace_days || 0)
  if (iuran && iuran.exists && iuran.status === 'terlambat') {
    const deadline = iuran.deadline ? new Date(iuran.deadline) : null
    if (deadline && graceDays > 0) {
      const graceEnd = new Date(deadline)
      graceEnd.setDate(graceEnd.getDate() + graceDays)
      const now = new Date()
      if (now > graceEnd) {
        // return Non Aktif
        return KARTU_STATUS[2] || { label: 'Non Aktif', variant: 'muted' }
      }
    }
  }
  return statusMeta(row.status)
}

function confirmDelete(item) {
  deleteTarget.value = item
}

function openReason(item) {
  reasonTarget.value = item
}

async function handleDelete() {
  if (!deleteTarget.value) return
  deleting.value = true
  try {
    await store.remove(deleteTarget.value.id)
    toast.success('Kartu akses berhasil dihapus.')
    deleteTarget.value = null
    const page =
        store.items.length === 1 && store.meta.current_page > 1
            ? store.meta.current_page - 1
            : store.meta.current_page
    store.fetchList(page)
  } catch (error) {
    toast.error(extractErrorMessage(error, 'Gagal menghapus kartu akses.'))
  } finally {
    deleting.value = false
  }
}

function openBlacklist(item) {
  blacklistTarget.value = item
  blacklistReason.value = item.blacklist_reason || ''
}

async function handleBlacklist() {
  if (!blacklistTarget.value) return
  blacklisting.value = true
  try {
    await store.blacklist(blacklistTarget.value.id, blacklistReason.value)
    toast.success('Kartu berhasil diblacklist.')
    blacklistTarget.value = null
    blacklistReason.value = ''
    store.fetchList(store.meta.current_page)
  } catch (error) {
    toast.error(extractErrorMessage(error, 'Gagal memblacklist kartu.'))
  } finally {
    blacklisting.value = false
  }
}

async function handleClearBlacklist(item) {
  try {
    await store.clearBlacklist(item.id)
    toast.success('Kartu berhasil diaktifkan kembali.')
    store.fetchList(store.meta.current_page)
  } catch (error) {
    toast.error(extractErrorMessage(error, 'Gagal mengaktifkan kartu.'))
  }
}

onMounted(() => {
  store.fetchList()
})
</script>

<template>
  <div class="page">
    <PageHeader title="Kartu Akses" subtitle="Kelola kartu akses untuk tab in / tab out">
      <template #actions>
        <Button v-if="auth.canManage('kartu_gate')" variant="secondary" @click="router.push({ name: 'kartu.gate' })">
          Simulasi Gate
        </Button>
        <Button v-if="auth.canManage('kartu')" variant="primary" @click="router.push({ name: 'kartu.create' })">
          + Tambah Kartu
        </Button>
      </template>
    </PageHeader>

    <div class="card">
      <!-- Tabs -->
      <div class="tabs-header">
        <button
            type="button"
            :class="['tab-btn', { active: store.activeTab === 'active' }]"
            @click="switchTab('active')"
        >
          <svg class="tab-icon" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect x="1.5" y="3.5" width="13" height="9" rx="1.5" stroke="currentColor" stroke-width="1.2"/>
            <path d="M1.5 6.5h13" stroke="currentColor" stroke-width="1.2"/>
          </svg>
          Aktif
        </button>
        <button
            type="button"
            :class="['tab-btn', 'tab-btn-danger', { active: store.activeTab === 'deleted' }]"
            @click="switchTab('deleted')"
        >
          <svg class="tab-icon" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path
                d="M2.5 4.5h11M6 4.5V3a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v1.5M6.5 7.5v4M9.5 7.5v4M3.5 4.5l.6 8.1a1 1 0 0 0 1 .9h5.8a1 1 0 0 0 1-.9l.6-8.1"
                stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"
            />
          </svg>
          Dihapus
        </button>
      </div>

      <div class="card-header toolbar">
        <div class="toolbar-fields">
          <div class="search-wrap">
            <svg class="search-icon" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
              <circle cx="9" cy="9" r="6.5" stroke="currentColor" stroke-width="1.6" />
              <path d="M17 17L13.5 13.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
            </svg>
            <input
                v-model="store.filters.search"
                type="text"
                class="form-control search-input"
                :placeholder="searchPlaceholder"
                @input="onSearch"
            />
          </div>
          <template v-if="store.activeTab === 'active'">
            <select v-model="store.filters.status" class="form-control filter-select" @change="applyFilters">
              <option value="">Semua Status</option>
              <option v-for="opt in KARTU_STATUS_OPTIONS" :key="opt.value" :value="opt.value">
                {{ opt.label }}
              </option>
            </select>
            <select v-model="store.filters.is_blacklisted" class="form-control filter-select" @change="applyFilters">
              <option value="">Semua Kartu</option>
              <option value="true">Hanya Blacklist</option>
              <option value="false">Tanpa Blacklist</option>
            </select>
            <button v-if="activeFilterCount > 0" type="button" class="reset-filter" @click="resetFilters">
              Reset filter
            </button>
          </template>
        </div>

        <div class="toolbar-summary">
          <span class="summary-total">{{ displayTotal ?? 0 }} kartu</span>
          <span v-if="store.activeTab === 'active' && blacklistedCount > 0" class="summary-blacklist">
            <span class="dot dot-danger"></span>
            {{ blacklistedCount }} diblacklist
          </span>
          <span v-else-if="store.activeTab === 'deleted'" class="summary-deleted">
            <span class="dot dot-muted"></span>
            Riwayat kartu yang telah dihapus
          </span>
        </div>
      </div>

      <DataTable
          :columns="columns"
          :rows="filteredItems"
          :loading="store.loading"
          :refreshing="store.refreshing"
          :error="store.error"
          loading-text="Memuat kartu akses..."
          :empty-text="store.activeTab === 'deleted' ? 'Belum ada kartu yang dihapus.' : 'Belum ada data kartu akses.'"
          :page="store.meta.current_page"
          :per-page="store.meta.per_page"
          :total="displayTotal"
          :last-page="store.activeTab === 'deleted' ? Math.ceil(displayTotal / store.meta.per_page) || 1 : store.meta.last_page"
          :per-page-options="[10, 15, 25, 50, 100]"
          @change-page="changePage"
          @change-per-page="changePerPage"
      >
        <template #cell-card_number="{ row }">
          <span :class="{ 'is-deleted-text': row.is_deleted }">{{ row.card_number }}</span>
        </template>
        <template #cell-nama="{ row }">
          <span :class="{ 'is-deleted-text': row.is_deleted }">{{ row.nama || '-' }}</span>
        </template>
        <template #cell-rfid_tag="{ row }">
          <code v-if="row.rfid_tag" class="rfid-chip" :class="{ 'is-deleted-chip': row.is_deleted }">{{ row.rfid_tag }}</code>
          <span v-else class="text-muted">-</span>
        </template>
        <template #cell-pemilik="{ row }">{{ row.user?.name || '-' }}</template>
        <template #cell-masa_berlaku="{ row }">
          <template v-if="row.valid_until">
            <div class="date-range">
              <span>{{ formatDateTime(row.valid_from) }}</span>
              <span class="date-range-sep">→</span>
              <span>{{ formatDateTime(row.valid_until) }}</span>
            </div>
          </template>
          <template v-else><span class="text-muted">-</span></template>
        </template>
        <template #cell-tenggang="{ row }">
          <span v-if="row.grace_days" class="text-muted">{{ row.grace_days }} hari</span>
          <span v-else class="text-muted">-</span>
        </template>
        <template #cell-iuran="{ row }">
          <div v-if="row.iuran && row.iuran.exists" class="iuran-cell">
            <span class="badge" :class="row.iuran.status === 'terlambat' ? 'badge-danger' : 'badge-success'">
              <span class="badge-dot"></span>
              {{ row.iuran.status_label }}
            </span>
            <span v-if="row.iuran.deadline" class="iuran-deadline">
              <svg viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="2" y="3" width="12" height="11" rx="1.5" stroke="currentColor" stroke-width="1.2"/>
                <path d="M2 6.5h12" stroke="currentColor" stroke-width="1.2"/>
                <path d="M5 1.5v3M11 1.5v3" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
              </svg>
              <span>{{ row.iuran.status === 'terlambat' ? 'Lewat' : 'Jatuh tempo' }} {{ formatDateTime(row.iuran.deadline) }}</span>
            </span>
          </div>
          <span v-else class="text-muted">Tidak ada</span>
        </template>
        <template #cell-status="{ row }">
          <span v-if="row.is_deleted" class="badge badge-muted">
            <span class="badge-dot"></span>
            Dihapus
          </span>
          <span v-else class="badge" :class="`badge-${statusMetaWithIuran(row).variant}`">
            <span class="badge-dot"></span>
            {{ statusMetaWithIuran(row).label }}
          </span>
        </template>
        <template #cell-akses="{ row }">
          <span v-if="row.is_deleted" class="badge badge-outline badge-muted">
            <span class="badge-dot"></span>
            Tidak berlaku
          </span>
          <span
              v-else
              class="badge badge-outline"
              :class="`badge-${accessMeta(row).variant}`"
              :title="row.access?.message"
          >
            <span class="badge-dot"></span>
            {{ accessMeta(row).allowed ? 'Boleh' : 'Ditolak' }} · {{ accessMeta(row).label }}
          </span>
        </template>
        <template #cell-blacklist_reason="{ row }">
          <button
              v-if="row.is_blacklisted && row.blacklist_reason"
              type="button"
              class="reason-link"
              @click="openReason(row)"
          >
            <svg viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M2 3.5C2 2.67 2.67 2 3.5 2h9c.83 0 1.5.67 1.5 1.5v7c0 .83-.67 1.5-1.5 1.5H6l-3 2.5v-2.5h-.5C1.67 12 1 11.33 1 10.5v-7z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/>
            </svg>
            Lihat Keterangan
          </button>
          <span v-else-if="row.is_blacklisted" class="text-muted">Tanpa keterangan</span>
          <span v-else class="text-muted">-</span>
        </template>
        <template #cell-aksi="{ row }">
          <div class="table-actions" style="justify-content: flex-end">
            <RouterLink :to="{ name: 'kartu.edit', params: { id: row.id } }">
              <Button variant="secondary" size="sm">Edit</Button>
            </RouterLink>
            <Button
                v-if="row.is_blacklisted || row.status === 3"
                variant="secondary"
                size="sm"
                @click="handleClearBlacklist(row)"
            >
              Aktifkan
            </Button>
            <Button v-else variant="warning" size="sm" @click="openBlacklist(row)">
              Blacklist
            </Button>
            <Button variant="danger" size="sm" @click="confirmDelete(row)">Hapus</Button>
          </div>
        </template>
      </DataTable>
    </div>

    <!-- Delete confirmation -->
    <Modal :model-value="!!deleteTarget" title="Hapus Kartu Akses" @update:model-value="deleteTarget = null">
      <div class="modal-notice modal-notice-danger">
        <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M10 2 1.5 17h17L10 2z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
          <path d="M10 8v4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
          <circle cx="10" cy="14.5" r="0.9" fill="currentColor"/>
        </svg>
        <p>Tindakan ini tidak dapat dibatalkan. Kartu akan dihapus secara permanen.</p>
      </div>
      <div class="target-box">
        <span class="target-label">Nomor Kartu</span>
        <strong>{{ deleteTarget?.card_number }}</strong>
        <span class="target-owner">{{ deleteTarget?.user?.name || 'Tanpa pemilik' }}</span>
      </div>
      <template #footer>
        <Button variant="secondary" @click="deleteTarget = null">Batal</Button>
        <Button variant="danger" :loading="deleting" @click="handleDelete">Hapus Kartu</Button>
      </template>
    </Modal>

    <!-- Blacklist confirmation -->
    <Modal :model-value="!!blacklistTarget" title="Blacklist Kartu" @update:model-value="blacklistTarget = null">
      <p class="modal-lead">
        Kartu berikut akan diblokir dan tidak dapat digunakan untuk tab in / tab out sampai diaktifkan kembali.
      </p>
      <div class="target-box">
        <span class="target-label">Nomor Kartu</span>
        <strong>{{ blacklistTarget?.card_number }}</strong>
        <span class="target-owner">{{ blacklistTarget?.user?.name || 'Tanpa pemilik' }}</span>
      </div>
      <div class="form-group">
        <label class="form-label">Alasan (opsional)</label>
        <input
            v-model="blacklistReason"
            type="text"
            class="form-control"
            placeholder="Contoh: Tunggakan pembayaran belum diselesaikan"
        />
      </div>
      <template #footer>
        <Button variant="secondary" @click="blacklistTarget = null">Batal</Button>
        <Button variant="warning" :loading="blacklisting" @click="handleBlacklist">Blacklist Kartu</Button>
      </template>
    </Modal>

    <!-- Blacklist reason detail -->
    <Modal :model-value="!!reasonTarget" title="Keterangan Blacklist" @update:model-value="reasonTarget = null">
      <div class="target-box">
        <span class="target-label">Nomor Kartu</span>
        <strong>{{ reasonTarget?.card_number }}</strong>
        <span class="target-owner">{{ reasonTarget?.user?.name || 'Tanpa pemilik' }}</span>
      </div>
      <div class="reason-box">
        {{ reasonTarget?.blacklist_reason || 'Tanpa keterangan.' }}
      </div>
      <template #footer>
        <Button variant="secondary" @click="reasonTarget = null">Tutup</Button>
      </template>
    </Modal>
  </div>
</template>

<style scoped>
/* ===== Tabs ===== */
.tabs-header {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 0 12px;
  border-bottom: 1px solid var(--color-border, #e3e5e9);
  background: var(--color-surface, #fff);
}
.tab-btn {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  flex: 0 0 auto;
  padding: 12px 14px;
  border: none;
  background: transparent;
  color: var(--color-text-muted, #8a8f98);
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  position: relative;
  transition: color 0.15s ease;
  border-bottom: 2px solid transparent;
  margin-bottom: -1px;
}
.tab-icon {
  width: 14px;
  height: 14px;
  flex-shrink: 0;
  opacity: 0.85;
}
.tab-btn:hover {
  color: var(--color-text, #1f2328);
}
.tab-btn.active {
  color: var(--color-primary, #3b6fe0);
  border-bottom-color: var(--color-primary, #3b6fe0);
}
.tab-btn-danger.active {
  color: var(--color-danger, #c53030);
  border-bottom-color: var(--color-danger, #c53030);
}

/* ===== Toolbar ===== */
.toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
}
.toolbar-fields {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  flex: 1;
  min-width: 0;
}
.search-wrap {
  position: relative;
  flex: 1;
  min-width: 220px;
  max-width: 300px;
}
.search-icon {
  position: absolute;
  left: 10px;
  top: 50%;
  transform: translateY(-50%);
  width: 16px;
  height: 16px;
  color: var(--color-text-muted, #8a8f98);
  pointer-events: none;
}
.search-input {
  width: 100%;
  padding-left: 32px;
}
.filter-select {
  max-width: 170px;
  flex-shrink: 0;
}
.reset-filter {
  border: none;
  background: none;
  color: var(--color-primary, #3b6fe0);
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  padding: 6px 4px;
  white-space: nowrap;
}
.reset-filter:hover {
  text-decoration: underline;
}
.toolbar-summary {
  display: flex;
  align-items: center;
  gap: 14px;
  font-size: 13px;
  color: var(--color-text-muted, #8a8f98);
  white-space: nowrap;
}
.summary-total {
  font-weight: 600;
  color: var(--color-text, #1f2328);
}
.summary-blacklist,
.summary-deleted {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

/* ===== Small status dots ===== */
.dot {
  width: 7px;
  height: 7px;
  border-radius: 999px;
  display: inline-block;
  flex-shrink: 0;
}
.dot-danger { background: var(--color-danger, #e5484d); }
.dot-success { background: var(--color-success, #30a46c); }
.dot-muted { background: var(--color-text-muted, #8a8f98); }

/* ===== Badges (status / akses) ===== */
.badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 3px 10px;
  border-radius: 999px;
  font-size: 12.5px;
  font-weight: 600;
  line-height: 1.6;
  white-space: nowrap;
}
.badge-dot {
  width: 6px;
  height: 6px;
  border-radius: 999px;
  background: currentColor;
  flex-shrink: 0;
}
.badge-success { background: rgba(48, 164, 108, 0.12); color: var(--color-success, #1a7f4e); }
.badge-danger { background: rgba(229, 72, 77, 0.12); color: var(--color-danger, #c53030); }
.badge-warning { background: rgba(245, 166, 35, 0.14); color: var(--color-warning, #b7791f); }
.badge-muted { background: rgba(138, 143, 152, 0.14); color: var(--color-text-muted, #626871); }
.badge-outline {
  background: transparent;
  border: 1px solid currentColor;
}

/* ===== RFID chip ===== */
.rfid-chip {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 12px;
  background: var(--color-bg, #f5f6f8);
  border: 1px solid var(--color-border, #e3e5e9);
  padding: 2px 8px;
  border-radius: var(--radius-sm, 6px);
  color: var(--color-text, #1f2328);
}
.is-deleted-chip {
  opacity: 0.6;
}
.is-deleted-text {
  color: var(--color-text-muted, #8a8f98);
  text-decoration: line-through;
  text-decoration-color: var(--color-border, #d7dae0);
}

/* ===== Date range ===== */
.date-range {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  white-space: nowrap;
}
.date-range-sep {
  color: var(--color-text-muted, #8a8f98);
}

/* ===== Iuran cell ===== */
.iuran-cell {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 5px;
}
.iuran-deadline {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 12px;
  color: var(--color-text-muted, #8a8f98);
  white-space: nowrap;
}
.iuran-deadline svg {
  width: 12px;
  height: 12px;
  flex-shrink: 0;
  opacity: 0.8;
}

/* ===== Blacklist reason link ===== */
.reason-link {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  border: 1px solid var(--color-border, #e3e5e9);
  background: var(--color-surface, #fff);
  color: var(--color-text, #1f2328);
  font-size: 12.5px;
  font-weight: 600;
  padding: 5px 10px;
  border-radius: var(--radius-sm, 6px);
  cursor: pointer;
  transition: background 0.15s ease, border-color 0.15s ease;
}
.reason-link svg {
  width: 14px;
  height: 14px;
  color: var(--color-text-muted, #8a8f98);
}
.reason-link:hover {
  background: var(--color-bg, #f5f6f8);
  border-color: var(--color-text-muted, #c7cad0);
}

/* ===== Modal content ===== */
.modal-lead {
  margin: 0 0 14px;
  font-size: 14px;
  color: var(--color-text, #1f2328);
  line-height: 1.6;
}
.modal-notice {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 12px 14px;
  border-radius: var(--radius-sm, 8px);
  margin-bottom: 16px;
  font-size: 13.5px;
  line-height: 1.5;
}
.modal-notice p { margin: 0; }
.modal-notice-danger {
  background: rgba(229, 72, 77, 0.08);
  color: var(--color-danger, #c53030);
}
.modal-notice svg {
  width: 18px;
  height: 18px;
  flex-shrink: 0;
  margin-top: 1px;
}
.target-box {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 12px 14px;
  background: var(--color-bg, #f5f6f8);
  border: 1px solid var(--color-border, #e3e5e9);
  border-radius: var(--radius-sm, 8px);
  margin-bottom: 16px;
}
.target-label {
  font-size: 11.5px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-text-muted, #8a8f98);
}
.target-box strong {
  font-size: 15px;
  color: var(--color-text, #1f2328);
}
.target-owner {
  font-size: 13px;
  color: var(--color-text-muted, #8a8f98);
}
.reason-box {
  padding: 12px 14px;
  background: var(--color-bg, #f5f6f8);
  border: 1px solid var(--color-border, #e3e5e9);
  border-radius: var(--radius-sm, 6px);
  font-size: 14px;
  line-height: 1.6;
  color: var(--color-text, #1f2328);
  white-space: pre-wrap;
  word-break: break-word;
}
.text-muted {
  color: var(--color-text-muted, #8a8f98);
}

/* ===== Responsive ===== */
@media (max-width: 720px) {
  .toolbar {
    flex-direction: column;
    align-items: stretch;
  }
  .toolbar-fields {
    flex-direction: column;
    align-items: stretch;
  }
  .search-wrap,
  .filter-select {
    max-width: none;
  }
  .toolbar-summary {
    justify-content: space-between;
  }
}
</style>