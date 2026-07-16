<script setup>
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useUserStore } from '@/stores/user'
import { useToast } from '@/composables/useToast'
import { extractValidationErrors, extractErrorMessage } from '@/utils/helper'
import PageHeader from '@/components/layout/Header.vue'
import UserForm from '@/components/forms/UserForm.vue'

const router = useRouter()
const store = useUserStore()
const toast = useToast()

const form = reactive({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
  role: 'user',
  type: 'warga',
  phone: '',
  no_kk: '',
})

const errors = ref({})

async function handleSubmit() {
  errors.value = {}
  try {
    await store.create({ ...form })
    toast.success('Pengguna berhasil ditambahkan.')
    router.push({ name: 'users.index' })
  } catch (error) {
    errors.value = extractValidationErrors(error)
    toast.error(extractErrorMessage(error, 'Gagal menyimpan pengguna.'))
  }
}
</script>

<template>
  <div class="page">
    <PageHeader title="Tambah Data Warga" subtitle="Buat akun data warga baru" />

    <div class="card">
      <div class="card-body">
        <UserForm
          :form="form"
          :errors="errors"
          :saving="store.saving"
          @submit="handleSubmit"
          @cancel="router.push({ name: 'users.index' })"
        />
      </div>
    </div>
  </div>
</template>
