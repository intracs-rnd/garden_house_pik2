<script setup>
import { onMounted, ref } from 'vue'
import cardsApi from '@/api/cards'
import { extractErrorMessage } from '@/utils/helper'
import { formatDate } from '@/utils/formatter'
import { useToast } from '@/composables/useToast'
import PageHeader from '@/components/layout/Header.vue'
import Button from '@/components/common/Button.vue'
import DataTable from '@/components/common/DataTable.vue'
import Modal from '@/components/common/Modal.vue'

const toast = useToast()

// --- Import CSV ---
const fileInput    = ref(null)
const selectedFile = ref(null)
const uploading    = ref(false)
const progress     = ref(0)
const importResult = ref(null)
const importError  = ref('')

const SAMPLE_CSV = `No,UID,Status,Datetime
1,1100EE00E200470D896064267408010C9684,ALLOW,2026-08-03 10:15:24
2,1100EE00E20047061C506426FD37010C8EE4,REJECT,2026-08-03 10:15:29`

function onFileChange(e) {
  const f = e.target.files?.[0]
  if (!f) return
  selectedFile.value = f
  importResult.value = null
  importError.value  = ''
}

function onDrop(e) {
  e.preventDefault()
  const f = e.dataTransfer.files?.[0]
  if (!f) return
  selectedFile.value = f
  importResult.value = null
  importError.value  = ''
}

function downloadSample() {
  const blob = new Blob([SAMPLE_CSV], { type: 'text/csv' })
  const url  = URL.createObjectURL(blob)
  const a    = document.createElement('a')
  a.href     = url
  a.download = 'sample-import-cards.csv'
  a.click()
  URL.revokeObjectURL(url)
}

function resetImport() {
  selectedFile.value = null
  importResult.value = null
  importError.value  = ''
  progress.value     = 0
  if (fileInput.value) fileInput.value.value = ''
}

async function doImport() {
  if (!selectedFile.value) return
  uploading.value    = true
  progress.value     = 0
  importResult.value = null
  importError.value  = ''
  try {
    const res = await cardsApi.importCsv(selectedFile.value, (p) => {
      progress.value = p
    })
    importResult.value = res.data
    toast.success(res.message || 'Import berhasil.')
    fetchCards(1)
  } catch (err) {
    importError.value = extractErrorMessage(err, 'Gagal melakukan import CSV.')
    toast.error(importError.value)
  } finally {
    uploading.value = false
  }
}

// --- Cards List ---
const columns = [
  { key: 'id',         label: 'ID',      width: '64px',  align: 'center' },
  { key: 'uid',        label: 'UID' },
  { key: 'status',     label: 'Status',  width: '120px', align: 'center' },
  { key: 'name',       label: 'Name',    width: '140px' },
  { key: 'unit',       label: 'Unit',    width: '110px' },
  { key: 'expiry',     label: 'Expiry',  width: '120px' },
  { key: 'grace_days', label: 'Grace',   width: '72px',  align: 'center' },
  { key: 'actions',    label: '',        width: '120px', align: 'right' },
]

const rows        = ref([])
const listLoading = ref(false)
const listError   = ref('')
const search      = ref('')
const meta        = ref({ current_page: 1, per_page: 15, total: 0, last_page: 1 })

async function fetchCards(page = meta.value.current_page, perPage = meta.value.per_page) {
  listLoading.value = true
  listError.value   = ''
  try {
    const res = await cardsApi.list({
      page,
      per_page: perPage,
      search: search.value || undefined,
    })
    rows.value = res.data || []
    if (res.meta) meta.value = res.meta
  } catch (err) {
    listError.value = extractErrorMessage(err, 'Gagal memuat daftar cards.')
  } finally {
    listLoading.value = false
  }
}

function onSearch() { fetchCards(1) }

function statusClass(status) {
  switch ((status || '').toUpperCase()) {
    case 'ALLOW':  return 'badge-success'
    case 'REJECT': return 'badge-danger'
    default:       return 'badge-muted'
  }
}

// --- Edit Modal ---
const STATUSES = ['ALLOW', 'REJECT']

const editOpen   = ref(false)
const editTarget = ref(null)
const editSaving = ref(false)
const editError  = ref('')
const editForm   = ref({ name: '', unit: '', status: '', expiry: '', grace_days: 0 })

function openEdit(row) {
  editTarget.value = row
  editForm.value   = {
    name:       row.name       ?? '',
    unit:       row.unit       ?? '',
    status:     row.status     ?? '',
    expiry:     row.expiry ? row.expiry.substring(0, 10) : '',
    grace_days: row.grace_days ?? 0,
  }
  editError.value = ''
  editOpen.value  = true
}

