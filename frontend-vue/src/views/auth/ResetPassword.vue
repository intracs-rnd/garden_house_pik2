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

<style scoped src="./auth_css/ResetPassword.css"></style>