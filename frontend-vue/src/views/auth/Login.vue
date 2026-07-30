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
          <h1>GH PIK2</h1>
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

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Inter:wght@400;500;600;700&display=swap');

* {
  box-sizing: border-box;
}

.auth-page {
  --forest: #1E293B;
  --forest-deep: #0F172A;
  --brass: #C9A96E;
  --brass-light: #E3CFA0;
  --sage: #8FAE7A;
  --cream: #F7F4EC;
  --ink: #23201A;
  --ink-soft: #6B6355;
  --danger: #C24A3E;

  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 32px;
  background-image: url('@/assets/images/perumahan_pik2.png');
  background-size: cover;
  background-position: center;
  background-attachment: fixed;
  font-family: 'Inter', -apple-system, sans-serif;
  position: relative;
}

.auth-page::before {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(160deg, rgba(15, 23, 42, 0.75) 0%, rgba(15, 23, 42, 0.55) 100%);
}

.auth-container {
  position: relative;
  z-index: 1;
  display: grid;
  grid-template-columns: 1.05fr 0.95fr;
  max-width: 980px;
  width: 100%;
  border-radius: 24px;
  overflow: hidden;
  box-shadow: 0 30px 80px rgba(0, 0, 0, 0.45);
}

/* ---------- Left panel ---------- */
.auth-welcome {
  position: relative;
  background: linear-gradient(165deg, rgba(30, 41, 59, 0.92) 0%, rgba(15, 23, 42, 0.96) 100%);
  backdrop-filter: blur(2px);
  color: white;
  padding: 56px 48px;
  display: flex;
  align-items: center;
  overflow: hidden;
}

.blueprint-grid {
  position: absolute;
  inset: 0;
  opacity: 0.07;
  background-image:
    linear-gradient(white 1px, transparent 1px),
    linear-gradient(90deg, white 1px, transparent 1px);
  background-size: 28px 28px;
  pointer-events: none;
}

.welcome-content {
  position: relative;
  z-index: 1;
  width: 100%;
}

.eyebrow {
  display: inline-block;
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--brass-light);
  border-bottom: 1px solid rgba(201, 169, 110, 0.4);
  padding-bottom: 6px;
  margin-bottom: 22px;
}

.auth-welcome h2 {
  font-family: 'Fraunces', serif;
  font-weight: 600;
  font-size: 34px;
  line-height: 1.18;
  margin: 0 0 14px;
  letter-spacing: -0.01em;
}

.auth-welcome p {
  font-size: 15px;
  line-height: 1.6;
  color: rgba(247, 244, 236, 0.75);
  max-width: 340px;
  margin: 0 0 28px;
}

.house-sketch {
  width: 100%;
  max-width: 160px;
  height: auto;
  display: block;
  margin: 0 0 26px;
  opacity: 0.85;
}

.stroke-draw,
.leaf-draw {
  stroke-dasharray: 420;
  stroke-dashoffset: 420;
  animation: draw 1.4s cubic-bezier(0.65, 0, 0.35, 1) forwards;
}

.delay-1 { animation-delay: 0.35s; }
.delay-2 { animation-delay: 0.6s; }
.delay-3 { animation-delay: 0.9s; }
.leaf-draw { animation-delay: 1.05s; stroke-dasharray: 140; stroke-dashoffset: 140; }

@keyframes draw {
  to { stroke-dashoffset: 0; }
}

@media (prefers-reduced-motion: reduce) {
  .stroke-draw, .leaf-draw {
    animation: none;
    stroke-dashoffset: 0;
  }
}

.features {
  list-style: none;
  margin: 0;
  padding: 20px 0 0;
  border-top: 1px solid rgba(255, 255, 255, 0.12);
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.features li {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 13.5px;
  color: rgba(247, 244, 236, 0.9);
}

.tick {
  color: var(--sage);
  font-weight: 700;
  font-size: 13px;
}

/* ---------- Right panel ---------- */
.auth-card {
  background: rgba(255, 255, 255, 0.97);
  backdrop-filter: blur(12px);
  padding: 56px 48px;
  display: flex;
  flex-direction: column;
  justify-content: center;
}

.auth-brand {
  text-align: left;
  margin-bottom: 32px;
}

.auth-logo {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 52px;
  height: 52px;
  background: linear-gradient(150deg, var(--forest) 0%, var(--forest-deep) 100%);
  border-radius: 14px;
  margin-bottom: 18px;
  box-shadow: 0 8px 18px rgba(30, 41, 59, 0.28);
}

.auth-logo svg {
  width: 28px;
  height: 28px;
}

.auth-brand h1 {
  font-family: 'Fraunces', serif;
  font-weight: 600;
  font-size: 24px;
  color: var(--ink);
  margin: 0 0 4px;
}

.auth-brand p {
  font-size: 13.5px;
  color: var(--ink-soft);
  margin: 0;
}

.form-group {
  margin-bottom: 20px;
}

.form-label {
  display: block;
  font-weight: 600;
  font-size: 13px;
  color: var(--ink);
  margin-bottom: 8px;
}

.form-control {
  width: 100%;
  padding: 13px 16px;
  border: 1.5px solid #E4DFD3;
  border-radius: 10px;
  font-size: 14px;
  font-family: inherit;
  color: var(--ink);
  background: var(--cream);
  transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
}

.form-control::placeholder {
  color: #B3AA98;
}

.form-control:focus {
  outline: none;
  border-color: var(--brass);
  background: white;
  box-shadow: 0 0 0 3px rgba(201, 169, 110, 0.18);
}

.form-control.is-invalid {
  border-color: var(--danger);
  background: #FCF3F1;
}

.form-error {
  display: block;
  font-size: 12px;
  color: var(--danger);
  margin-top: 6px;
}

.btn-login {
  width: 100%;
  background: linear-gradient(150deg, var(--forest) 0%, var(--forest-deep) 100%);
  border: none;
  border-radius: 10px;
  padding: 14px 24px;
  font-weight: 600;
  font-size: 14.5px;
  letter-spacing: 0.01em;
  color: white;
  transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
  box-shadow: 0 10px 22px rgba(30, 41, 59, 0.22);
  margin-top: 6px;
}

.btn-login:hover:not(:disabled) {
  transform: translateY(-2px);
  box-shadow: 0 14px 28px rgba(30, 41, 59, 0.3);
  background: linear-gradient(150deg, #2C3E5C 0%, var(--forest-deep) 100%);
}

.alert {
  padding: 12px 16px;
  border-radius: 10px;
  margin-bottom: 22px;
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 13.5px;
  background: #FCF3F1;
  color: var(--danger);
  border: 1px solid #F3D9D4;
}

.alert-icon {
  font-weight: bold;
}

.auth-footer {
  margin-top: 24px;
  font-size: 13.5px;
  color: var(--ink-soft);
}

.auth-footer a {
  color: var(--forest);
  font-weight: 600;
  text-decoration: none;
  border-bottom: 1px solid transparent;
  transition: border-color 0.2s ease, color 0.2s ease;
}

.auth-footer a:hover {
  color: var(--brass);
  border-color: var(--brass);
}

@media (max-width: 860px) {
  .auth-container {
    grid-template-columns: 1fr;
    max-width: 420px;
    border-radius: 20px;
  }

  .auth-welcome {
    display: none;
  }

  .auth-card {
    padding: 40px 32px;
  }
}

@media (max-width: 480px) {
  .auth-page {
    padding: 16px;
  }

  .auth-card {
    padding: 32px 24px;
  }

  .form-control {
    padding: 12px 14px;
    font-size: 16px;
  }
}
</style>