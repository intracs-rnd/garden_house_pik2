<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useKartuStore } from '@/stores/kartu'
import { useToast } from '@/composables/useToast'
import { extractValidationErrors, extractErrorMessage } from '@/utils/helper'
import PageHeader from '@/components/layout/Header.vue'
import KartuForm from '@/components/forms/KartuForm.vue'
import Loader from '@/components/common/Loader.vue'

const props = defineProps({
  id: { type: [String, Number], required: true },
})

const router = useRouter()
const store = useKartuStore()
const toast = useToast()

const loading = ref(true)
const errors = ref({})
const availableRfids = ref([])

// Simpan masa berlaku awal supaya kita tahu ketika user memperpanjangnya.
const originalValidUntil = ref('')
const originalValidFrom = ref('')
const originalGraceDays = ref(0)

const STATUS_AKTIF = 1
const STATUS_NONAKTIF = 2

const form = reactive({
  user_id: '',
  card_number: '',
  nama: '',
  rfid_tag: '',
  status: 1,
  is_blacklisted: false,
  blacklist_reason: '',
  valid_from: '',
  valid_until: '',
  grace_days: 0,
  keterangan: '',
})

// Offset WIB (Asia/Jakarta = UTC+7), di-hardcode supaya TIDAK bergantung
// pada timezone sistem operasi/browser tempat kode ini dijalankan.
// (Kalau timezone OS server/browser bukan Asia/Jakarta, memakai
// date.getHours()/getMinutes() bawaan JS tidak akan menghasilkan
// konversi yang benar — ini penyebab fix sebelumnya "tidak berubah".)
const WIB_OFFSET_MINUTES = 7 * 60

/**
 * Convert datetime UTC dari API menjadi format "yyyy-mm-ddThh:mm" dalam
 * waktu WIB, sesuai yang dibutuhkan <input type="datetime-local">.
 */
function toDateTimeInput(value) {
  if (!value) return ''

  // Backend bisa mengirim "2026-07-08 02:10:00" (tanpa Z) atau
  // "2026-07-08T02:10:00.000000Z". Pastikan diperlakukan sebagai UTC
  // dengan menambahkan sufiks "Z" jika belum ada info timezone.
  let iso = String(value).replace(' ', 'T')
  if (!/Z$|[+-]\d{2}:?\d{2}$/.test(iso)) {
    iso += 'Z'
  }

  const utcMs = Date.parse(iso)
  if (Number.isNaN(utcMs)) return ''

  // Geser waktu UTC +7 jam, lalu baca komponen tanggal/jam dengan
  // getter UTC (bukan getter lokal) supaya hasilnya deterministik,
  // tidak peduli timezone OS/browser yang menjalankan kode ini.
  const wib = new Date(utcMs + WIB_OFFSET_MINUTES * 60000)

  const pad = (n) => String(n).padStart(2, '0')
  const yyyy = wib.getUTCFullYear()
  const mm = pad(wib.getUTCMonth() + 1)
  const dd = pad(wib.getUTCDate())
  const hh = pad(wib.getUTCHours())
  const min = pad(wib.getUTCMinutes())

  return `${yyyy}-${mm}-${dd}T${hh}:${min}`
}

function buildPayload() {
  const payload = { ...form }
  Object.keys(payload).forEach((key) => {
    if (payload[key] === '' || payload[key] === null) delete payload[key]
  })
  if (!form.is_blacklisted) {
    payload.is_blacklisted = false
    delete payload.blacklist_reason
  }
  return payload
}

/**
 * Convert string "yyyy-mm-ddThh:mm" (waktu WIB, hasil dari <input
 * type="datetime-local">) menjadi epoch ms UTC yang sebenarnya, tanpa
 * bergantung pada timezone sistem OS/browser.
 */
function wibInputToUtcMs(wibInput) {
  if (!wibInput) return NaN
  // Parse sebagai UTC dulu (anggap digitnya UTC), lalu kurangi offset WIB
  // supaya didapat epoch ms yang benar-benar merepresentasikan waktu WIB tsb.
  const asUtcMs = Date.parse(`${wibInput}:00Z`)
  if (Number.isNaN(asUtcMs)) return NaN
  return asUtcMs - WIB_OFFSET_MINUTES * 60000
}

