<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useUserStore } from '@/stores/user'
import { useToast } from '@/composables/useToast'
import { extractValidationErrors, extractErrorMessage } from '@/utils/helper'
import PageHeader from '@/components/layout/Header.vue'
import UserForm from '@/components/forms/UserForm.vue'
import Loader from '@/components/common/Loader.vue'

const props = defineProps({
  id: { type: [String, Number], required: true },
})

const router = useRouter()
const store = useUserStore()
const toast = useToast()

const loading = ref(true)
const errors = ref({})

const form = reactive({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
  role: 'user',
  type: 'warga',
  phone: '',
  no_kk: '',
  is_active: true,
})

async function handleSubmit() {
  errors.value = {}
  const payload = { ...form }
  // Do not send an empty password on update.
  if (!payload.password) {
    delete payload.password
    delete payload.password_confirmation
  }
  try {
    await store.update(props.id, payload)
    toast.success('Perubahan berhasil disimpan.')
    router.push({ name: 'users.index' })
  } catch (error) {
    errors.value = extractValidationErrors(error)
    toast.error(extractErrorMessage(error, 'Gagal menyimpan perubahan.'))
  }
}

onMounted(async () => {
  try {
    const user = await store.fetchOne(props.id)
    Object.assign(form, {
      name: user.name || '',
      email: user.email || '',
      password: '',
      password_confirmation: '',
      role: user.role || 'user',
      type: user.type || 'warga',
      phone: user.phone || '',
      no_kk: user.no_kk || '',
      is_active: user.is_active ?? true,
    })
  } catch (error) {
    toast.error(extractErrorMessage(error, 'Data pengguna tidak ditemukan.'))
    router.push({ name: 'users.index' })
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="page">
    <PageHeader title="Edit Data Warga" subtitle="Perbarui informasi data warga" />

    <div class="card">
      <div class="card-body">
        <Loader v-if="loading" text="Memuat data..." />
        <UserForm
          v-else
          :form="form"
          :errors="errors"
          :saving="store.saving"
          is-edit
          @submit="handleSubmit"
          @cancel="router.push({ name: 'users.index' })"
        />
      </div>
    </div>
  </div>
</template>