async function saveEdit() {
  if (!editTarget.value) return
  editSaving.value = true
  editError.value  = ''
  try {
    // Kirim hanya field yang berisi nilai, jangan kirim expiry kosong sebagai string
    const payload = {
      status:     editForm.value.status,
      name:       editForm.value.name       ?? '',
      unit:       editForm.value.unit       ?? '',
      grace_days: editForm.value.grace_days ?? 0,
    }
    if (editForm.value.expiry) {
      payload.expiry = editForm.value.expiry.substring(0, 10)
    }
    await cardsApi.update(editTarget.value.id, payload)
    toast.success('Card berhasil diperbarui.')
    editOpen.value = false
    fetchCards()
  } catch (err) {
    editError.value = extractErrorMessage(err, 'Gagal menyimpan perubahan.')
  } finally {
    editSaving.value = false
  }
}

// --- Delete ---
const deleteTarget  = ref(null)
const deleteOpen    = ref(false)
const deleteLoading = ref(false)

function openDelete(row) {
  deleteTarget.value = row
  deleteOpen.value   = true
}

async function confirmDelete() {
  if (!deleteTarget.value) return
  deleteLoading.value = true
  try {
    await cardsApi.remove(deleteTarget.value.id)
    toast.success('Card berhasil dihapus.')
    deleteOpen.value = false
    fetchCards()
  } catch (err) {
    toast.error(extractErrorMessage(err, 'Gagal menghapus card.'))
  } finally {
    deleteLoading.value = false
  }
}

onMounted(() => fetchCards(1))
</script>

