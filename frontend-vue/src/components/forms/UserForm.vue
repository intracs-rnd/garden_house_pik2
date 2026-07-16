<script setup>
import { reactive, computed, watch } from 'vue'
import { USER_ROLES, USER_TYPES } from '@/utils/helper'
import Button from '@/components/common/Button.vue'

const props = defineProps({
  form: { type: Object, required: true },
  errors: { type: Object, default: () => ({}) },
  isEdit: { type: Boolean, default: false },
  saving: { type: Boolean, default: false },
})

const emit = defineEmits(['submit', 'cancel'])

const touched = reactive({ password: false, password_confirmation: false, no_kk: false })
const pwErrors = reactive({ password: '', password_confirmation: '' })
const kkError = reactive({ no_kk: '' })

// Only digits are allowed in the family card number (Nomor Kartu Keluarga).
function onKkInput() {
  const digits = String(props.form.no_kk || '').replace(/\D/g, '').slice(0, 16)
  props.form.no_kk = digits
  touched.no_kk = true
  validateKk()
}

function validateKk() {
  const kk = String(props.form.no_kk || '')
  if (!kk) {
    kkError.no_kk = 'Nomor Kartu Keluarga wajib diisi.'
  } else if (!/^\d{16}$/.test(kk)) {
    kkError.no_kk = 'Nomor Kartu Keluarga harus 16 digit angka.'
  } else {
    kkError.no_kk = ''
  }
}

// On edit an empty password means "leave unchanged", so validation is skipped
// until the user starts typing a new password.
function skipOnEdit() {
  return props.isEdit && !props.form.password && !props.form.password_confirmation
}

function validatePassword() {
  if (skipOnEdit()) {
    pwErrors.password = ''
    return
  }
  const pw = props.form.password || ''
  if (!pw) {
    pwErrors.password = 'Password wajib diisi.'
  } else if (pw.length < 8) {
    pwErrors.password = 'Password minimal 8 karakter.'
  } else {
    pwErrors.password = ''
  }
}

function validateConfirmation() {
  if (skipOnEdit()) {
    pwErrors.password_confirmation = ''
    return
  }
  const pw = props.form.password || ''
  const conf = props.form.password_confirmation || ''
  if (!conf) {
    pwErrors.password_confirmation = 'Konfirmasi password wajib diisi.'
  } else if (conf !== pw) {
    pwErrors.password_confirmation = 'Konfirmasi password tidak cocok.'
  } else {
    pwErrors.password_confirmation = ''
  }
}

watch(
  () => props.form.password,
  () => {
    touched.password = true
    validatePassword()
    if (touched.password_confirmation) validateConfirmation()
  },
)

watch(
  () => props.form.password_confirmation,
  () => {
    touched.password_confirmation = true
    validateConfirmation()
  },
)

const passwordError = computed(() => pwErrors.password || props.errors.password || '')
const kkErrorMessage = computed(() => kkError.no_kk || props.errors.no_kk || '')
const kkValid = computed(() => touched.no_kk && /^\d{16}$/.test(String(props.form.no_kk || '')))
const confirmationError = computed(
  () => pwErrors.password_confirmation || props.errors.password_confirmation || '',
)
const passwordValid = computed(
  () => touched.password && !!props.form.password && !passwordError.value,
)
const confirmationValid = computed(
  () => touched.password_confirmation && !!props.form.password_confirmation && !confirmationError.value,
)

function validate() {
  touched.password = true
  touched.password_confirmation = true
  touched.no_kk = true
  validatePassword()
  validateConfirmation()
  validateKk()
  return !pwErrors.password && !pwErrors.password_confirmation && !kkError.no_kk
}

function onSubmit() {
  if (!validate()) return
  emit('submit')
}
</script>

