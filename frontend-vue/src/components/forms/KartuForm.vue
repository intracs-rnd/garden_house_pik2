<script setup>
import { computed } from 'vue'
import { KARTU_STATUS_OPTIONS } from '@/utils/helper'
import Button from '@/components/common/Button.vue'
import SearchableSelect from '@/components/common/SearchableSelect.vue'

const props = defineProps({
  form: { type: Object, required: true },
  errors: { type: Object, default: () => ({}) },
  users: { type: Array, default: () => [] },
  isEdit: { type: Boolean, default: false },
  saving: { type: Boolean, default: false },
})

const emit = defineEmits(['submit', 'cancel'])

const userOptions = computed(() =>
  props.users.map((user) => ({
    value: user.id,
    label: `${user.name}${user.email ? ` (${user.email})` : ''}`,
  })),
)

/**
 * Simulasi scan kartu RFID: menghasilkan rfid_tag acak (10 karakter hex uppercase),
 * meniru format yang dibuat backend. Nantinya trigger ini bisa diganti dengan
 * event scan kartu fisik.
 */
function simulateScan() {
  const bytes = new Uint8Array(5)
  crypto.getRandomValues(bytes)
  props.form.rfid_tag = Array.from(bytes, (b) => b.toString(16).padStart(2, '0'))
    .join('')
    .toUpperCase()
}
</script>

<template>
  <form @submit.prevent="emit('submit')">
    <div class="form-grid">
      <div class="form-group">
        <label class="form-label">Pemilik Kartu <span class="req">*</span></label>
        <SearchableSelect
          v-model="form.user_id"
          :options="userOptions"
          :invalid="!!errors.user_id"
          :clearable="false"
          placeholder="Pilih pengguna"
          search-placeholder="Cari nama atau email..."
        />
        <span v-if="errors.user_id" class="form-error">{{ errors.user_id }}</span>
      </div>


      <div class="form-group">
        <label class="form-label">Nama / Label Kartu</label>
        <input
          v-model="form.nama"
          type="text"
          class="form-control"
          :class="{ 'is-invalid': errors.nama }"
          placeholder="Contoh: Kartu Penghuni Blok A"
        />
        <span v-if="errors.nama" class="form-error">{{ errors.nama }}</span>
      </div>

      <div class="form-group">
        <label class="form-label">RFID Tag</label>
        <div class="rfid-input">
          <input
            v-model="form.rfid_tag"
            type="text"
            class="form-control"
            :class="{ 'is-invalid': errors.rfid_tag }"
            placeholder="Scan kartu untuk mengisi otomatis"
            readonly
          />
          <Button v-if="!isEdit" variant="secondary" type="button" @click="simulateScan">
            Scan Kartu
          </Button>
        </div>
        <small v-if="!isEdit" class="form-hint">
          Tekan "Scan Kartu" untuk simulasi. Nantinya nilai ini terisi otomatis saat kartu di-scan.
        </small>
        <span v-if="errors.rfid_tag" class="form-error">{{ errors.rfid_tag }}</span>
      </div>

      <div class="form-group">
        <label class="form-label">Status</label>
        <select
          v-model.number="form.status"
          class="form-control"
          :class="{ 'is-invalid': errors.status }"
        >
          <option v-for="opt in KARTU_STATUS_OPTIONS" :key="opt.value" :value="opt.value">
            {{ opt.label }}
          </option>
        </select>
        <span v-if="errors.status" class="form-error">{{ errors.status }}</span>
      </div>

      <div class="form-group">
        <label class="form-label">Masa Berlaku Mulai</label>
        <input
          v-model="form.valid_from"
          type="datetime-local"
          class="form-control"
          :class="{ 'is-invalid': errors.valid_from }"
        />
        <span v-if="errors.valid_from" class="form-error">{{ errors.valid_from }}</span>
      </div>

      <div class="form-group">
        <label class="form-label">Masa Berlaku Sampai</label>
        <input
          v-model="form.valid_until"
          type="datetime-local"
          class="form-control"
          :class="{ 'is-invalid': errors.valid_until }"
        />
        <small class="form-hint">
          Kartu otomatis non-aktif setelah tanggal & jam ini (ditambah masa tenggang) terlewati.
        </small>
        <span v-if="errors.valid_until" class="form-error">{{ errors.valid_until }}</span>
      </div>

      <div class="form-group">
        <label class="form-label">Masa Tenggang (hari)</label>
        <input
          v-model.number="form.grace_days"
          type="number"
          min="0"
          max="365"
          class="form-control"
          :class="{ 'is-invalid': errors.grace_days }"
          placeholder="0"
        />
        <small class="form-hint">
          Kartu masih bisa dipakai selama jumlah hari ini setelah masa berlaku habis.
        </small>
        <span v-if="errors.grace_days" class="form-error">{{ errors.grace_days }}</span>
      </div>
    </div>

    <div class="form-group checkbox-group">
      <label class="checkbox">
        <input v-model="form.is_blacklisted" type="checkbox" />
        <span>Blacklist kartu ini (blokir akses)</span>
      </label>
    </div>

    <div v-if="form.is_blacklisted" class="form-group">
      <label class="form-label">Alasan Blacklist</label>
      <input
        v-model="form.blacklist_reason"
        type="text"
        class="form-control"
        :class="{ 'is-invalid': errors.blacklist_reason }"
        placeholder="Contoh: Tunggakan pembayaran belum diselesaikan"
      />
      <span v-if="errors.blacklist_reason" class="form-error">{{ errors.blacklist_reason }}</span>
    </div>

    <div class="form-group">
      <label class="form-label">Keterangan</label>
      <textarea
        v-model="form.keterangan"
        class="form-control"
        rows="3"
        placeholder="Catatan tambahan (opsional)"
      ></textarea>
      <span v-if="errors.keterangan" class="form-error">{{ errors.keterangan }}</span>
    </div>

    <div class="form-actions">
      <Button variant="secondary" type="button" @click="emit('cancel')">Batal</Button>
      <Button variant="primary" type="submit" :loading="saving">
        {{ isEdit ? 'Simpan Perubahan' : 'Simpan Kartu' }}
      </Button>
    </div>
  </form>
</template>

<style scoped>
.req {
  color: var(--color-danger);
}
.form-hint {
  display: block;
  margin-top: 4px;
  color: var(--color-text-muted);
  font-size: 12px;
}
.rfid-input {
  display: flex;
  gap: 8px;
  align-items: stretch;
}
.rfid-input .form-control {
  flex: 1;
}
.checkbox-group {
  margin-top: 4px;
}
.checkbox {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  font-size: 14px;
}
.checkbox input {
  width: 16px;
  height: 16px;
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
