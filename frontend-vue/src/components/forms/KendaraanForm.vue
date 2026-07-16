<script setup>
import { computed } from 'vue'
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
</script>

<template>
  <form @submit.prevent="emit('submit')">
    <div class="form-grid">
      <div class="form-group">
        <label class="form-label">Pemilik Kendaraan</label>
        <SearchableSelect
          v-model="form.user_id"
          :options="userOptions"
          :invalid="!!errors.user_id"
          placeholder="Tanpa pemilik"
          search-placeholder="Cari nama atau email..."
        />
        <span v-if="errors.user_id" class="form-error">{{ errors.user_id }}</span>
      </div>

      <div class="form-group">
        <label class="form-label">Nama Kendaraan <span class="req">*</span></label>
        <input
          v-model="form.nama"
          type="text"
          class="form-control"
          :class="{ 'is-invalid': errors.nama }"
          placeholder="Contoh: Toyota Avanza Operasional"
        />
        <span v-if="errors.nama" class="form-error">{{ errors.nama }}</span>
      </div>

      <div class="form-group">
        <label class="form-label">Nomor Plat <span class="req">*</span></label>
        <input
          v-model="form.nomor_plat"
          type="text"
          class="form-control"
          :class="{ 'is-invalid': errors.nomor_plat }"
          placeholder="B 1234 XYZ"
        />
        <span v-if="errors.nomor_plat" class="form-error">{{ errors.nomor_plat }}</span>
      </div>

      <div class="form-group">
        <label class="form-label">Merk</label>
        <input v-model="form.merk" type="text" class="form-control" placeholder="Toyota" />
        <span v-if="errors.merk" class="form-error">{{ errors.merk }}</span>
      </div>

      <div class="form-group">
        <label class="form-label">Model</label>
        <input v-model="form.model" type="text" class="form-control" placeholder="Avanza" />
        <span v-if="errors.model" class="form-error">{{ errors.model }}</span>
      </div>

      <div class="form-group">
        <label class="form-label">Tahun</label>
        <input
          v-model.number="form.tahun"
          type="number"
          class="form-control"
          :class="{ 'is-invalid': errors.tahun }"
          placeholder="2023"
        />
        <span v-if="errors.tahun" class="form-error">{{ errors.tahun }}</span>
      </div>
    </div>

    <div class="form-actions">
      <Button variant="secondary" type="button" @click="emit('cancel')">Batal</Button>
      <Button variant="primary" type="submit" :loading="saving">
        {{ isEdit ? 'Simpan Perubahan' : 'Simpan Kendaraan' }}
      </Button>
    </div>
  </form>
</template>

<style scoped>
.req {
  color: var(--color-danger);
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