/**
 * Jika masa berlaku efektif (valid_until + masa tenggang) diperpanjang atau
 * valid_from mundur sehingga kartu kini berada di rentang berlaku, aktifkan
 * kembali kartu yang tadinya non-aktif karena kadaluarsa. Reaktivasi hanya
 * terjadi jika salah satu field validitas benar-benar berubah, sehingga
 * admin yang sengaja menonaktifkan kartu (tanpa mengubah masa berlaku)
 * tidak diganggu. Kartu blacklist tidak pernah diaktifkan dari sini.
 */
function reactivateIfExtended() {
  if (form.is_blacklisted || !form.valid_until) return

  const newUntilMs = wibInputToUtcMs(form.valid_until)
  if (Number.isNaN(newUntilMs)) return

  const nowMs = Date.now()
  const graceDays = Math.max(0, Number(form.grace_days) || 0)
  const effectiveUntilMs = newUntilMs + graceDays * 86400000

  // Kartu belum berlaku jika valid_from ada dan masih di masa depan.
  const newFromMs = form.valid_from ? wibInputToUtcMs(form.valid_from) : null
  const notYetValid = newFromMs !== null && !Number.isNaN(newFromMs) && newFromMs > nowMs

  // Deteksi perubahan pada salah satu field validitas dibandingkan data awal.
  const prevUntilMs = originalValidUntil.value ? wibInputToUtcMs(originalValidUntil.value) : null
  const prevFromMs = originalValidFrom.value ? wibInputToUtcMs(originalValidFrom.value) : null
  const untilChanged = prevUntilMs === null || newUntilMs !== prevUntilMs
  const fromChanged = (prevFromMs ?? null) !== (newFromMs ?? null)
  const graceChanged = graceDays !== Number(originalGraceDays.value || 0)
  const validityChanged = untilChanged || fromChanged || graceChanged

  if (
    validityChanged
    && effectiveUntilMs > nowMs
    && !notYetValid
    && Number(form.status) === STATUS_NONAKTIF
  ) {
    form.status = STATUS_AKTIF
  }
}

async function handleSubmit() {
  errors.value = {}
  reactivateIfExtended()
  try {
    await store.update(props.id, buildPayload())
    toast.success('Perubahan berhasil disimpan.')
    router.push({ name: 'kartu.index' })
  } catch (error) {
    errors.value = extractValidationErrors(error)
    toast.error(extractErrorMessage(error, 'Gagal menyimpan perubahan.'))
  }
}

onMounted(async () => {
  await store.fetchUsers()
  try {
    const data = await store.fetchOne(props.id)
    Object.assign(form, {
      user_id: data.user_id ?? '',
      card_number: data.card_number ?? '',
      nama: data.nama ?? '',
      rfid_tag: data.rfid_tag ?? '',
      status: data.status ?? 1,
      is_blacklisted: Boolean(data.is_blacklisted),
      blacklist_reason: data.blacklist_reason ?? '',
      valid_from: toDateTimeInput(data.valid_from),
      valid_until: toDateTimeInput(data.valid_until),
      grace_days: data.grace_days ?? 0,
      keterangan: data.keterangan ?? '',
    })
    originalValidUntil.value = toDateTimeInput(data.valid_until)
    originalValidFrom.value = toDateTimeInput(data.valid_from)
    originalGraceDays.value = Number(data.grace_days ?? 0)

    availableRfids.value = await store.fetchAvailableRfid(data.rfid_tag)
  } catch (error) {
    toast.error(extractErrorMessage(error, 'Data kartu akses tidak ditemukan.'))
    router.push({ name: 'kartu.index' })
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="page">
    <PageHeader title="Edit Kartu Akses" subtitle="Perbarui data kartu akses" />

    <div class="card">
      <div class="card-body">
        <Loader v-if="loading" text="Memuat data..." />
        <KartuForm
            v-else
            :form="form"
            :errors="errors"
            :users="store.users"
            :rfids="availableRfids"
            :saving="store.saving"
            is-edit
            @submit="handleSubmit"
            @cancel="router.push({ name: 'kartu.index' })"
        />
      </div>
    </div>
  </div>
</template>