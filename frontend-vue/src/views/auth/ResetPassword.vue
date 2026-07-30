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
        <div class="auth-logo">
          <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M16 5L27 13V27H5V13L16 5Z" stroke="white" stroke-width="1.8" stroke-linejoin="round"/>
            <rect x="13" y="18" width="6" height="9" fill="white"/>
            <rect x="9" y="14" width="3.5" height="3.5" fill="white" opacity="0.85"/>
            <rect x="19.5" y="14" width="3.5" height="3.5" fill="white" opacity="0.85"/>
          </svg>
        </div>
        <h1>Buat Password Baru</h1>
        <p>Buat password baru untuk akun Anda</p>
      </div>

      <div v-if="generalError" class="alert alert-danger">
        <span class="alert-icon">⚠</span>
        {{ generalError }}
      </div>

      <div v-if="invalidLink" class="auth-footer">
        <RouterLink :to="{ name: 'forgot-password' }">Kembali ke halaman lupa password</RouterLink>
      </div>

      <template v-if="!invalidLink">
        <form @submit.prevent="handleSubmit">
          <div class="form-group">
            <label class="form-label" for="reset-email">Email</label>
            <input
              id="reset-email"
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
            <label class="form-label" for="reset-password">Password Baru</label>
            <input
              id="reset-password"
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
            <label class="form-label" for="reset-password-confirmation">Konfirmasi Password</label>
            <input
              id="reset-password-confirmation"
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
@import url('https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Inter:wght@400;500;600;700&display=swap');

* {
  box-sizing: border-box;
}

.auth-page {
  --forest: #1E293B;
  --forest-deep: #0F172A;
  --brass: #C9A96E;
  --sage: #8FAE7A;
  --cream: #F7F4EC;
  --ink: #23201A;
  --ink-soft: #6B6355;
  --danger: #C24A3E;
  --success: #4C8A5C;

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
  font-family: 'Inter', -apple-system, sans-serif;
  position: relative;
  overflow: hidden;
}

.auth-page::before {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(160deg, rgba(15, 23, 42, 0.72) 0%, rgba(15, 23, 42, 0.5) 100%);
  z-index: 0;
}

.auth-card {
  position: relative;
  z-index: 1;
  width: 100%;
  max-width: 420px;
  background: rgba(255, 255, 255, 0.97);
  backdrop-filter: blur(16px);
  border-radius: 20px;
  box-shadow: 0 30px 80px rgba(0, 0, 0, 0.45);
  padding: 40px 36px;
  border: 1px solid rgba(255, 255, 255, 0.4);
  transition: transform 0.3s ease-in-out;
}

.auth-card:hover {
  transform: translateY(-4px);
}

.auth-brand {
  text-align: center;
  margin-bottom: 28px;
}

.auth-logo {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 56px;
  height: 56px;
  background: linear-gradient(150deg, var(--forest) 0%, var(--forest-deep) 100%);
  border-radius: 14px;
  margin-bottom: 16px;
  box-shadow: 0 8px 18px rgba(30, 41, 59, 0.28);
}

.auth-logo svg {
  width: 30px;
  height: 30px;
}

.auth-brand h1 {
  font-family: 'Fraunces', serif;
  font-weight: 600;
  font-size: 22px;
  color: var(--ink);
}

.auth-brand p {
  margin-top: 8px;
  color: var(--ink-soft);
  font-size: 14px;
  line-height: 1.5;
}

.auth-footer {
  margin-top: 22px;
  text-align: center;
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

.btn-submit {
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
}

.btn-submit:hover:not(:disabled) {
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

.form-control.is-valid {
  border-color: var(--success);
}

.form-error {
  display: block;
  font-size: 12px;
  color: var(--danger);
  margin-top: 6px;
}

.form-success {
  display: block;
  margin-top: 6px;
  font-size: 12px;
  color: var(--success);
}

@media (max-width: 480px) {
  .auth-page {
    padding: 16px;
  }

  .auth-card {
    padding: 28px 24px;
  }

  .form-control {
    padding: 12px 14px;
    font-size: 16px;
  }
}
</style>