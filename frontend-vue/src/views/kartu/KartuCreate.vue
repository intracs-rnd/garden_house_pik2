<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useKartuStore } from '@/stores/kartu'
import { useToast } from '@/composables/useToast'
import { extractValidationErrors, extractErrorMessage } from '@/utils/helper'
import PageHeader from '@/components/layout/Header.vue'
import KartuForm from '@/components/forms/KartuForm.vue'

const router = useRouter()
const store = useKartuStore()
const toast = useToast()

const errors = ref({})

const form = reactive({
  user_id: '',
  card_number: '',
  nama: '',
  rfid_tag: '',
  status: 1,
  is_blacklisted: false,
  blacklist_reason: '',
  valid_from: '',
  valid_until: '',
  grace_days: 0,
  keterangan: '',
})

function buildPayload() {
  const payload = { ...form }
  // Strip empty optional fields so backend validation stays happy.
  Object.keys(payload).forEach((key) => {
    if (payload[key] === '' || payload[key] === null) delete payload[key]
  })
  if (!form.is_blacklisted) delete payload.blacklist_reason
  return payload
}

async function handleSubmit() {
  errors.value = {}
  try {
    await store.create(buildPayload())
    toast.success('Kartu akses berhasil ditambahkan.')
    router.push({ name: 'kartu.index' })
  } catch (error) {
    errors.value = extractValidationErrors(error)
    toast.error(extractErrorMessage(error, 'Gagal menyimpan kartu akses.'))
  }
}

onMounted(() => store.fetchUsers())
</script>

<template>
  <div class="page">
    <PageHeader title="Tambah Kartu Akses" subtitle="Daftarkan kartu akses baru" />

    <div class="card">
      <div class="card-body">
        <KartuForm
          :form="form"
          :errors="errors"
          :users="store.users"
          :saving="store.saving"
          @submit="handleSubmit"
          @cancel="router.push({ name: 'kartu.index' })"
        />
      </div>
    </div>
  </div>
</template>
