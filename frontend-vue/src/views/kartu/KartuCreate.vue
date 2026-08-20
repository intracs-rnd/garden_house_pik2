<script setup>
import { onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useKartuStore } from '@/stores/kartu'
import { useToast } from '@/composables/useToast'
import { extractValidationErrors, extractErrorMessage } from '@/utils/helper'
import PageHeader from '@/components/layout/Header.vue'
import KartuForm from '@/components/forms/KartuForm.vue'

const router = useRouter()
const store = useKartuStore()
const toast = useToast()

const errors = ref({})
const availableRfids = ref([])
const remainingSlots = ref(4)

const form = reactive({
  user_id: '',
  card_number: '',
  nama: '',
  rfid_tags: [],
  status: 1,
  is_blacklisted: false,
  blacklist_reason: '',
  valid_from: '',
  valid_until: '',
  grace_days: 0,
  keterangan: '',
})

watch(() => form.user_id, async (userId) => {
  if (userId) {
    remainingSlots.value = await store.fetchRemainingSlots(userId)
    // Clear selections that exceed the new slot count.
    if (form.rfid_tags.length > remainingSlots.value) {
      form.rfid_tags = form.rfid_tags.slice(0, remainingSlots.value)
    }
  } else {
    remainingSlots.value = 4
  }
})

function buildPayload() {
  const payload = {
    user_id: form.user_id || undefined,
    rfid_tags: form.rfid_tags,
    nama: form.nama || undefined,
    status: form.status,
    is_blacklisted: form.is_blacklisted,
    blacklist_reason: form.is_blacklisted ? form.blacklist_reason || undefined : undefined,
    valid_from: form.valid_from || undefined,
    valid_until: form.valid_until || undefined,
    grace_days: form.grace_days,
    keterangan: form.keterangan || undefined,
  }
  // Remove undefined keys.
  Object.keys(payload).forEach((k) => payload[k] === undefined && delete payload[k])
  return payload
}

async function handleSubmit() {
  errors.value = {}
  try {
    const payload = buildPayload()
    if (!payload.rfid_tags || payload.rfid_tags.length === 0) {
      errors.value = { rfid_tags: 'Pilih minimal 1 RFID tag.' }
      return
    }
    await store.createBatch(payload)
    const count = payload.rfid_tags.length
    toast.success(`${count} kartu akses berhasil ditambahkan.`)
    router.push({ name: 'kartu.index' })
  } catch (error) {
    errors.value = extractValidationErrors(error)
    toast.error(extractErrorMessage(error, 'Gagal menyimpan kartu akses.'))
  }
}

onMounted(async () => {
  await store.fetchUsers()
  availableRfids.value = await store.fetchAvailableRfid()
})
</script>

<template>
  <div class="page">
    <PageHeader title="Tambah Kartu Akses" subtitle="Daftarkan kartu akses baru" />

    <div class="card">
      <div class="card-body">
        <KartuForm
          :form="form"
          :errors="errors"
          :users="store.users"
          :rfids="availableRfids"
          :remaining-slots="remainingSlots"
          :saving="store.saving"
          @submit="handleSubmit"
          @cancel="router.push({ name: 'kartu.index' })"
        />
      </div>
    </div>
  </div>
</template>
