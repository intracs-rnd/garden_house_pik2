<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { extractErrorMessage, extractValidationErrors, USER_ROLE_VARIANT, USER_TYPE_VARIANT } from '@/utils/helper'
import { capitalize, formatCurrency, formatDate, formatDateTime } from '@/utils/formatter'
import PageHeader from '@/components/layout/Header.vue'
import Button from '@/components/common/Button.vue'
import Loader from '@/components/common/Loader.vue'
import Modal from '@/components/common/Modal.vue'

const auth = useAuthStore()
const toast = useToast()

const loading = ref(true)

const user = computed(() => auth.user || {})
const hasOutstanding = computed(() => Number(user.value.outstanding_balance) > 0)

// --- Reset password ---
const showResetModal = ref(false)
const resetErrors = ref({})
const resetForm = reactive({
  password: '',
  password_confirmation: '',
})
const resetTouched = reactive({
  password: false,
  password_confirmation: false,
})
let isResetting = false

function openResetModal() {
  isResetting = true
  resetErrors.value = {}
  resetForm.password = ''
  resetForm.password_confirmation = ''
  resetTouched.password = false
  resetTouched.password_confirmation = false
  showResetModal.value = true
  // Cegah watcher menandai field "touched" saat form dikosongkan ulang.
  nextTick(() => {
    isResetting = false
  })
}

// Validasi satu field lalu perbarui daftar error secara langsung.
function validateResetField(field) {
  const next = { ...resetErrors.value }

  if (field === 'password') {
    if (!resetForm.password) {
      next.password = 'Password wajib diisi.'
    } else if (resetForm.password.length < 8) {
      next.password = 'Password minimal 8 karakter.'
    } else {
      delete next.password
    }
  }

  if (field === 'password_confirmation') {
    if (!resetForm.password_confirmation) {
      next.password_confirmation = 'Konfirmasi password wajib diisi.'
    } else if (resetForm.password_confirmation !== resetForm.password) {
      next.password_confirmation = 'Konfirmasi password tidak cocok.'
    } else {
      delete next.password_confirmation
    }
  }

  resetErrors.value = next
}

// Validasi otomatis saat pengguna mengetik (tanpa klik tombol).
watch(
  () => resetForm.password,
  () => {
    if (isResetting) return
    resetTouched.password = true
    validateResetField('password')
    if (resetTouched.password_confirmation) {
      validateResetField('password_confirmation')
    }
  },
)

watch(
  () => resetForm.password_confirmation,
  () => {
    if (isResetting) return
    resetTouched.password_confirmation = true
    validateResetField('password_confirmation')
  },
)

const passwordValid = computed(
  () => resetTouched.password && !resetErrors.value.password,
)
const confirmationValid = computed(
  () => resetTouched.password_confirmation && !resetErrors.value.password_confirmation,
)

function validateReset() {
  resetTouched.password = true
  resetTouched.password_confirmation = true
  validateResetField('password')
  validateResetField('password_confirmation')
  return Object.keys(resetErrors.value).length === 0
}

async function handleResetPassword() {
  // Validasi sisi klien: cegah simpan bila input tidak valid.
  if (!validateReset()) {
    return
  }

  try {
    await auth.changePassword({ ...resetForm })
    toast.success('Password berhasil diperbarui.')
    showResetModal.value = false
  } catch (error) {
    resetErrors.value = extractValidationErrors(error)
    toast.error(extractErrorMessage(error, 'Gagal mengubah password.'))
  }
}

