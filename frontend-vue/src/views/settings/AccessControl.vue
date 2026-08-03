<script setup>
import { onMounted, ref, reactive, computed, watch } from 'vue'
import { useAccessControlStore, ROLE_LABELS, ACCESS_OPTIONS } from '@/stores/accessControl'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { extractErrorMessage } from '@/utils/helper'
import PageHeader from '@/components/layout/Header.vue'
import Button from '@/components/common/Button.vue'

const store = useAccessControlStore()
const auth = useAuthStore()
const toast = useToast()

const selectedRole = ref('')
// Local editable draft: { feature_key: 'view' | 'manage' | '' }
const draft = reactive({})

const accessOptions = ACCESS_OPTIONS

function roleLabel(role) {
  return ROLE_LABELS[role] || role
}

/** (Re)build the draft from the stored matrix for the selected role. */
function loadDraft() {
  Object.keys(draft).forEach((k) => delete draft[k])
  const current = store.permissions[selectedRole.value] || {}
  store.features.forEach((f) => {
    draft[f.key] = current[f.key] || ''
  })
}

watch(selectedRole, loadDraft)

const grantedCount = computed(
  () => Object.values(draft).filter((v) => v === 'view' || v === 'manage').length,
)

async function handleSave() {
  if (!selectedRole.value) return
  try {
    await store.saveRole(selectedRole.value, { ...draft })
    toast.success(`Hak akses untuk ${roleLabel(selectedRole.value)} berhasil disimpan.`)
    loadDraft()
    // Refresh the current user's own permissions in case they changed.
    auth.fetchUser().catch(() => {})
  } catch (error) {
    toast.error(extractErrorMessage(error, 'Gagal menyimpan hak akses.'))
  }
}

onMounted(async () => {
  try {
    await store.fetchMatrix()
    if (store.roles.length) {
      selectedRole.value = store.roles[0]
      loadDraft()
    }
  } catch {
    // error surfaced via store.error
  }
})
</script>

<template>
  <div class="page">
    <PageHeader
      title="Pengaturan Hak Akses"
      subtitle="Atur fitur/menu apa saja yang bisa diakses tiap peran, serta apakah hanya bisa melihat atau boleh melakukan aksi."
    >
      <template #actions>
        <Button variant="primary" :loading="store.saving" :disabled="!selectedRole" @click="handleSave">
          Simpan Perubahan
        </Button>
      </template>
    </PageHeader>

    <div v-if="store.error" class="alert alert-danger">{{ store.error }}</div>

    <div class="card">
      <div class="card-header role-tabs">
        <span class="role-tabs-label">Peran:</span>
        <button
          v-for="role in store.roles.filter(r => r !== 'staff')"
          :key="role"
          type="button"
          class="role-tab"
          :class="{ 'is-active': role === selectedRole }"
          @click="selectedRole = role"
        >
          {{ roleLabel(role) }}
        </button>
        <span class="role-note">
          Super Admin selalu memiliki akses penuh dan tidak dapat diubah.
        </span>
      </div>

      <div v-if="store.loading" class="empty-state">Memuat data hak akses...</div>

      <table v-else class="feature-table">
        <thead>
          <tr>
            <th class="feature-col">Fitur / Menu</th>
            <th v-for="opt in accessOptions" :key="opt.value" class="access-col">
              {{ opt.label }}
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="feature in store.features" :key="feature.key">
            <td class="feature-col">
              <strong>{{ feature.label }}</strong>
              <small class="feature-key">{{ feature.key }}</small>
            </td>
            <td v-for="opt in accessOptions" :key="opt.value" class="access-col">
              <label class="radio-cell">
                <input
                  type="radio"
                  :name="`feature-${feature.key}`"
                  :value="opt.value"
                  v-model="draft[feature.key]"
                />
              </label>
            </td>
          </tr>
        </tbody>
      </table>

      <div class="card-footer">
        <small>{{ grantedCount }} fitur diberikan akses untuk peran {{ roleLabel(selectedRole) }}.</small>
      </div>
    </div>
  </div>
</template>

<style scoped src="./setting_css/AccessControl.css"></style>
