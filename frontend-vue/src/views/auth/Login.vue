<script setup>
import { reactive, ref } from 'vue'
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
</script>

<template>
  <div class="auth-page">
    <div class="auth-container">
      <!-- Left side - Welcome message with housing theme -->
      <div class="auth-welcome">
        <div class="welcome-content">
          <div class="welcome-icon">
            <svg viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
              <!-- House illustration -->
              <path d="M20 80V45L50 20L80 45V80" stroke="white" stroke-width="2" fill="none"/>
              <path d="M50 20L80 45V80H20V45L50 20Z" fill="none" stroke="white" stroke-width="1.5"/>
              <rect x="35" y="50" width="15" height="20" fill="none" stroke="white" stroke-width="1.5"/>
              <rect x="50" y="50" width="15" height="20" fill="none" stroke="white" stroke-width="1.5"/>
              <circle cx="57" cy="56" r="1.5" fill="white"/>
              <circle cx="42" cy="56" r="1.5" fill="white"/>
              <path d="M35 50L50 35L65 50" fill="none" stroke="white" stroke-width="1.5"/>
            </svg>
          </div>
          <h2>Selamat Datang</h2>
          <p>Kelola Perumahan PIK2 dengan mudah dan aman</p>
          <div class="features">
            <div class="feature-item">
              <span class="check-icon">✓</span>
              <span>Kelola Data Penghuni</span>
            </div>
            <div class="feature-item">
              <span class="check-icon">✓</span>
              <span>Monitor Fasilitas</span>
            </div>
            <div class="feature-item">
              <span class="check-icon">✓</span>
              <span>Laporan Terperinci</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Right side - Login form -->
      <div class="auth-card">
        <div class="auth-brand">
          <div class="auth-logo">
            <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
              <rect x="6" y="10" width="20" height="16" rx="2" fill="white" opacity="0.9"/>
              <path d="M16 6L24 10H8L16 6Z" fill="white" opacity="0.9"/>
              <rect x="10" y="13" width="4" height="4" fill="currentColor" opacity="0.3"/>
              <rect x="18" y="13" width="4" height="4" fill="currentColor" opacity="0.3"/>
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
            <label class="form-label">
              <span class="label-icon">✉</span>
              Email
            </label>
            <input
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
            <label class="form-label">
              <span class="label-icon">🔐</span>
              Password
            </label>
            <input
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
* {
  box-sizing: border-box;
}

.auth-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  background: url('@/assets/images/perumahan_pik2.png');
  background-size: cover;
  background-position: center;
  background-repeat: no-repeat;
  background-attachment: fixed;
  position: relative;
  overflow: hidden;
}

/* Added overlay for better text readability */
.auth-page::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.4); /* Dark overlay */
  z-index: 0;
}

.auth-container {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 60px;
  max-width: 1000px;
  width: 100%;
  z-index: 1;
  align-items: center;
}

.auth-welcome {
  color: white;
  display: flex;
  flex-direction: column;
  justify-content: center;
}

.welcome-content {
  text-align: left;
}

.welcome-icon {
  width: 80px;
  height: 80px;
  margin-bottom: 24px;
  animation: pulse 2s ease-in-out infinite;
}

.welcome-icon svg {
  width: 100%;
  height: 100%;
}

@keyframes pulse {
  0%, 100% { transform: scale(1); }
  50% { transform: scale(1.05); }
}

.auth-welcome h2 {
  font-size: 32px;
  font-weight: 700;
  margin-bottom: 12px;
  text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
}

.auth-welcome p {
  font-size: 16px;
  margin-bottom: 32px;
  opacity: 0.95;
  line-height: 1.5;
  text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
}

.features {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.feature-item {
  display: flex;
  align-items: center;
  gap: 12px;
  font-size: 14px;
}

.check-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  background: rgba(255, 255, 255, 0.25);
  border-radius: 50%;
  font-weight: bold;
  flex-shrink: 0;
}

