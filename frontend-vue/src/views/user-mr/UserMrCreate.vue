<script setup>
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useUserMrStore } from '@/stores/userMr'
import { useToast } from '@/composables/useToast'
import { extractValidationErrors, extractErrorMessage } from '@/utils/helper'
import PageHeader from '@/components/layout/Header.vue'
import UserMrForm from '@/components/forms/UserMrForm.vue'

const router = useRouter()
const store = useUserMrStore()
const toast = useToast()

const form = reactive({
  name: '',
  username: '',
  password: '',
  password_confirmation: '',
})

const errors = ref({})

async function handleSubmit() {
  errors.value = {}
  try {
    await store.create({ ...form })
    toast.success('User MR berhasil ditambahkan.')
    router.push({ name: 'user-mr.index' })
  } catch (error) {
    errors.value = extractValidationErrors(error)
    toast.error(extractErrorMessage(error, 'Gagal menyimpan user MR.'))
  }
}
</script>

<template>
  <div class="page">
    <PageHeader title="Tambah User MR" subtitle="Buat akun user MR baru" />

    <div class="card">
      <div class="card-body">
        <UserMrForm
          :form="form"
          :errors="errors"
          :saving="store.saving"
          @submit="handleSubmit"
          @cancel="router.push({ name: 'user-mr.index' })"
        />
      </div>
    </div>
  </div>
</template>
