<script setup>
import { onMounted, ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useUserMrStore } from '@/stores/userMr'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { debounce, extractErrorMessage } from '@/utils/helper'
import { formatDate } from '@/utils/formatter'
import PageHeader from '@/components/layout/Header.vue'
import Button from '@/components/common/Button.vue'
import DataTable from '@/components/common/DataTable.vue'
import Modal from '@/components/common/Modal.vue'

const router = useRouter()
const store = useUserMrStore()
const auth = useAuthStore()
const toast = useToast()

const deleteTarget = ref(null)
const deleting = ref(false)

const columns = computed(() => {
  const base = [
    { key: 'name', label: 'Nama', cellClass: 'fw-600' },
    { key: 'username', label: 'Username' },
    { key: 'created_at', label: 'Terbuat' },
  ]
  base.push({ key: 'aksi', label: 'Aksi', align: 'right' })
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
    await store.remove(deleteTarget.value.uuid)
    toast.success('User MR berhasil dihapus.')
    deleteTarget.value = null
    const page =
      store.items.length === 1 && store.meta.current_page > 1
        ? store.meta.current_page - 1
        : store.meta.current_page
    store.fetchList(page)
  } catch (error) {
    toast.error(extractErrorMessage(error, 'Gagal menghapus user MR.'))
  } finally {
    deleting.value = false
  }
}

onMounted(() => store.fetchList())
</script>

<template>
  <div class="page">
    <PageHeader title="User MR" subtitle="Kelola akun user MR sistem">
      <template #actions>
        <Button variant="primary" @click="router.push({ name: 'user-mr.create' })">
          + Tambah User MR
        </Button>
      </template>
    </PageHeader>

    <div class="card">
      <div class="card-header toolbar">
        <input
          v-model="store.filters.search"
          type="text"
          class="form-control search-input"
          placeholder="Cari nama atau username..."
          @input="onSearch"
        />
      </div>

      <DataTable
        :columns="columns"
        :rows="store.items"
        :loading="store.loading"
        :refreshing="store.refreshing"
        :pagination="store.meta"
        @change-page="changePage"
        @change-per-page="changePerPage"
      >
        <template #cell-created_at="{ row }">
          {{ formatDate(row.created_at) }}
        </template>

        <template #cell-aksi="{ row }">
          <div class="action-buttons">
            <Button
              variant="info"
              size="sm"
              @click="router.push({ name: 'user-mr.edit', params: { uuid: row.uuid } })"
            >
              Edit
            </Button>
            <Button variant="danger" size="sm" @click="confirmDelete(row)">
              Hapus
            </Button>
          </div>
        </template>
      </DataTable>
    </div>

    <Modal
      v-if="deleteTarget"
      title="Hapus User MR"
      @close="deleteTarget = null"
      @confirm="handleDelete"
      :loading="deleting"
    >
      <p>
        Apakah Anda yakin ingin menghapus user MR
        <strong>{{ deleteTarget.username }}</strong
        >?
      </p>
      <p style="font-size: 12px; color: var(--color-muted)">Tindakan ini tidak dapat diurungkan.</p>
    </Modal>
  </div>
</template>

<style scoped>
.action-buttons {
  display: flex;
  gap: 8px;
}
</style>
