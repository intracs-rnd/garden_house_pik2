<script setup>
import { reactive, ref, computed, watch, onMounted } from 'vue'
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
  password_confirmation: '',
})

const errors = ref({})
const generalError = ref('')
const invalidLink = ref(false)
const touched = reactive({
  password: false,
  password_confirmation: false,
})

onMounted(() => {
  form.email = route.query.email || ''

  if (!form.email) {
    invalidLink.value = true
    generalError.value = 'Email tidak ditemukan. Silakan ulangi proses lupa password.'
  }
})

// Validasi satu field lalu perbarui daftar error secara langsung.
function validateField(field) {
  const next = { ...errors.value }

  if (field === 'password') {
    if (!form.password) {
      next.password = 'Password wajib diisi.'
    } else if (form.password.length < 8) {
      next.password = 'Password minimal 8 karakter.'
    } else {
      delete next.password
    }
  }

  if (field === 'password_confirmation') {
    if (!form.password_confirmation) {
      next.password_confirmation = 'Konfirmasi password wajib diisi.'
    } else if (form.password_confirmation !== form.password) {
      next.password_confirmation = 'Konfirmasi password tidak cocok.'
    } else {
      delete next.password_confirmation
    }
  }

  errors.value = next
}

// Validasi otomatis saat pengguna mengetik (tanpa klik tombol).
watch(
  () => form.password,
  () => {
    touched.password = true
    validateField('password')
    if (touched.password_confirmation) {
      validateField('password_confirmation')
    }
  },
)

watch(
  () => form.password_confirmation,
  () => {
    touched.password_confirmation = true
    validateField('password_confirmation')
  },
)

const passwordValid = computed(() => touched.password && !errors.value.password)
const confirmationValid = computed(
  () => touched.password_confirmation && !errors.value.password_confirmation,
)

async function handleSubmit() {
  generalError.value = ''

  // Validasi sisi klien: cegah simpan bila input tidak valid.
  if (!validate()) {
    return
  }

  try {
    await auth.resetPassword({ ...form })
    toast.success('Password berhasil direset. Silakan masuk.')
    router.push({ name: 'login' })
  } catch (error) {
    errors.value = extractValidationErrors(error)
    generalError.value = auth.error
  }
}

function validate() {
  touched.password = true
  touched.password_confirmation = true
  validateField('password')
  validateField('password_confirmation')
  return Object.keys(errors.value).length === 0
}
</script>

<template>
  <div class="auth-page">
    <div class="auth-card">
      <div class="auth-brand">
        <span class="auth-logo">GH</span>
        <h1>Lupa Password</h1>
        <p>Buat password baru untuk akun Anda</p>
      </div>

      <div v-if="generalError" class="alert alert-danger">{{ generalError }}</div>

      <div v-if="invalidLink" class="auth-footer">
        <RouterLink :to="{ name: 'forgot-password' }">Kembali ke halaman lupa password</RouterLink>
      </div>

      <template v-if="!invalidLink">
        <form @submit.prevent="handleSubmit">
          <div class="form-group">
            <label class="form-label">Email</label>
            <input
              v-model="form.email"
              type="email"
              class="form-control"
              :class="{ 'is-invalid': errors.email }"
              autocomplete="email"
              readonly
            />
            <span v-if="errors.email" class="form-error">{{ errors.email }}</span>
          </div>

          <div class="form-group">
            <label class="form-label">Password Baru</label>
            <input
              v-model="form.password"
              type="password"
              class="form-control"
              :class="{ 'is-invalid': errors.password, 'is-valid': passwordValid }"
              placeholder="Minimal 8 karakter"
              autocomplete="new-password"
              required
            />
            <span v-if="errors.password" class="form-error">{{ errors.password }}</span>
            <span v-else-if="passwordValid" class="form-success">Password valid.</span>
          </div>

          <div class="form-group">
            <label class="form-label">Konfirmasi Password</label>
            <input
              v-model="form.password_confirmation"
              type="password"
              class="form-control"
              :class="{
                'is-invalid': errors.password_confirmation,
                'is-valid': confirmationValid,
              }"
              placeholder="Ulangi password baru"
              autocomplete="new-password"
              required
            />
            <span v-if="errors.password_confirmation" class="form-error">
              {{ errors.password_confirmation }}
            </span>
            <span v-else-if="confirmationValid" class="form-success">Password cocok.</span>
          </div>

          <Button variant="primary" type="submit" block :loading="auth.loading" class="btn-submit">
            Reset Password
          </Button>
        </form>
      </template>

      <p class="auth-footer">
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

.form-control.is-valid {
  border-color: #28a745; /* Green for valid */
}
.form-success {
  display: block;
  margin-top: 5px;
  font-size: 12px;
  color: #28a745; /* Green for success message */
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