<template>
  <form @submit.prevent="onSubmit">
    <div class="form-grid">
      <div class="form-group">
        <label class="form-label">Nama Lengkap <span class="req">*</span></label>
        <input
          v-model="form.name"
          type="text"
          class="form-control"
          :class="{ 'is-invalid': errors.name }"
          placeholder="Masukkan nama"
        />
        <span v-if="errors.name" class="form-error">{{ errors.name }}</span>
      </div>

      <div class="form-group">
        <label class="form-label">Email <span class="req">*</span></label>
        <input
          v-model="form.email"
          type="email"
          class="form-control"
          :class="{ 'is-invalid': errors.email }"
          placeholder="nama@email.com"
        />
        <span v-if="errors.email" class="form-error">{{ errors.email }}</span>
      </div>

      <div class="form-group">
        <label class="form-label">
          Password <span v-if="!isEdit" class="req">*</span>
        </label>
        <input
          v-model="form.password"
          type="password"
          class="form-control"
          :class="{ 'is-invalid': passwordError, 'is-valid': passwordValid }"
          :placeholder="isEdit ? 'Kosongkan jika tidak diubah' : 'Minimal 8 karakter'"
          autocomplete="new-password"
        />
        <span v-if="passwordError" class="form-error">{{ passwordError }}</span>
        <span v-else-if="passwordValid" class="form-success">Password valid.</span>
        <span v-else-if="isEdit" class="form-hint">
          Biarkan kosong bila tidak ingin mengganti password.
        </span>
      </div>

      <div class="form-group">
        <label class="form-label">
          Konfirmasi Password <span v-if="!isEdit" class="req">*</span>
        </label>
        <input
          v-model="form.password_confirmation"
          type="password"
          class="form-control"
          :class="{ 'is-invalid': confirmationError, 'is-valid': confirmationValid }"
          :placeholder="isEdit ? 'Ulangi password baru' : 'Ulangi password'"
          autocomplete="new-password"
        />
        <span v-if="confirmationError" class="form-error">{{ confirmationError }}</span>
        <span v-else-if="confirmationValid" class="form-success">Password cocok.</span>
      </div>

      <div class="form-group">
        <label class="form-label">Peran</label>
        <select
          v-model="form.role"
          class="form-control"
          :class="{ 'is-invalid': errors.role }"
        >
          <option v-for="role in USER_ROLES" :key="role.value" :value="role.value">
            {{ role.label }}
          </option>
        </select>
        <span v-if="errors.role" class="form-error">{{ errors.role }}</span>
      </div>

      <div class="form-group">
        <label class="form-label">Tipe</label>
        <select
          v-model="form.type"
          class="form-control"
          :class="{ 'is-invalid': errors.type }"
        >
          <option v-for="type in USER_TYPES" :key="type.value" :value="type.value">
            {{ type.label }}
          </option>
        </select>
        <span v-if="errors.type" class="form-error">{{ errors.type }}</span>
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
        <label class="form-label">No. Kartu Keluarga <span class="req">*</span></label>
        <input
          v-model="form.no_kk"
          type="text"
          inputmode="numeric"
          maxlength="16"
          class="form-control"
          :class="{ 'is-invalid': kkErrorMessage, 'is-valid': kkValid }"
          placeholder="16 digit nomor KK"
          @input="onKkInput"
        />
        <span v-if="kkErrorMessage" class="form-error">{{ kkErrorMessage }}</span>
        <span v-else-if="kkValid" class="form-success">Nomor KK valid.</span>
      </div>

      <div v-if="isEdit" class="form-group">
        <label class="form-label">Status Akun</label>
        <label class="switch">
          <input v-model="form.is_active" type="checkbox" />
          <span>{{ form.is_active ? 'Aktif' : 'Nonaktif' }}</span>
        </label>
      </div>
    </div>

    <div class="form-actions">
      <Button variant="secondary" type="button" @click="emit('cancel')">Batal</Button>
      <Button variant="primary" type="submit" :loading="saving">
        {{ isEdit ? 'Simpan Perubahan' : 'Simpan Pengguna' }}
      </Button>
    </div>
  </form>
</template>

<style scoped>
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
.form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 8px;
  padding-top: 16px;
  border-top: 1px solid var(--color-border);
}
.switch {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  cursor: pointer;
}
.switch input {
  width: 18px;
  height: 18px;
}
</style>
