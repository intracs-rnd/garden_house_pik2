<script setup>
import { onMounted, ref, computed } from 'vue'
import cameraApi from '@/api/camera'
import { useToast } from '@/composables/useToast'
import { extractErrorMessage } from '@/utils/helper'
import { useAuthStore } from '@/stores/auth'
import PageHeader from '@/components/layout/Header.vue'
import Button from '@/components/common/Button.vue'
import Loader from '@/components/common/Loader.vue'
import LiveStream from '@/components/common/LiveStream.vue'

const auth = useAuthStore()
const canManage = computed(() => auth.canManage('cameras'))

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

// Kamera 3 dan 4 sementara tidak digunakan — hanya tampilkan 2 kamera pertama
const visibleCameras = computed(() => cameras.value.slice(0, 2))

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
      <template v-if="canManage" #actions>
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
      <p v-if="canManage" class="cam-hint">
        URL RTSP wajib diawali <code>rtsp://</code>. Contoh:
        <code>rtsp://root:cctv123456@192.xxx.xxx.xxx:554/live2.sdp</code>.
        Kredensial kamera hanya disimpan di server dan tidak pernah dikirim ke pemutar di browser.
      </p>

      <div class="cam-grid">
        <!-- Kamera 3 dan 4 sementara disembunyikan; hanya 2 kamera pertama yang ditampilkan -->
        <div v-for="cam in visibleCameras" :key="cam.path" class="cam-card">
          <div class="cam-card-head">
            <span class="cam-path">{{ cam.path }}</span>
            <label v-if="canManage" class="cam-toggle">
              <input type="checkbox" v-model="cam.enabled" @change="markChanged" />
              <span>{{ cam.enabled ? 'Aktif' : 'Nonaktif' }}</span>
            </label>
            <span v-else class="cam-toggle-readonly">{{ cam.enabled ? 'Aktif' : 'Nonaktif' }}</span>
          </div>

          <div class="cam-preview">
            <LiveStream v-if="cam.enabled" :src="cam.stream_url" :label="cam.name" />
            <div v-else class="cam-preview-off">Kamera nonaktif</div>
          </div>

          <template v-if="canManage">
            <label class="cam-field">
              <span>Nama Kamera</span>
              <input
                  type="text"
                  v-model="cam.name"
                  placeholder="mis. Gerbang Utama"
                  maxlength="100"
                  @input="markChanged()"
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
                  @input="markChanged()"
              />
            </label>
          </template>

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

<style scoped src="./setting_css/CameraSettings.css"></style>
