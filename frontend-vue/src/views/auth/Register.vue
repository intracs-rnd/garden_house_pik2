<script setup>
import { reactive, ref } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { extractValidationErrors } from '@/utils/helper'
import Button from '@/components/common/Button.vue'

const router = useRouter()
const auth = useAuthStore()
const toast = useToast()

const form = reactive({
  name: '',
  email: '',
  phone: '',
  password: '',
  password_confirmation: '',
})

const errors = ref({})
const generalError = ref('')

async function handleSubmit() {
  errors.value = {}
  generalError.value = ''
  try {
    await auth.register({ ...form })
    toast.success('Registrasi berhasil. Selamat datang!')
    router.push('/dashboard')
  } catch (error) {
    errors.value = extractValidationErrors(error)
    generalError.value = auth.error
  }
}
</script>

<template>
  <div class="auth-page">
    <div class="auth-card">
      <div class="auth-brand">
        <span class="auth-logo">GH</span>
        <h1>Buat Akun</h1>
        <p>Daftar untuk mulai menggunakan panel</p>
      </div>

      <div v-if="generalError" class="alert alert-danger">{{ generalError }}</div>

      <form @submit.prevent="handleSubmit">
        <div class="form-group">
          <label class="form-label">Nama Lengkap</label>
          <input
            v-model="form.name"
            type="text"
            class="form-control"
            :class="{ 'is-invalid': errors.name }"
            placeholder="Nama Anda"
            required
          />
          <span v-if="errors.name" class="form-error">{{ errors.name }}</span>
        </div>

        <div class="form-group">
          <label class="form-label">Email</label>
          <input
            v-model="form.email"
            type="email"
            class="form-control"
            :class="{ 'is-invalid': errors.email }"
            placeholder="nama@email.com"
            required
          />
          <span v-if="errors.email" class="form-error">{{ errors.email }}</span>
        </div>

        <div class="form-group">
          <label class="form-label">No. Telepon</label>
          <input
            v-model="form.phone"
            type="text"
            class="form-control"
            :class="{ 'is-invalid': errors.phone }"
            placeholder="08xxxxxxxxxx"
          />
          <span v-if="errors.phone" class="form-error">{{ errors.phone }}</span>
        </div>

        <div class="form-group">
          <label class="form-label">Password</label>
          <input
            v-model="form.password"
            type="password"
            class="form-control"
            :class="{ 'is-invalid': errors.password }"
            placeholder="Minimal 8 karakter"
            required
          />
          <span v-if="errors.password" class="form-error">{{ errors.password }}</span>
        </div>

        <div class="form-group">
          <label class="form-label">Konfirmasi Password</label>
          <input
            v-model="form.password_confirmation"
            type="password"
            class="form-control"
            placeholder="Ulangi password"
            required
          />
        </div>

        <Button variant="primary" type="submit" block :loading="auth.loading">
          Daftar
        </Button>
      </form>

      <p class="auth-footer">
        Sudah punya akun?
        <RouterLink :to="{ name: 'login' }">Masuk di sini</RouterLink>
      </p>
    </div>
  </div>
</template>

<style scoped src="./auth_css/Register.css"></style>