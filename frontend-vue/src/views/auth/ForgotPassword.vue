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
        <span class="auth-logo">GH</span>
        <h1>Lupa Password</h1>
        <p>Masukkan email Anda untuk mengatur ulang password</p>
      </div>

      <div v-if="generalError" class="alert alert-danger">{{ generalError }}</div>

      <form @submit.prevent="handleSubmit">
        <div class="form-group">
          <label class="form-label">Email</label>
          <input
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

        <Button variant="primary" type="submit" block :loading="auth.loading">
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

<style scoped>
.auth-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  background:  url('@/assets/images/perumahan_pik2.png');
  background-size: cover;
  background-position: center;
  background-repeat: no-repeat;
  background-attachment: fixed;
  position: relative;
  overflow: hidden;
}
.auth-card {
  width: 100%;
  max-width: 420px;
  background: rgba(255, 255, 255, 0.98);
  backdrop-filter: blur(20px);
  border-radius: var(--radius-lg);
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4), 0 0 1px rgba(255, 255, 255, 0.5) inset;
  padding: 36px 32px;
  border: 1px solid rgba(255, 255, 255, 0.3);
}
.auth-brand {
  text-align: center;
  margin-bottom: 24px;
}
.auth-logo {
  display: inline-grid;
  place-items: center;
  width: 56px;
  height: 56px;
  background: var(--color-primary);
  color: #fff;
  border-radius: 14px;
  font-weight: 700;
  font-size: 20px;
  margin-bottom: 14px;
}
.auth-brand h1 {
  font-size: 22px;
}
.auth-brand p {
  margin-top: 6px;
  color: var(--color-text-muted);
  font-size: 14px;
}
.auth-footer {
  margin-top: 20px;
  text-align: center;
  font-size: 14px;
  color: var(--color-text-muted);
}
.auth-footer a {
  color: var(--color-primary);
  font-weight: 500;
}
</style>
