<script setup>
import { reactive, ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useKendaraanStore } from '@/stores/kendaraan'
import { useToast } from '@/composables/useToast'
import { extractValidationErrors, extractErrorMessage } from '@/utils/helper'
import PageHeader from '@/components/layout/Header.vue'
import KendaraanForm from '@/components/forms/KendaraanForm.vue'

const router = useRouter()
const store = useKendaraanStore()
const toast = useToast()

const errors = ref({})

const form = reactive({
  user_id: '',
  nama: '',
  nomor_plat: '',
  merk: '',
  model: '',
  tahun: null,
})

function buildPayload() {
  const payload = { ...form }
  // Strip empty optional fields so backend validation stays happy.
  Object.keys(payload).forEach((key) => {
    if (payload[key] === '' || payload[key] === null) delete payload[key]
  })
  // Always send the owner explicitly (id or null) so it can be assigned/cleared.
  payload.user_id = form.user_id || null
  return payload
}

async function handleSubmit() {
  errors.value = {}
  try {
    await store.create(buildPayload())
    toast.success('Kendaraan berhasil ditambahkan.')
    router.push({ name: 'kendaraan.index' })
  } catch (error) {
    errors.value = extractValidationErrors(error)
    toast.error(extractErrorMessage(error, 'Gagal menyimpan kendaraan.'))
  }
}

onMounted(() => store.fetchUsers())
</script>

<template>
  <div class="page">
    <PageHeader title="Tambah Kendaraan" subtitle="Daftarkan kendaraan baru" />

    <div class="card">
      <div class="card-body">
        <KendaraanForm
          :form="form"
          :errors="errors"
          :users="store.users"
          :saving="store.saving"
          @submit="handleSubmit"
          @cancel="router.push({ name: 'kendaraan.index' })"
        />
      </div>
    </div>
  </div>
</template>
