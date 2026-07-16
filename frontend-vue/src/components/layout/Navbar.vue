<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'

const emit = defineEmits(['toggle-sidebar', 'toggle-sidebar-collapse'])

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toast = useToast()

const menuOpen = ref(false)
const isMobile = ref(window.innerWidth <= 1024)

function handleBurgerClick() {
  if (isMobile.value) {
    emit('toggle-sidebar')
  } else {
    emit('toggle-sidebar-collapse')
  }
}

function toggleMenu() {
  menuOpen.value = !menuOpen.value
}

function goToProfile() {
  menuOpen.value = false
  router.push({ name: 'profile' })
}

async function handleLogout() {
  menuOpen.value = false
  await auth.logout()
  toast.success('Anda telah keluar.')
  router.push({ name: 'login' })
}

window.addEventListener('resize', () => {
  isMobile.value = window.innerWidth <= 1024
})
</script>

<template>
  <header class="navbar">
    <div class="navbar-left">
      <button 
        class="navbar-toggle" 
        type="button" 
        @click="handleBurgerClick"
        aria-label="Toggle sidebar"
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M4 6h16M4 12h16M4 18h16" stroke-linecap="round" />
        </svg>
      </button>
      <h1 class="navbar-title">{{ route.meta.title || 'Dashboard' }}</h1>
    </div>

    <div class="navbar-right">
      <div class="user-menu">
        <button class="user-trigger" type="button" @click="toggleMenu">
          <span class="user-avatar">{{ auth.userInitials }}</span>
          <span class="user-info">
            <strong>{{ auth.userName }}</strong>
            <small>{{ auth.user?.role || 'user' }}</small>
          </span>
          <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M6 9l6 6 6-6" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
        </button>

        <div v-if="menuOpen" class="user-dropdown" @click.self="menuOpen = false">
          <div class="dropdown-header">
            <strong>{{ auth.userName }}</strong>
            <small>{{ auth.user?.email }}</small>
          </div>
          <button class="dropdown-item" type="button" @click="goToProfile">
            Profil Saya
          </button>
          <button class="dropdown-item danger" type="button" @click="handleLogout">
            Keluar
          </button>
        </div>
      </div>
    </div>

    <!-- Click-away layer -->
    <div v-if="menuOpen" class="menu-backdrop" @click="menuOpen = false"></div>
  </header>
</template>

<style scoped>
.navbar {
  position: sticky;
  top: 0;
  height: var(--header-height);
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 24px;
  background: var(--color-surface);
  border-bottom: 1px solid var(--color-border);
  z-index: 30;
}
.navbar-left {
  display: flex;
  align-items: center;
  gap: 14px;
}
.navbar-toggle {
  display: grid;
  place-items: center;
  width: 38px;
  height: 38px;
  border: 1px solid var(--color-border);
  background: #fff;
  border-radius: var(--radius-sm);
  cursor: pointer;
  color: var(--color-text);
}
.navbar-toggle svg {
  width: 20px;
  height: 20px;
}
.navbar-title {
  font-size: 18px;
}
.navbar-right {
  display: flex;
  align-items: center;
  gap: 16px;
}
.user-menu {
  position: relative;
}
.user-trigger {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 6px 8px;
  background: none;
  border: none;
  cursor: pointer;
  border-radius: var(--radius-sm);
}
.user-trigger:hover {
  background: #f1f5f9;
}
.user-avatar {
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: var(--color-primary);
  color: #fff;
  font-weight: 600;
  font-size: 13px;
}
.user-info {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  line-height: 1.25;
}
.user-info strong {
  font-size: 14px;
}
.user-info small {
  font-size: 12px;
  color: var(--color-text-muted);
  text-transform: capitalize;
}
.chevron {
  width: 16px;
  height: 16px;
  color: var(--color-text-muted);
}
.user-dropdown {
  position: absolute;
  right: 0;
  top: calc(100% + 8px);
  width: 220px;
  background: #fff;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  box-shadow: var(--shadow-lg);
  overflow: hidden;
  z-index: 60;
}
.dropdown-header {
  display: flex;
  flex-direction: column;
  padding: 14px 16px;
  border-bottom: 1px solid var(--color-border);
}
.dropdown-header small {
  color: var(--color-text-muted);
  font-size: 12px;
  margin-top: 2px;
}
.dropdown-item {
  width: 100%;
  text-align: left;
  padding: 12px 16px;
  background: none;
  border: none;
  cursor: pointer;
  font-size: 14px;
  color: var(--color-text);
}
.dropdown-item:hover {
  background: #f8fafc;
}
.dropdown-item.danger {
  color: var(--color-danger);
}
.menu-backdrop {
  position: fixed;
  inset: 0;
  z-index: 50;
}

@media (max-width: 1024px) {
  .user-info {
    display: none;
  }
}
</style>
