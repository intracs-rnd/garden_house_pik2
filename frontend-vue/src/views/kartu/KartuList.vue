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
  if (auth.canManage('kartu')) {
    base.push({ key: 'aksi', label: 'Aksi', align: 'right' })
  }
  return base
})

const onSearch = debounce(() => store.fetchList(1), 400)

function applyFilters() {
  store.fetchList(1)
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
  const reason = item.access?.reason
  const meta = kartuReasonMeta(reason)
  return {
    allowed: item.access?.allowed ?? false,
    label: meta.label,
    variant: item.access?.allowed ? meta.variant : 'danger',
  }
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
      <div class="card-header toolbar">
        <input
          v-model="store.filters.search"
          type="text"
          class="form-control search-input"
          placeholder="Cari nomor kartu, nama, pemilik..."
          @input="onSearch"
        />
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
      </div>

      <DataTable
        :columns="columns"
        :rows="store.items"
        :loading="store.loading"
        :refreshing="store.refreshing"
        :error="store.error"
        loading-text="Memuat kartu akses..."
        empty-text="Belum ada data kartu akses."
        :page="store.meta.current_page"
        :per-page="store.meta.per_page"
        :total="store.meta.total"
        :last-page="store.meta.last_page"
        :per-page-options="[10, 15, 25, 50, 100]"
        @change-page="changePage"
        @change-per-page="changePerPage"
      >
        <template #cell-nama="{ row }">{{ row.nama || '-' }}</template>
        <template #cell-rfid_tag="{ row }">{{ row.rfid_tag || '-' }}</template>
        <template #cell-pemilik="{ row }">{{ row.user?.name || '-' }}</template>
        <template #cell-masa_berlaku="{ row }">
          <template v-if="row.valid_until">
            {{ formatDateTime(row.valid_from) }} – {{ formatDateTime(row.valid_until) }}
          </template>
          <template v-else>-</template>
        </template>
        <template #cell-tenggang="{ row }">
          {{ row.grace_days ? `${row.grace_days} hari` : '-' }}
        </template>
        <template #cell-iuran="{ row }">
          <template v-if="row.iuran && row.iuran.exists">
            <span :class="{ 'text-danger': row.iuran.status === 'terlambat' }">
              {{ row.iuran.status_label }} · {{ formatDateTime(row.iuran.deadline) || '-' }}
            </span>
          </template>
          <span v-else class="text-muted">Tidak ada</span>
        </template>
        <template #cell-status="{ row }">
          <span class="badge" :class="`badge-${statusMeta(row.status).variant}`">
            {{ statusMeta(row.status).label }}
          </span>
        </template>
        <template #cell-akses="{ row }">
          <span class="badge" :class="`badge-${accessMeta(row).variant}`" :title="row.access?.message">
            {{ accessMeta(row).allowed ? 'Boleh' : 'Ditolak' }} · {{ accessMeta(row).label }}
          </span>
        </template>
        <template #cell-blacklist_reason="{ row }">
          <Button
            v-if="row.is_blacklisted && row.blacklist_reason"
            variant="secondary"
            size="sm"
            @click="openReason(row)"
          >
            Lihat Keterangan
          </Button>
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
      <p>
        Yakin ingin menghapus kartu
        <strong>{{ deleteTarget?.card_number }}</strong>
        milik {{ deleteTarget?.user?.name || 'pengguna ini' }}?
      </p>
      <template #footer>
        <Button variant="secondary" @click="deleteTarget = null">Batal</Button>
        <Button variant="danger" :loading="deleting" @click="handleDelete">Hapus</Button>
      </template>
    </Modal>

    <!-- Blacklist confirmation -->
    <Modal :model-value="!!blacklistTarget" title="Blacklist Kartu" @update:model-value="blacklistTarget = null">
      <p>
        Blokir kartu <strong>{{ blacklistTarget?.card_number }}</strong> sehingga tidak dapat
        digunakan untuk tab in / tab out.
      </p>
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
        <Button variant="warning" :loading="blacklisting" @click="handleBlacklist">Blacklist</Button>
      </template>
    </Modal>

    <!-- Blacklist reason detail -->
    <Modal :model-value="!!reasonTarget" title="Keterangan Blacklist" @update:model-value="reasonTarget = null">
      <p class="reason-meta">
        Kartu <strong>{{ reasonTarget?.card_number }}</strong>
        milik {{ reasonTarget?.user?.name || 'pengguna ini' }}
      </p>
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
.toolbar {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
}
.search-input {
  max-width: 280px;
  flex: 1;
}
.filter-select {
  max-width: 180px;
}
.reason-meta {
  margin: 0 0 12px;
  font-size: 14px;
  color: var(--color-text-muted);
}
.reason-box {
  padding: 12px 14px;
  background: var(--color-bg);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  font-size: 14px;
  line-height: 1.6;
  color: var(--color-text);
  white-space: pre-wrap;
  word-break: break-word;
}
.text-muted {
  color: var(--color-text-muted);
}
</style>
