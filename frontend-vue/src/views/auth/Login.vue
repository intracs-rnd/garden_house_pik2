<script setup>
import { reactive, ref, onMounted } from 'vue'
import { useRouter, useRoute, RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { extractValidationErrors } from '@/utils/helper'
import Button from '@/components/common/Button.vue'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const toast = useToast()

const form = reactive({
  email: '',
  password: '',
})

const errors = ref({})
const generalError = ref('')

async function handleSubmit() {
  errors.value = {}
  generalError.value = ''
  try {
    await auth.login({ ...form })
    toast.success(`Selamat datang, ${auth.userName}!`)
    const redirect = route.query.redirect || '/dashboard'
    router.push(redirect)
  } catch (error) {
    errors.value = extractValidationErrors(error)
    generalError.value = auth.error
  }
}

onMounted(() => {
  if (route.query.reason === 'session_expired') {
    toast.error('Sesi Anda telah berakhir karena akun sedang digunakan di perangkat lain.')
    router.replace({ query: { ...route.query, reason: undefined } })
  }
})
</script>

<template>
  <div class="auth-page">
    <div class="auth-container">
      <!-- Left side - brand panel -->
      <div class="auth-welcome">
        <div class="blueprint-grid" aria-hidden="true"></div>

        <div class="welcome-content">
          <span class="eyebrow">Portal Penghuni</span>

          <h2>Rumah Anda,<br />satu genggaman.</h2>
          <p>Kelola unit, fasilitas, dan administrasi Garden House PIK2 tanpa perlu ke kantor pengelola.</p>

          <!-- Signature element: self-drawing house line-art -->
          <svg class="house-sketch" viewBox="0 0 220 160" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path class="stroke-draw" d="M20 150V70L110 15L200 70V150" stroke="#C9A96E" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            <path class="stroke-draw delay-1" d="M45 150V95H85V150" stroke="#C9A96E" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
            <path class="stroke-draw delay-2" d="M130 105H165V135H130Z" stroke="#C9A96E" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
            <path class="stroke-draw delay-2" d="M110 15V150" stroke="#C9A96E" stroke-width="1" stroke-dasharray="3 4" opacity="0.5"/>
            <path class="stroke-draw delay-3" d="M20 150H200" stroke="#C9A96E" stroke-width="2"/>
            <path class="leaf-draw" d="M195 55C205 45 210 30 205 18C193 25 186 38 189 50C191 55 193 56 195 55Z" stroke="#8FAE7A" stroke-width="1.6" stroke-linejoin="round"/>
          </svg>

          <ul class="features">
            <li><span class="tick">✓</span>Kelola data penghuni</li>
            <li><span class="tick">✓</span>Monitor fasilitas bersama</li>
            <li><span class="tick">✓</span>Laporan iuran terperinci</li>
          </ul>
        </div>
      </div>

      <!-- Right side - Login form -->
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
          <h1>GARDEN HOUSE PIK2</h1>
          <p>Portal Manajemen Perumahan</p>
        </div>

        <div v-if="generalError" class="alert alert-danger">
          <span class="alert-icon">⚠</span>
          {{ generalError }}
        </div>

        <form @submit.prevent="handleSubmit">
          <div class="form-group">
            <label class="form-label" for="login-email">Email</label>
            <input
              id="login-email"
              v-model="form.email"
              type="email"
              class="form-control"
              :class="{ 'is-invalid': errors.email }"
              placeholder="admin@ghpik2.test"
              autocomplete="email"
              required
            />
            <span v-if="errors.email" class="form-error">{{ errors.email }}</span>
          </div>

          <div class="form-group">
            <label class="form-label" for="login-password">Password</label>
            <input
              id="login-password"
              v-model="form.password"
              type="password"
              class="form-control"
              :class="{ 'is-invalid': errors.password }"
              placeholder="••••••••"
              autocomplete="current-password"
              required
            />
            <span v-if="errors.password" class="form-error">{{ errors.password }}</span>
          </div>

          <Button variant="primary" type="submit" block :loading="auth.loading" class="btn-login">
            Masuk Sekarang
          </Button>
        </form>

        <p class="auth-footer">
          <RouterLink :to="{ name: 'forgot-password' }">Lupa password?</RouterLink>
        </p>
      </div>
    </div>
  </div>
</template>

<style scoped src="./auth_css/Login.css"></style>