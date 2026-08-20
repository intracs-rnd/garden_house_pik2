<script setup>
import { computed, ref } from 'vue'
import { KARTU_STATUS_OPTIONS } from '@/utils/helper'
import Button from '@/components/common/Button.vue'
import SearchableSelect from '@/components/common/SearchableSelect.vue'

const props = defineProps({
  form: { type: Object, required: true },
  errors: { type: Object, default: () => ({}) },
  users: { type: Array, default: () => [] },
  rfids: { type: Array, default: () => [] },
  remainingSlots: { type: Number, default: 4 },
  isEdit: { type: Boolean, default: false },
  saving: { type: Boolean, default: false },
})

const emit = defineEmits(['submit', 'cancel'])

const rfidSearch = ref('')

const userOptions = computed(() =>
    props.users.map((user) => ({
      value: user.id,
      label: `${user.name}${user.email ? ` (${user.email})` : ''}`,
    })),
)

const filteredRfids = computed(() => {
  const q = rfidSearch.value.trim().toLowerCase()
  if (!q) return props.rfids
  return props.rfids.filter(
      (r) =>
          String(r.id || '').toLowerCase().includes(q) ||
          (r.uid || '').toLowerCase().includes(q) ||
          (r.name || '').toLowerCase().includes(q) ||
          (r.unit || '').toLowerCase().includes(q),
  )
})

const maxSelectable = computed(() => props.remainingSlots)
const selectedCount = computed(() => (props.form.rfid_tags || []).length)

function isChecked(uid) {
  return (props.form.rfid_tags || []).includes(uid)
}

function toggleRfid(uid) {
  const tags = props.form.rfid_tags || []
  if (tags.includes(uid)) {
    props.form.rfid_tags = tags.filter((t) => t !== uid)
  } else if (tags.length < maxSelectable.value) {
    props.form.rfid_tags = [...tags, uid]
  }
}

function isDisabled(uid) {
  return !isChecked(uid) && selectedCount.value >= maxSelectable.value
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
            placeholder="Contoh: Dimas Anggara"
        />
        <span v-if="errors.nama" class="form-error">{{ errors.nama }}</span>
      </div>

      <div class="form-group rfid-group" :class="{ 'is-invalid': errors.rfid_tags }">
        <div class="rfid-header">
          <label class="form-label">RFID Tag</label>
          <span v-if="!isEdit" class="rfid-counter" :class="{ 'at-max': selectedCount >= maxSelectable }">
            {{ selectedCount }} / {{ maxSelectable }} dipilih
          </span>
        </div>

        <template v-if="!isEdit">
          <div v-if="maxSelectable === 0" class="rfid-limit-notice">
            KK ini sudah mencapai batas maksimal 4 kartu akses.
          </div>
          <template v-else>
            <input
                v-model="rfidSearch"
                type="text"
                class="form-control rfid-search"
                placeholder="Cari UID, nama, atau unit..."
            />
            <div class="rfid-table-wrap">
              <table class="rfid-table">
                <thead>
                  <tr>
                    <th class="col-check"></th>
                    <th>ID</th>
                    <th>UID</th>
                    <th>Nama</th>
                    <th>Unit</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-if="filteredRfids.length === 0">
                    <td colspan="5" class="rfid-empty">Tidak ada RFID tersedia</td>
                  </tr>
                  <tr
                      v-for="rfid in filteredRfids"
                      :key="rfid.uid"
                      :class="{ selected: isChecked(rfid.uid), disabled: isDisabled(rfid.uid) }"
                      @click="!isDisabled(rfid.uid) && toggleRfid(rfid.uid)"
                  >
                    <td class="col-check">
                      <input
                          type="checkbox"
                          :checked="isChecked(rfid.uid)"
                          :disabled="isDisabled(rfid.uid)"
                          @click.stop="toggleRfid(rfid.uid)"
                      />
                    </td>
                    <td class="col-id">{{ rfid.id }}</td>
                    <td class="col-uid">{{ rfid.uid }}</td>
                    <td>{{ rfid.name || '-' }}</td>
                    <td>{{ rfid.unit || '-' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
        </template>

        <input v-else :value="form.rfid_tag" type="text" class="form-control" disabled />

        <span v-if="errors.rfid_tags" class="form-error">{{ errors.rfid_tags }}</span>
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
.rfid-group {
  grid-column: 1 / -1;
}
.rfid-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 6px;
}
.rfid-header .form-label {
  margin-bottom: 0;
}
.rfid-counter {
  font-size: 12px;
  font-weight: 600;
  color: var(--color-text-muted);
  background: var(--color-bg-soft, #f3f4f6);
  padding: 2px 10px;
  border-radius: 999px;
}
.rfid-counter.at-max {
  color: var(--color-primary, #2563eb);
  background: var(--color-primary-light, #dbeafe);
}
.rfid-search {
  margin-bottom: 6px;
}
.rfid-table-wrap {
  border: 1px solid var(--color-border);
  border-radius: 6px;
  overflow: hidden;
  max-height: 260px;
  overflow-y: auto;
}
.rfid-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.rfid-table thead th {
  position: sticky;
  top: 0;
  background: var(--color-bg-soft, #f9fafb);
  padding: 8px 12px;
  text-align: left;
  font-weight: 600;
  color: var(--color-text-muted);
  border-bottom: 1px solid var(--color-border);
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.rfid-table tbody tr {
  cursor: pointer;
  transition: background 0.1s;
}
.rfid-table tbody tr:not(:last-child) td {
  border-bottom: 1px solid var(--color-border);
}
.rfid-table tbody tr:hover:not(.disabled) {
  background: var(--color-bg-hover, #f3f4f6);
}
.rfid-table tbody tr.selected {
  background: var(--color-primary-light, #dbeafe);
}
.rfid-table tbody tr.disabled {
  cursor: not-allowed;
  opacity: 0.45;
}
.rfid-table td {
  padding: 8px 12px;
}
.col-check {
  width: 40px;
  text-align: center;
}
.col-id {
  font-size: 12px;
  color: var(--color-text-muted);
  width: 60px;
}
.col-uid {
  font-family: monospace;
  letter-spacing: 0.05em;
}
.rfid-empty {
  text-align: center;
  color: var(--color-text-muted);
  padding: 24px !important;
}
.rfid-limit-notice {
  padding: 12px;
  border-radius: 6px;
  background: var(--color-danger-light, #fee2e2);
  color: var(--color-danger, #dc2626);
  font-size: 13px;
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