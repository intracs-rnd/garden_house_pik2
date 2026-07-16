<script setup>
import { onMounted, ref, computed } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import { useKendaraanStore } from '@/stores/kendaraan'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { debounce, extractErrorMessage } from '@/utils/helper'
import PageHeader from '@/components/layout/Header.vue'
import Button from '@/components/common/Button.vue'
import DataTable from '@/components/common/DataTable.vue'
import Modal from '@/components/common/Modal.vue'

const router = useRouter()
const store = useKendaraanStore()
const auth = useAuthStore()
const toast = useToast()

const deleteTarget = ref(null)
const deleting = ref(false)

const columns = computed(() => {
  const base = [
    { key: 'nama', label: 'Nama', cellClass: 'fw-600' },
    { key: 'nomor_plat', label: 'Nomor Plat' },
    { key: 'merk', label: 'Merk / Model' },
    { key: 'tahun', label: 'Tahun' },
    { key: 'pemilik', label: 'Pemilik' },
  ]
  if (auth.canManage('kendaraan')) {
    base.push({ key: 'aksi', label: 'Aksi', align: 'right' })
  }
  return base
})

const onSearch = debounce(() => store.fetchList(1), 400)

function changePage(page) {
  store.fetchList(page)
}

function changePerPage(perPage) {
  store.setPerPage(perPage)
}

function confirmDelete(item) {
  deleteTarget.value = item
}

async function handleDelete() {
  if (!deleteTarget.value) return
  deleting.value = true
  try {
    await store.remove(deleteTarget.value.id)
    toast.success('Kendaraan berhasil dihapus.')
    deleteTarget.value = null
    const page =
      store.items.length === 1 && store.meta.current_page > 1
        ? store.meta.current_page - 1
        : store.meta.current_page
    store.fetchList(page)
  } catch (error) {
    toast.error(extractErrorMessage(error, 'Gagal menghapus kendaraan.'))
  } finally {
    deleting.value = false
  }
}

onMounted(() => {
  store.fetchList()
})
</script>

<template>
  <div class="page">
    <PageHeader title="Kendaraan" subtitle="Kelola data kendaraan">
      <template #actions>
        <Button v-if="auth.canManage('kendaraan')" variant="primary" @click="router.push({ name: 'kendaraan.create' })">
          + Tambah Kendaraan
        </Button>
      </template>
    </PageHeader>

    <div class="card">
      <div class="card-header toolbar">
        <input
          v-model="store.filters.search"
          type="text"
          class="form-control search-input"
          placeholder="Cari nama, plat, merk..."
          @input="onSearch"
        />

      </div>

      <DataTable
        :columns="columns"
        :rows="store.items"
        :loading="store.loading"
        :refreshing="store.refreshing"
        :error="store.error"
        show-index
        loading-text="Memuat kendaraan..."
        empty-text="Belum ada data kendaraan."
        :page="store.meta.current_page"
        :per-page="store.meta.per_page"
        :total="store.meta.total"
        :last-page="store.meta.last_page"
        :per-page-options="[10, 15, 25, 50, 100]"
        @change-page="changePage"
        @change-per-page="changePerPage"
      >
        <template #cell-merk="{ row }">
          {{ [row.merk, row.model].filter(Boolean).join(' ') || '-' }}
        </template>
        <template #cell-tahun="{ row }">{{ row.tahun || '-' }}</template>
        <template #cell-pemilik="{ row }">{{ row.user?.name || '-' }}</template>
        <template #cell-aksi="{ row }">
          <div class="table-actions" style="justify-content: flex-end">
            <RouterLink :to="{ name: 'kendaraan.edit', params: { id: row.id } }">
              <Button variant="secondary" size="sm">Edit</Button>
            </RouterLink>
            <Button variant="danger" size="sm" @click="confirmDelete(row)">Hapus</Button>
          </div>
        </template>
      </DataTable>
    </div>

    <Modal :model-value="!!deleteTarget" title="Hapus Kendaraan" @update:model-value="deleteTarget = null">
      <p>
        Yakin ingin menghapus kendaraan
        <strong>{{ deleteTarget?.nama }}</strong> ({{ deleteTarget?.nomor_plat }})?
      </p>
      <template #footer>
        <Button variant="secondary" @click="deleteTarget = null">Batal</Button>
        <Button variant="danger" :loading="deleting" @click="handleDelete">Hapus</Button>
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
</style>
