<script setup>
import { onMounted, ref, computed } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import { useUserStore } from '@/stores/user'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { debounce, extractErrorMessage, USER_ROLE_VARIANT, USER_TYPE_VARIANT } from '@/utils/helper'
import { formatDate, capitalize } from '@/utils/formatter'
import PageHeader from '@/components/layout/Header.vue'
import Button from '@/components/common/Button.vue'
import DataTable from '@/components/common/DataTable.vue'
import Modal from '@/components/common/Modal.vue'

const router = useRouter()
const store = useUserStore()
const auth = useAuthStore()
const toast = useToast()

const deleteTarget = ref(null)
const deleting = ref(false)

const columns = computed(() => {
  const base = [
    { key: 'name', label: 'Nama', cellClass: 'fw-600' },
    { key: 'email', label: 'Email' },
    { key: 'no_kk', label: 'No. KK' },
    { key: 'role', label: 'Peran' },
    { key: 'type', label: 'Tipe' },
    { key: 'phone', label: 'Telepon' },
    { key: 'status', label: 'Status' },
    { key: 'created_at', label: 'Terdaftar' },
  ]
  if (auth.canManage('users')) {
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

function confirmDelete(user) {
  deleteTarget.value = user
}

async function handleDelete() {
  if (!deleteTarget.value) return
  deleting.value = true
  try {
    await store.remove(deleteTarget.value.id)
    toast.success('Akun Warga berhasil dihapus.')
    deleteTarget.value = null
    const page =
      store.items.length === 1 && store.meta.current_page > 1
        ? store.meta.current_page - 1
        : store.meta.current_page
    store.fetchList(page)
  } catch (error) {
    toast.error(extractErrorMessage(error, 'Gagal menghapus warga.'))
  } finally {
    deleting.value = false
  }
}

onMounted(() => store.fetchList())
</script>

<template>
  <div class="page">
    <PageHeader title="Data Warga" subtitle="Kelola akun data warga sistem">
      <template #actions>
        <Button v-if="auth.canManage('users')" variant="primary" @click="router.push({ name: 'users.create' })">
          + Tambah akun warga
        </Button>
      </template>
    </PageHeader>

    <div class="card">
      <div class="card-header toolbar">
        <input
          v-model="store.filters.search"
          type="text"
          class="form-control search-input"
          placeholder="Cari nama, email, atau no. KK..."
          @input="onSearch"
        />
      </div>

      <DataTable
        :columns="columns"
        :rows="store.items"
        :loading="store.loading"
        :refreshing="store.refreshing"
        :error="store.error"
        loading-text="Memuat data warga..."
        empty-text="Belum ada data warga."
        :page="store.meta.current_page"
        :per-page="store.meta.per_page"
        :total="store.meta.total"
        :last-page="store.meta.last_page"
        :per-page-options="[10, 15, 25, 50, 100]"
        @change-page="changePage"
        @change-per-page="changePerPage"
      >
        <template #cell-role="{ row }">
          <span class="badge" :class="`badge-${USER_ROLE_VARIANT[row.role] || 'muted'}`">
            {{ capitalize(row.role || 'user') }}
          </span>
        </template>
        <template #cell-type="{ row }">
          <span class="badge" :class="`badge-${USER_TYPE_VARIANT[row.type] || 'muted'}`">
            {{ capitalize(row.type || 'warga') }}
          </span>
        </template>
        <template #cell-phone="{ row }">{{ row.phone || '-' }}</template>
        <template #cell-no_kk="{ row }">{{ row.no_kk || '-' }}</template>
        <template #cell-status="{ row }">
          <span class="badge" :class="row.is_active ? 'badge-success' : 'badge-muted'">
            {{ row.is_active ? 'Aktif' : 'Nonaktif' }}
          </span>
        </template>
        <template #cell-created_at="{ row }">{{ formatDate(row.created_at) }}</template>
        <template #cell-aksi="{ row }">
          <div class="table-actions" style="justify-content: flex-end">
            <RouterLink :to="{ name: 'users.edit', params: { id: row.id } }">
              <Button variant="secondary" size="sm">Edit</Button>
            </RouterLink>
            <Button variant="danger" size="sm" @click="confirmDelete(row)">Hapus</Button>
          </div>
        </template>
      </DataTable>
    </div>

    <!-- Delete confirmation -->
    <Modal :model-value="!!deleteTarget" title="Hapus Akun Warga" @update:model-value="deleteTarget = null">
      <p>
        Yakin ingin menghapus akun warga
        <strong>{{ deleteTarget?.name }}</strong>? Tindakan ini tidak dapat dibatalkan.
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
}
.search-input {
  max-width: 320px;
}
</style>
