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
  background:  url('@/assets/images/perumahan_pik2.png');
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

.auth-card {
  width: 100%;
  max-width: 420px;
  background: rgba(255, 255, 255, 0.95); /* Slightly less opaque */
  backdrop-filter: blur(20px);
  border-radius: var(--radius-lg);
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4), 0 0 1px rgba(255, 255, 255, 0.5) inset;
  padding: 36px 32px;
  border: 1px solid rgba(255, 255, 255, 0.3);
  transition: transform 0.3s ease-in-out; /* Added transition for hover effect */
}

.auth-card:hover {
  transform: translateY(-5px); /* Subtle lift on hover */
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
  color: #6b5236; /* Changed color for better contrast */
  font-size: 14px;
}
.auth-footer {
  margin-top: 20px;
  text-align: center;
  font-size: 14px;
  color: var(--color-text-muted);
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

.btn-submit {
  background: linear-gradient(135deg, #0066cc 0%, #0052a3 100%);
  border: none;
  padding: 14px 24px;
  font-weight: 600;
  letter-spacing: 0.5px;
  transition: all 0.3s ease;
  box-shadow: 0 4px 12px rgba(0, 102, 204, 0.3);
}

.btn-submit:hover:not(:disabled) {
  transform: translateY(-3px); /* Slightly more pronounced lift */
  box-shadow: 0 8px 20px rgba(0, 102, 204, 0.4); /* Stronger shadow */
}

/* Alert styles (copied from Login.vue for consistency) */
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

/* Form control styles (copied from Login.vue for consistency) */
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