<template>
  <div>
    <PageHeader
      title="Import Cards (CSV)"
      subtitle="Import bulk kartu/card dari file CSV - khusus Super Admin."
    />

    <!-- Import Section -->
    <div class="card upload-card">
      <h3 class="section-title">Upload File CSV</h3>
      <p class="section-desc">
        Format kolom yang diperlukan:
        <code>No, UID, Status, Datetime</code>
      </p>

      <div
        class="drop-zone"
        :class="{ 'has-file': selectedFile }"
        @dragover.prevent
        @drop="onDrop"
        @click="fileInput.click()"
      >
        <input
          ref="fileInput"
          type="file"
          accept=".csv,.txt"
          class="hidden-input"
          @change="onFileChange"
        />
        <template v-if="selectedFile">
          <svg class="dz-icon dz-icon--success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M9 13l2 2 4-4" />
          </svg>
          <p class="dz-filename">{{ selectedFile.name }}</p>
          <p class="dz-filesize">{{ (selectedFile.size / 1024).toFixed(1) }} KB</p>
        </template>
        <template v-else>
          <svg class="dz-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12" />
          </svg>
          <p class="dz-label">Klik atau seret file CSV ke sini</p>
          <p class="dz-hint">Maks. 5 MB &middot; .csv / .txt</p>
        </template>
      </div>

      <div v-if="uploading" class="progress-wrap">
        <div class="progress-bar">
          <div class="progress-fill" :style="{ width: progress + '%' }"></div>
        </div>
        <span class="progress-label">{{ progress }}%</span>
      </div>

      <p v-if="importError" class="error-msg">{{ importError }}</p>

      <!-- Import Result -->
      <div v-if="importResult" class="import-result">
        <div class="stat-row">
          <div class="stat-item stat-success">
            <span class="stat-num">{{ importResult.imported }}</span>
            <span class="stat-label">Berhasil diimpor</span>
          </div>
          <div class="stat-item stat-warning">
            <span class="stat-num">{{ importResult.skipped }}</span>
            <span class="stat-label">Dilewati (duplikat)</span>
          </div>
          <div class="stat-item stat-danger">
            <span class="stat-num">{{ importResult.errors.length }}</span>
            <span class="stat-label">Error</span>
          </div>
        </div>
        <div v-if="importResult.errors.length > 0" class="error-list">
          <p class="error-list-title">Detail Error</p>
          <ul>
            <li v-for="(e, i) in importResult.errors" :key="i">{{ e }}</li>
          </ul>
        </div>
      </div>

      <div class="action-row">
        <Button variant="ghost" size="sm" @click="downloadSample">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="btn-icon">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>
          </svg>
          Unduh Contoh CSV
        </Button>
        <div class="action-right">
          <Button v-if="selectedFile" variant="secondary" size="sm" :disabled="uploading" @click="resetImport">Batal</Button>
          <Button variant="primary" size="sm" :disabled="!selectedFile || uploading" :loading="uploading" @click="doImport">
            Import Sekarang
          </Button>
        </div>
      </div>
    </div>

    <!-- Cards List -->
    <div class="card list-card">
      <div class="list-header">
        <h3 class="section-title" style="margin:0">Daftar Cards</h3>
        <div class="list-toolbar">
          <form class="search-form" @submit.prevent="onSearch">
            <input
              v-model="search"
              class="form-control"
              type="search"
              placeholder="Cari UID, name, unit, status..."
            />
            <Button type="submit" size="sm" variant="primary">Cari</Button>
          </form>
          <Button size="sm" variant="ghost" @click="fetchCards()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="btn-icon">
              <path d="M23 4v6h-6M1 20v-6h6"/>
              <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
            </svg>
            Muat ulang
          </Button>
        </div>
      </div>

      <DataTable
        :columns="columns"
        :rows="rows"
        :loading="listLoading"
        :error="listError"
        :page="meta.current_page"
        :per-page="meta.per_page"
        :total="meta.total"
        :last-page="meta.last_page"
        show-index
        empty-text="Belum ada data card."
        @change-page="(p) => fetchCards(p)"
        @change-per-page="(pp) => fetchCards(1, pp)"
      >
        <template #cell-uid="{ row }">
          <code class="uid-cell">{{ row.uid }}</code>
        </template>

        <template #cell-status="{ row }">
          <span class="badge" :class="statusClass(row.status)">{{ row.status || '-' }}</span>
        </template>

        <template #cell-name="{ row }">
          <span :class="{ 'text-muted': !row.name }">{{ row.name || '-' }}</span>
        </template>

        <template #cell-unit="{ row }">
          <span :class="{ 'text-muted': !row.unit }">{{ row.unit || '-' }}</span>
        </template>

        <template #cell-expiry="{ row }">
          {{ row.expiry ? formatDate(row.expiry) : '-' }}
        </template>

        <template #cell-grace_days="{ row }">
          {{ row.grace_days ?? '-' }}
        </template>

        <template #cell-actions="{ row }">
          <div class="action-btns">
            <Button size="sm" variant="ghost" @click="openEdit(row)">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="btn-icon">
                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
              </svg>
              Edit
            </Button>
            <Button size="sm" variant="danger" @click="openDelete(row)">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="btn-icon">
                <polyline points="3 6 5 6 21 6"/>
                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                <path d="M10 11v6M14 11v6"/>
                <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
              </svg>
              Hapus
            </Button>
          </div>
        </template>
      </DataTable>
    </div>

    <!-- Edit Modal -->
    <Modal v-model="editOpen" title="Edit Card">
      <div class="edit-form">
        <div class="form-group">
          <label class="form-label">UID</label>
          <input class="form-control" :value="editTarget?.uid" disabled />
        </div>
        <div class="form-group">
          <label class="form-label">Status</label>
          <select v-model="editForm.status" class="form-control">
            <option v-for="s in STATUSES" :key="s" :value="s">{{ s }}</option>
          </select>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Name</label>
            <input v-model="editForm.name" class="form-control" type="text" placeholder="Nama pemilik (opsional)" />
          </div>
          <div class="form-group">
            <label class="form-label">Unit</label>
            <input v-model="editForm.unit" class="form-control" type="text" placeholder="No. unit (opsional)" />
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Expiry</label>
            <input v-model="editForm.expiry" class="form-control" type="date" />
          </div>
          <div class="form-group">
            <label class="form-label">Grace Days</label>
            <input v-model.number="editForm.grace_days" class="form-control" type="number" min="0" />
          </div>
        </div>
        <p v-if="editError" class="error-msg">{{ editError }}</p>
      </div>
      <template #footer>
        <Button variant="secondary" :disabled="editSaving" @click="editOpen = false">Batal</Button>
        <Button variant="primary" :loading="editSaving" @click="saveEdit">Simpan</Button>
      </template>
    </Modal>

    <!-- Delete Confirm Modal -->
    <Modal v-model="deleteOpen" title="Hapus Card">
      <p>Yakin ingin menghapus card dengan UID:</p>
      <code class="uid-cell uid-cell--block">{{ deleteTarget?.uid }}</code>
      <p class="delete-warn">Tindakan ini tidak dapat dibatalkan.</p>
      <template #footer>
        <Button variant="secondary" :disabled="deleteLoading" @click="deleteOpen = false">Batal</Button>
        <Button variant="danger" :loading="deleteLoading" @click="confirmDelete">Ya, Hapus</Button>
      </template>
    </Modal>
  </div>
</template>

