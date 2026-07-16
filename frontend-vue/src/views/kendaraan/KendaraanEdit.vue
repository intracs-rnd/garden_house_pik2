<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useKendaraanStore } from '@/stores/kendaraan'
import { useToast } from '@/composables/useToast'
import { extractValidationErrors, extractErrorMessage } from '@/utils/helper'
import PageHeader from '@/components/layout/Header.vue'
import KendaraanForm from '@/components/forms/KendaraanForm.vue'
import Loader from '@/components/common/Loader.vue'

const props = defineProps({
  id: { type: [String, Number], required: true },
})

const router = useRouter()
const store = useKendaraanStore()
const toast = useToast()

const loading = ref(true)
const errors = ref({})

const form = reactive({
  nama: '',
  nomor_plat: '',
  merk: '',
  model: '',
  tahun: null,
})

function buildPayload() {
  const payload = { ...form }
  Object.keys(payload).forEach((key) => {
    if (payload[key] === '' || payload[key] === null) delete payload[key]
  })
  return payload
}

async function handleSubmit() {
  errors.value = {}
  try {
    await store.update(props.id, buildPayload())
    toast.success('Perubahan berhasil disimpan.')
    router.push({ name: 'kendaraan.index' })
  } catch (error) {
    errors.value = extractValidationErrors(error)
    toast.error(extractErrorMessage(error, 'Gagal menyimpan perubahan.'))
  }
}

onMounted(async () => {
  await store.fetchUsers()
  try {
    const data = await store.fetchOne(props.id)
    Object.assign(form, {
      user_id: data.user_id ?? '',
      nama: data.nama ?? '',
      nomor_plat: data.nomor_plat ?? '',
      merk: data.merk ?? '',
      model: data.model ?? '',
      tahun: data.tahun ?? null,
    })
  } catch (error) {
    toast.error(extractErrorMessage(error, 'Data kendaraan tidak ditemukan.'))
    router.push({ name: 'kendaraan.index' })
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="page">
    <PageHeader title="Edit Kendaraan" subtitle="Perbarui data kendaraan" />

    <div class="card">
      <div class="card-body">
        <Loader v-if="loading" text="Memuat data..." />
        <KendaraanForm
          v-else
          :form="form"
          :errors="errors"
          :users="store.users"
          :saving="store.saving"
          is-edit
          @submit="handleSubmit"
          @cancel="router.push({ name: 'kendaraan.index' })"
        />
      </div>
    </div>
  </div>
</template>