onMounted(async () => {
  try {
    await auth.fetchUser()
  } catch (error) {
    toast.error(extractErrorMessage(error, 'Gagal memuat profil pengguna.'))
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="page">
    <PageHeader title="Profil Saya" subtitle="Informasi akun Anda">
      <template #actions>
        <Button variant="primary" :disabled="loading || !user.id" @click="openResetModal">
          Reset Password
        </Button>
      </template>
    </PageHeader>

    <Loader v-if="loading" text="Memuat profil..." />

    <template v-else>
      <!-- Identity card -->
      <div class="card">
        <div class="card-body profile-identity">
          <span class="profile-avatar">{{ auth.userInitials }}</span>
          <div class="profile-identity-info">
            <h2>{{ user.name || '-' }}</h2>
            <p class="text-muted">{{ user.email || '-' }}</p>
            <div class="profile-badges">
              <span class="badge" :class="`badge-${USER_ROLE_VARIANT[user.role] || 'muted'}`">
                {{ capitalize(user.role || 'user') }}
              </span>
              <span class="badge" :class="`badge-${USER_TYPE_VARIANT[user.type] || 'muted'}`">
                {{ capitalize(user.type || 'warga') }}
              </span>
              <span class="badge" :class="user.is_active ? 'badge-success' : 'badge-muted'">
                {{ user.is_active ? 'Aktif' : 'Nonaktif' }}
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- Details card -->
      <div class="card">
        <div class="card-header">Detail Akun</div>
        <div class="card-body">
          <dl class="profile-details">
            <div class="profile-detail">
              <dt>Nama Lengkap</dt>
              <dd>{{ user.name || '-' }}</dd>
            </div>
            <div class="profile-detail">
              <dt>Email</dt>
              <dd>{{ user.email || '-' }}</dd>
            </div>
            <div class="profile-detail">
              <dt>No. Telepon</dt>
              <dd>{{ user.phone || '-' }}</dd>
            </div>
            <div class="profile-detail">
              <dt>Peran</dt>
              <dd>{{ capitalize(user.role || 'user') }}</dd>
            </div>
            <div class="profile-detail">
              <dt>Tipe</dt>
              <dd>
                <span class="badge" :class="`badge-${USER_TYPE_VARIANT[user.type] || 'muted'}`">
                  {{ capitalize(user.type || 'warga') }}
                </span>
              </dd>
            </div>
            <div class="profile-detail">
              <dt>Status Akun</dt>
              <dd>
                <span class="badge" :class="user.is_active ? 'badge-success' : 'badge-muted'">
                  {{ user.is_active ? 'Aktif' : 'Nonaktif' }}
                </span>
              </dd>
            </div>

            <div class="profile-detail">
              <dt>Terdaftar</dt>
              <dd>{{ formatDate(user.created_at) }}</dd>
            </div>
            <div class="profile-detail">
              <dt>Diperbarui</dt>
              <dd>{{ formatDateTime(user.updated_at) }}</dd>
            </div>
          </dl>
        </div>
      </div>
    </template>

    <!-- Reset password -->
    <Modal
      :model-value="showResetModal"
      title="Reset Password"
      @update:model-value="showResetModal = $event"
    >
      <form id="reset-password-form" @submit.prevent="handleResetPassword">

        <div class="form-group">
          <label class="form-label">Password Baru <span class="req">*</span></label>
          <input
            v-model="resetForm.password"
            type="password"
            class="form-control"
            :class="{ 'is-invalid': resetErrors.password, 'is-valid': passwordValid }"
            placeholder="Minimal 8 karakter"
            autocomplete="new-password"
          />
          <span v-if="resetErrors.password" class="form-error">{{ resetErrors.password }}</span>
          <span v-else-if="passwordValid" class="form-success">Password valid.</span>
        </div>

        <div class="form-group mb-0">
          <label class="form-label">Konfirmasi Password Baru <span class="req">*</span></label>
          <input
            v-model="resetForm.password_confirmation"
            type="password"
            class="form-control"
            :class="{
              'is-invalid': resetErrors.password_confirmation,
              'is-valid': confirmationValid,
            }"
            placeholder="Ulangi password baru"
            autocomplete="new-password"
          />
          <span v-if="resetErrors.password_confirmation" class="form-error">
            {{ resetErrors.password_confirmation }}
          </span>
          <span v-else-if="confirmationValid" class="form-success">Password cocok.</span>
        </div>
      </form>

      <template #footer>
        <Button variant="secondary" @click="showResetModal = false">Batal</Button>
        <Button
          variant="primary"
          type="submit"
          form="reset-password-form"
          :loading="auth.loading"
        >
          Simpan Password
        </Button>
      </template>
    </Modal>
  </div>
</template>

<style scoped>
.profile-identity {
  display: flex;
  align-items: center;
  gap: 20px;
}
.profile-avatar {
  display: grid;
  place-items: center;
  width: 72px;
  height: 72px;
  border-radius: 50%;
  background: var(--color-primary);
  color: #fff;
  font-size: 24px;
  font-weight: 600;
  flex-shrink: 0;
}
.profile-identity-info h2 {
  font-size: 20px;
}
.profile-identity-info p {
  margin: 4px 0 10px;
}
.profile-badges {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
.profile-details {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 20px 24px;
  margin: 0;
}
.profile-detail dt {
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-text-muted);
  margin-bottom: 4px;
}
.profile-detail dd {
  margin: 0;
  font-size: 14px;
  font-weight: 500;
}
.text-danger {
  color: var(--color-danger);
}
.req {
  color: var(--color-danger);
}
.form-control.is-valid {
  border-color: var(--color-success);
}
.form-success {
  display: block;
  margin-top: 5px;
  font-size: 12px;
  color: var(--color-success);
}
@media (max-width: 640px) {
  .profile-identity {
    flex-direction: column;
    text-align: center;
  }
  .profile-badges {
    justify-content: center;
  }
  .profile-details {
    grid-template-columns: 1fr;
  }
}
</style>
