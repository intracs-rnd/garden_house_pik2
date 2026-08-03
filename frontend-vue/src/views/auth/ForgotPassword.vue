<script setup>
import { reactive, ref } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { extractValidationErrors } from '@/utils/helper'
import Button from '@/components/common/Button.vue'

const router = useRouter()
const auth = useAuthStore()

const form = reactive({
  email: '',
})

const errors = ref({})
const generalError = ref('')

async function handleSubmit() {
  errors.value = {}
  generalError.value = ''
  try {
    // Cek apakah email terdaftar di sistem (tanpa kirim email).
    await auth.forgotPassword({ ...form })
    // Email ditemukan → lanjut ke halaman ubah password.
    router.push({ name: 'reset-password', query: { email: form.email } })
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
        <div class="auth-logo">
          <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M16 5L27 13V27H5V13L16 5Z" stroke="white" stroke-width="1.8" stroke-linejoin="round"/>
            <rect x="13" y="18" width="6" height="9" fill="white"/>
            <rect x="9" y="14" width="3.5" height="3.5" fill="white" opacity="0.85"/>
            <rect x="19.5" y="14" width="3.5" height="3.5" fill="white" opacity="0.85"/>
          </svg>
        </div>
        <h1>Lupa Password</h1>
        <p>Masukkan email Anda untuk mengatur ulang password</p>
      </div>

      <div v-if="generalError" class="alert alert-danger">
        <span class="alert-icon">⚠</span>
        {{ generalError }}
      </div>

      <form @submit.prevent="handleSubmit">
        <div class="form-group">
          <label class="form-label" for="forgot-email">Email</label>
          <input
            id="forgot-email"
            v-model="form.email"
            type="email"
            class="form-control"
            :class="{ 'is-invalid': errors.email }"
            placeholder="nama@email.com"
            autocomplete="email"
            required
          />
          <span v-if="errors.email" class="form-error">{{ errors.email }}</span>
        </div>

        <Button variant="primary" type="submit" block :loading="auth.loading" class="btn-submit">
          Lanjutkan
        </Button>
      </form>

      <p class="auth-footer">
        Ingat password Anda?
        <RouterLink :to="{ name: 'login' }">Kembali ke halaman masuk</RouterLink>
      </p>
    </div>
  </div>
</template>

<style scoped src="./auth_css/ForgotPassword.css"></style>