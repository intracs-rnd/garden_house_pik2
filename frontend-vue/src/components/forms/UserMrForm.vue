<script setup>
import { reactive, computed, watch } from 'vue'
import Button from '@/components/common/Button.vue'

const props = defineProps({
  form: { type: Object, required: true },
  errors: { type: Object, default: () => ({}) },
  isEdit: { type: Boolean, default: false },
  saving: { type: Boolean, default: false },
})

const emit = defineEmits(['submit', 'cancel'])

const touched = reactive({ password: false, password_confirmation: false })
const pwErrors = reactive({ password: '', password_confirmation: '' })

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
  validatePassword()
  validateConfirmation()
  return !pwErrors.password && !pwErrors.password_confirmation
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
        <label class="form-label">Nama <span class="req">*</span></label>
        <input
          v-model="form.name"
          type="text"
          class="form-control"
          :class="{ 'is-invalid': errors.name }"
          placeholder="Masukkan nama lengkap"
        />
        <span v-if="errors.name" class="form-error">{{ errors.name }}</span>
      </div>

      <div class="form-group">
        <label class="form-label">Username <span class="req">*</span></label>
        <input
          v-model="form.username"
          type="text"
          class="form-control"
          :class="{ 'is-invalid': errors.username }"
          placeholder="Masukkan username unik"
          :readonly="isEdit"
          :title="isEdit ? 'Username tidak dapat diubah' : ''"
        />
        <span v-if="errors.username" class="form-error">{{ errors.username }}</span>
        <span v-else-if="isEdit" class="form-hint">Username tidak dapat diubah</span>
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
    </div>

    <div class="form-actions">
      <Button variant="secondary" type="button" @click="emit('cancel')">Batal</Button>
      <Button variant="primary" type="submit" :loading="saving">
        {{ isEdit ? 'Simpan Perubahan' : 'Tambah User MR' }}
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
.form-hint {
  display: block;
  margin-top: 5px;
  font-size: 12px;
  color: var(--color-muted);
}
.form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 8px;
  padding-top: 16px;
  border-top: 1px solid var(--color-border);
}
</style>