<style scoped>
.section-title {
  font-size: 15px;
  font-weight: 600;
  color: var(--color-text);
  margin: 0 0 6px;
}
.section-desc {
  font-size: 13px;
  color: var(--color-text-muted, #6b7280);
  margin: 0 0 16px;
}

.upload-card { margin-bottom: 20px; }

/* Drop zone */
.drop-zone {
  border: 2px dashed var(--color-border, #e2e8f0);
  border-radius: var(--radius, 8px);
  padding: 32px 24px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  cursor: pointer;
  transition: border-color 0.2s, background 0.2s;
  background: var(--color-bg-subtle, #f8fafc);
  user-select: none;
}
.drop-zone:hover,
.drop-zone.has-file {
  border-color: var(--color-primary, #3b82f6);
  background: color-mix(in srgb, var(--color-primary, #3b82f6) 5%, transparent);
}
.hidden-input { display: none; }
.dz-icon {
  width: 38px;
  height: 38px;
  color: var(--color-text-muted, #94a3b8);
}
.dz-icon--success { color: var(--color-success, #16a34a); }
.dz-label    { font-size: 14px; font-weight: 500; color: var(--color-text, #1e293b); margin: 0; }
.dz-hint     { font-size: 12px; color: var(--color-text-muted, #94a3b8); margin: 0; }
.dz-filename { font-size: 14px; font-weight: 600; color: var(--color-primary, #3b82f6); margin: 0; }
.dz-filesize { font-size: 12px; color: var(--color-text-muted, #94a3b8); margin: 0; }

/* Progress */
.progress-wrap {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 12px;
}
.progress-bar {
  flex: 1;
  height: 6px;
  background: var(--color-border, #e2e8f0);
  border-radius: 99px;
  overflow: hidden;
}
.progress-fill {
  height: 100%;
  background: var(--color-primary, #3b82f6);
  border-radius: 99px;
  transition: width 0.2s;
}
.progress-label { font-size: 12px; color: var(--color-text-muted); min-width: 36px; text-align: right; }

.error-msg { color: var(--color-danger, #dc2626); font-size: 13px; margin: 8px 0 0; }

/* Import result */
.import-result { margin-top: 16px; }
.stat-row { display: flex; gap: 12px; margin-bottom: 12px; flex-wrap: wrap; }
.stat-item {
  flex: 1;
  min-width: 90px;
  padding: 14px;
  border-radius: var(--radius, 8px);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
}
.stat-success { background: #f0fdf4; }
.stat-warning { background: #fffbeb; }
.stat-danger  { background: #fef2f2; }
.stat-num { font-size: 26px; font-weight: 700; line-height: 1; }
.stat-success .stat-num { color: #16a34a; }
.stat-warning .stat-num { color: #d97706; }
.stat-danger  .stat-num { color: #dc2626; }
.stat-label { font-size: 12px; color: var(--color-text-muted, #6b7280); text-align: center; }
.error-list {
  background: #fef2f2;
  border-radius: var(--radius, 8px);
  padding: 10px 14px;
}
.error-list-title { font-size: 13px; font-weight: 600; color: #dc2626; margin: 0 0 6px; }
.error-list ul { margin: 0; padding-left: 16px; }
.error-list li { font-size: 12px; color: #991b1b; margin-bottom: 3px; }

/* Action row */
.action-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 16px;
  flex-wrap: wrap;
  gap: 8px;
}
.action-right { display: flex; gap: 8px; }

/* List header */
.list-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
  padding: 16px 16px 0;
}
.list-toolbar {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.search-form {
  display: flex;
  gap: 8px;
  align-items: center;
}
.search-form .form-control { width: 240px; }

/* SVG icon inside button */
.btn-icon {
  width: 14px;
  height: 14px;
  flex-shrink: 0;
  margin-right: 4px;
  vertical-align: middle;
}

/* Table cells */
.uid-cell {
  font-size: 11.5px;
  font-family: monospace;
  color: var(--color-text);
  word-break: break-all;
}
.uid-cell--block {
  display: block;
  margin: 6px 0;
  padding: 8px 10px;
  background: var(--color-bg-subtle, #f8fafc);
  border-radius: var(--radius-sm, 6px);
  font-size: 12px;
}
.text-muted { color: var(--color-text-muted, #94a3b8); }
.action-btns { display: flex; gap: 4px; justify-content: flex-end; }

/* Edit form */
.edit-form { display: flex; flex-direction: column; gap: 14px; }
.form-group { display: flex; flex-direction: column; gap: 4px; }
.form-label { font-size: 13px; font-weight: 500; color: var(--color-text); }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

.delete-warn { color: var(--color-danger, #dc2626); font-size: 13px; margin: 8px 0 0; }
</style>