.auth-card {
  background: rgba(255, 255, 255, 0.95); /* Slightly less opaque */
  backdrop-filter: blur(20px);
  border-radius: 20px;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4), 0 0 1px rgba(255, 255, 255, 0.5) inset;
  padding: 48px;
  width: 100%;
  border: 1px solid rgba(255, 255, 255, 0.3);
  transition: transform 0.3s ease-in-out; /* Added transition for hover effect */
}

.auth-card:hover {
  transform: translateY(-5px); /* Subtle lift on hover */
}

.auth-brand {
  text-align: center;
  margin-bottom: 32px;
}

.auth-logo {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 64px;
  height: 64px;
  background: linear-gradient(135deg, #6d94a0 0%, #478b81 100%);
  color: white;
  border-radius: 16px;
  font-weight: 700;
  font-size: 20px;
  margin-bottom: 16px;
  box-shadow: 0 4px 12px rgba(71, 139, 129, 0.3);
}

.auth-logo svg {
  width: 100%;
  height: 100%;
}

.auth-brand h1 {
  font-size: 24px;
  color: #2c1810;
  margin-bottom: 8px;
}

.auth-brand p {
  font-size: 14px;
  color: #6b5236; /* Changed color for better contrast */
  font-weight: 500;
}

.form-group {
  margin-bottom: 24px;
}

.form-label {
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 600;
  font-size: 14px;
  color: #2c1810;
  margin-bottom: 10px;
}

.label-icon {
  font-size: 16px;
}

.form-control {
  width: 100%;
  padding: 12px 16px;
  border: 2px solid #E0D4C4;
  border-radius: 12px;
  font-size: 14px;
  transition: all 0.3s ease;
  background: #FBF8F5;
}

.form-control:focus {
  outline: none;
  border-color: #478b7f;
  background: white;
  box-shadow: 0 0 0 3px rgba(139, 111, 71, 0.1);
}

.form-control.is-invalid {
  border-color: #dc3545;
  background: #fff5f5;
}

.form-error {
  display: block;
  font-size: 12px;
  color: #dc3545;
  margin-top: 6px;
}

.btn-login {
  background: linear-gradient(135deg, #0066cc 0%, #0052a3 100%);
  border: none;
  padding: 14px 24px;
  font-weight: 600;
  letter-spacing: 0.5px;
  transition: all 0.3s ease;
  box-shadow: 0 4px 12px rgba(0, 102, 204, 0.3);
}

.btn-login:hover:not(:disabled) {
  transform: translateY(-3px); /* Slightly more pronounced lift */
  box-shadow: 0 8px 20px rgba(0, 102, 204, 0.4); /* Stronger shadow */
}

.alert {
  padding: 12px 16px;
  border-radius: 12px;
  margin-bottom: 24px;
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 14px;
}

.alert-danger {
  background: #fff5f5;
  color: #dc3545;
  border: 1px solid #ffdddd;
}

.alert-icon {
  font-weight: bold;
  font-size: 16px;
}

.auth-footer {
  margin-top: 24px;
  text-align: center;
  font-size: 14px;
  color: #8B6F47;
}

.auth-footer a {
  color: #0066cc; /* Changed to match primary button color */
  font-weight: 600;
  text-decoration: none;
  transition: color 0.2s ease;
}

.auth-footer a:hover {
  color: #0052a3; /* Darker shade on hover */
  text-decoration: underline;
}

@media (max-width: 768px) {
  .auth-container {
    grid-template-columns: 1fr;
    gap: 40px;
  }

  .auth-welcome {
    text-align: center;
    display: none;
  }

  .auth-card {
    padding: 32px;
    max-width: 400px;
    margin: 0 auto;
  }

  .auth-welcome h2 {
    font-size: 24px;
  }
}

@media (max-width: 480px) {
  .auth-page {
    padding: 16px;
  }

  .auth-card {
    padding: 24px;
  }

  .auth-logo {
    width: 56px;
    height: 56px;
  }

  .auth-brand h1 {
    font-size: 20px;
  }

  .form-control {
    padding: 11px 14px;
    font-size: 16px;
  }
}
</style>