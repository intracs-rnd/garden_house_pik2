<script setup>
import { onMounted, ref, computed } from 'vue'
import cameraApi from '@/api/camera'
import { useToast } from '@/composables/useToast'
import { extractErrorMessage } from '@/utils/helper'
import PageHeader from '@/components/layout/Header.vue'
import Button from '@/components/common/Button.vue'
import Loader from '@/components/common/Loader.vue'
import LiveStream from '@/components/common/LiveStream.vue'

const toast = useToast()

const loading = ref(true)
const saving = ref(false)
const reapplying = ref(false)
const error = ref('')

// Editable draft: [{ path, name, rtsp_url, enabled, stream_url }]
const cameras = ref([])
// Per-camera apply status returned after saving (keyed by path).
const applyStatus = ref({})

const hasChanges = ref(false)

function markChanged() {
  hasChanges.value = true
}

const applyList = computed(() => Object.values(applyStatus.value))

const statusMeta = {
  applied: { label: 'Diterapkan', variant: 'ok' },
  failed: { label: 'Gagal', variant: 'error' },
  unreachable: { label: 'go2rtc mati', variant: 'warn' },
  skipped: { label: 'Dilewati', variant: 'muted' },
}

function metaFor(status) {
  return statusMeta[status] || { label: status, variant: 'muted' }
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await cameraApi.getCameras()
    cameras.value = (res.data?.cameras || []).map((c) => ({ ...c }))
  } catch (e) {
    error.value = extractErrorMessage(e, 'Gagal memuat konfigurasi kamera.')
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  saving.value = true
  try {
    const payload = cameras.value.map((c) => ({
      name: c.name,
      rtsp_url: c.rtsp_url || '',
      enabled: !!c.enabled,
    }))
    const res = await cameraApi.updateCameras(payload)

    cameras.value = (res.data?.cameras || []).map((c) => ({ ...c }))

    const status = {}
    ;(res.data?.apply || []).forEach((a) => {
      status[a.path] = a
    })
    applyStatus.value = status
    hasChanges.value = false

    const anyFailure = (res.data?.apply || []).some(
        (a) => a.status === 'failed' || a.status === 'unreachable',
    )
    if (anyFailure) {
      toast.info('Konfigurasi tersimpan, tetapi sebagian gagal diterapkan ke go2rtc.')
    } else {
      toast.success('Konfigurasi kamera tersimpan dan diterapkan.')
    }
  } catch (e) {
    toast.error(extractErrorMessage(e, 'Gagal menyimpan konfigurasi kamera.'))
  } finally {
    saving.value = false
  }
}

