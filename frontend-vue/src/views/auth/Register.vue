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

<style scoped>
.auth-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  background: linear-gradient(135deg, #4f46e5 0%, #1e293b 100%);
}
.auth-card {
  width: 100%;
  max-width: 420px;
  background: #fff;
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-lg);
  padding: 36px 32px;
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
