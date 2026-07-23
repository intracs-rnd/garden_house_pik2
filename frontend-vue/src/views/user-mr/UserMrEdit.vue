<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useUserMrStore } from '@/stores/userMr'
import { useToast } from '@/composables/useToast'
import { extractValidationErrors, extractErrorMessage } from '@/utils/helper'
import PageHeader from '@/components/layout/Header.vue'
import UserMrForm from '@/components/forms/UserMrForm.vue'

const router = useRouter()
const store = useUserMrStore()
const toast = useToast()

const props = defineProps({
  uuid: { type: String, required: true },
})

const form = reactive({
  name: '',
  username: '',
  password: '',
  password_confirmation: '',
})

const errors = ref({})
const loading = ref(true)

onMounted(async () => {
  try {
    await store.fetchOne(props.uuid)
    const user = store.current.data
    form.name = user.name
    form.username = user.username
    form.password = ''
    form.password_confirmation = ''
  } catch (error) {
    toast.error(extractErrorMessage(error, 'Gagal memuat data user MR.'))
    router.push({ name: 'user-mr.index' })
  } finally {
    loading.value = false
  }
})

async function handleSubmit() {
  errors.value = {}
  try {
    await store.update(props.uuid, { ...form })
    toast.success('User MR berhasil diubah.')
    router.push({ name: 'user-mr.index' })
  } catch (error) {
    errors.value = extractValidationErrors(error)
    toast.error(extractErrorMessage(error, 'Gagal mengubah user MR.'))
  }
}
</script>

<template>
  <div class="page">
    <PageHeader title="Edit User MR" subtitle="Ubah data user MR" />

    <div class="card">
      <div class="card-body">
        <div v-if="loading" class="loading">Loading...</div>
        <UserMrForm
          v-else
          :form="form"
          :errors="errors"
          :saving="store.saving"
          is-edit
          @submit="handleSubmit"
          @cancel="router.push({ name: 'user-mr.index' })"
        />
      </div>
    </div>
  </div>
</template>

<style scoped>
.loading {
  text-align: center;
  padding: 20px;
  color: var(--color-muted);
}
</style>