async function handleReapply() {
  reapplying.value = true
  try {
    await cameraApi.apply()
    toast.success('Konfigurasi diterapkan ulang ke go2rtc. Memuat ulang halaman...')
    // Reload halaman supaya semua preview live stream ikut ter-refresh
    // dengan konfigurasi terbaru dari go2rtc.
    window.location.reload()
  } catch (e) {
    toast.error(extractErrorMessage(e, 'Gagal menerapkan ke go2rtc.'))
    reapplying.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader
        title="Pengaturan Kamera"
        subtitle="Atur URL RTSP tiap kamera. Perubahan langsung diterapkan ke go2rtc (live CCTV)."
    >
      <template #actions>
        <Button variant="secondary" :disabled="saving || loading || reapplying" :loading="reapplying" @click="handleReapply">
          Terapkan Ulang
        </Button>
        <Button variant="primary" :disabled="saving || loading || reapplying" @click="handleSave">
          {{ saving ? 'Menyimpan...' : 'Simpan' }}
        </Button>
      </template>
    </PageHeader>

    <div v-if="loading" class="cam-loading">
      <Loader />
    </div>

    <div v-else-if="error" class="cam-error">{{ error }}</div>

    <template v-else>
      <p class="cam-hint">
        URL RTSP wajib diawali <code>rtsp://</code>. Contoh:
        <code>rtsp://root:cctv123456@192.168.203.119:554/live2.sdp</code>.
        Kredensial kamera hanya disimpan di server dan tidak pernah dikirim ke pemutar di browser.
      </p>

      <div class="cam-grid">
        <div v-for="cam in cameras" :key="cam.path" class="cam-card">
          <div class="cam-card-head">
            <span class="cam-path">{{ cam.path }}</span>
            <label class="cam-toggle">
              <input type="checkbox" v-model="cam.enabled" @change="markChanged" />
              <span>{{ cam.enabled ? 'Aktif' : 'Nonaktif' }}</span>
            </label>
          </div>

          <div class="cam-preview">
            <LiveStream v-if="cam.enabled" :src="cam.stream_url" :label="cam.name" />
            <div v-else class="cam-preview-off">Kamera nonaktif</div>
          </div>

          <label class="cam-field">
            <span>Nama Kamera</span>
            <input
                type="text"
                v-model="cam.name"
                placeholder="mis. Gerbang Utama"
                maxlength="100"
                @input="markChanged"
            />
          </label>

          <label class="cam-field">
            <span>URL RTSP</span>
            <input
                type="text"
                v-model="cam.rtsp_url"
                placeholder="rtsp://user:pass@ip:554/stream"
                spellcheck="false"
                autocomplete="off"
                @input="markChanged"
            />
          </label>

          <div v-if="applyStatus[cam.path]" class="cam-status" :class="metaFor(applyStatus[cam.path].status).variant">
            <strong>{{ metaFor(applyStatus[cam.path].status).label }}</strong>
            <span>{{ applyStatus[cam.path].detail }}</span>
          </div>
        </div>
      </div>

      <p v-if="hasChanges" class="cam-unsaved">Ada perubahan yang belum disimpan.</p>
    </template>
  </div>
</template>

<style scoped>
.cam-loading {
  display: grid;
  place-items: center;
  padding: 48px;
}
.cam-error {
  color: #b91c1c;
  padding: 16px;
}
.cam-hint {
  font-size: 13px;
  color: #64748b;
  margin: 0 0 16px;
}
.cam-hint code {
  background: #f1f5f9;
  padding: 1px 5px;
  border-radius: 4px;
  font-size: 12px;
}
.cam-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 16px;
}
.cam-card {
  background: #fff;
  border: 1px solid var(--color-border, #e2e8f0);
  border-radius: var(--radius, 8px);
  padding: 16px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.cam-card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.cam-path {
  font-size: 12px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #94a3b8;
}
.cam-toggle {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  cursor: pointer;
}
.cam-preview {
  border-radius: 8px;
  overflow: hidden;
}
.cam-preview-off {
  aspect-ratio: 16 / 9;
  display: grid;
  place-items: center;
  background: #0f172a;
  color: #64748b;
  font-size: 13px;
  border-radius: 8px;
}
.cam-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 13px;
}
.cam-field span {
  font-weight: 600;
  color: #334155;
}
.cam-field input {
  padding: 8px 10px;
  border: 1px solid var(--color-border, #cbd5e1);
  border-radius: 6px;
  font-size: 13px;
  font-family: inherit;
}
.cam-field input:focus {
  outline: none;
  border-color: var(--color-primary, #2563eb);
}
.cam-status {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 8px 10px;
  border-radius: 6px;
  font-size: 12px;
}
.cam-status strong {
  font-size: 12px;
}
.cam-status.ok {
  background: #ecfdf5;
  color: #047857;
}
.cam-status.error {
  background: #fef2f2;
  color: #b91c1c;
}
.cam-status.warn {
  background: #fffbeb;
  color: #b45309;
}
.cam-status.muted {
  background: #f1f5f9;
  color: #64748b;
}
.cam-unsaved {
  margin-top: 12px;
  font-size: 13px;
  color: #b45309;
}
@media (max-width: 768px) {
  .cam-grid {
    grid-template-columns: 1fr;
  }
}
</style>