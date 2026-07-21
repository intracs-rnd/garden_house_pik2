<script setup>
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

defineProps({
  open: { type: Boolean, default: false },
  collapsed: { type: Boolean, default: false },
})

const emit = defineEmits(['navigate', 'toggle'])

const auth = useAuthStore()

const menu = [
  {
    to: { name: 'dashboard' },
    label: 'Dashboard',
    icon: 'M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z',
    feature: null,
  },
  {
    to: { name: 'kartu.index' },
    label: 'Kartu Akses',
    icon: 'M3 5h18a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zm-1 5h20M6 15h4',
    feature: 'kartu',
    adminOnly: true,
  },
  {
    to: { name: 'kartu.gate' },
    label: 'Simulasi Gate',
    icon: 'M4 21V5a1 1 0 0 1 1-1h6v17M4 21h16M14 21V9h5a1 1 0 0 1 1 1v11M8 8h.01M8 12h.01',
    feature: 'kartu_gate',
  },
  {
    to: { name: 'users.index' },
    label: 'Data Warga',
    icon: 'M17 20h5v-2a4 4 0 0 0-3-3.87M9 20H4v-2a4 4 0 0 1 3-3.87m6-1.13a4 4 0 1 0-4-4 4 4 0 0 0 4 4zm6 0a4 4 0 1 0-3-6.65',
    feature: 'users',
  },
  {
    to: { name: 'iuran.index' },
    label: 'Iuran Perumahan',
    icon: 'M9 14l-4-4 4-4m-4 4h15M5 19h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2z',
    feature: null,
  },
  {
    to: { name: 'reports.index' },
    label: 'Laporan',
    icon: 'M9 17v-6m3 6V7m3 10v-3M6 3h9l5 5v11a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z',
    feature: 'reports',
  },
  {
    to: { name: 'settings.access-control' },
    label: 'Pengaturan Hak Akses',
    icon: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm7.4-3a7.4 7.4 0 0 0-.1-1.2l2-1.6-2-3.4-2.4 1a7.3 7.3 0 0 0-2-1.2l-.4-2.5H9.5l-.4 2.5a7.3 7.3 0 0 0-2 1.2l-2.4-1-2 3.4 2 1.6a7.4 7.4 0 0 0 0 2.4l-2 1.6 2 3.4 2.4-1a7.3 7.3 0 0 0 2 1.2l.4 2.5h4.9l.4-2.5a7.3 7.3 0 0 0 2-1.2l2.4 1 2-3.4-2-1.6c.07-.4.1-.8.1-1.2z',
    feature: 'access_control',
    featureLevel: 'manage',
  },
  {
    to: { name: 'settings.cameras' },
    label: 'Pengaturan Kamera',
    icon: 'M23 7l-7 5 7 5V7zM1 5h13a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H1a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z',
    feature: 'cameras',
    featureLevel: 'manage',
  },
  {
    to: { name: 'logs.index' },
    label: 'Log Error',
    icon: 'M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z',
    feature: 'log_error',
  },
  {
    to: { name: 'mqtt.test' },
    label: 'MQTT Test',
    icon: 'M12 2L2 7v10c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-10-5zm0 18c-3.86-.96-7-5.04-7-9V8.3l7-3.11 7 3.11V11c0 3.96-3.14 8.04-7 9z M9 12l2 2 4-4',
    feature: 'mqtty',
  },
]

const visibleMenu = computed(() =>
  menu.filter((item) => {
    if (item.superAdmin) return auth.isSuperAdmin
    if (item.adminOnly) return auth.isAdmin
    if (!item.feature) return true
    if (item.featureLevel === 'manage') return auth.canManage(item.feature)
    return auth.hasFeature(item.feature)
  }),
)
</script>

<template>
  <aside class="sidebar" :class="{ 'is-open': open, 'is-collapsed': collapsed }">
    <div class="sidebar-brand">

      <span class="brand-logo">GH</span>
      <div class="brand-text">
        <strong>GH PIK2</strong>
        <small>Admin Panel</small>
      </div>
    </div>

    <nav class="sidebar-nav">
      <p class="sidebar-heading">Menu Utama</p>
      <RouterLink
        v-for="item in visibleMenu"
        :key="item.label"
        :to="item.to"
        class="sidebar-link"
        :title="collapsed ? item.label : ''"
        @click="emit('navigate')"
      >
        <svg
          class="sidebar-icon"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="2"
          stroke-linecap="round"
          stroke-linejoin="round"
        >
          <path :d="item.icon" />
        </svg>
        <span class="link-label">{{ item.label }}</span>
      </RouterLink>
    </nav>

    <div class="sidebar-footer">
      <small>v1.0.0 </small>
    </div>
  </aside>
</template>

<style scoped>
.sidebar {
  position: fixed;
  top: 0;
  left: 0;
  width: var(--sidebar-width);
  height: 100vh;
  background: var(--color-sidebar);
  color: #cbd5e1;
  display: flex;
  flex-direction: column;
  z-index: 50;
  transition: transform 0.25s ease, width 0.3s ease;
}
.sidebar.is-collapsed {
  width: var(--sidebar-collapsed-width, 80px);
}
.sidebar.is-collapsed .sidebar-brand {
  flex-direction: column;
  padding: 12px 8px;
}
.sidebar.is-collapsed .brand-logo {
  width: 38px;
  height: 38px;
}
.sidebar.is-collapsed .brand-text {
  display: none;
}
.sidebar.is-collapsed .sidebar-nav {
  padding: 12px 4px;
}
.sidebar.is-collapsed .sidebar-link {
  justify-content: center;
  padding: 12px 4px;
  gap: 0;
}
.sidebar.is-collapsed .link-label {
  display: none;
}
.sidebar.is-collapsed .sidebar-heading {
  display: none;
}
.sidebar.is-collapsed .sidebar-footer {
  padding: 12px 8px;
}
.sidebar-brand {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 18px 20px;
  height: var(--header-height);
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
  transition: flex-direction 0.3s ease, padding 0.3s ease;
}
.burger-button {
  display: flex;
  align-items: center;
  justify-content: center;
  background: none;
  border: none;
  cursor: pointer;
  padding: 4px;
  color: #cbd5e1;
  transition: color 0.15s ease;
}
.burger-button:hover {
  color: #fff;
}
.burger-icon {
  width: 24px;
  height: 24px;
}
.brand-logo {
  display: grid;
  place-items: center;
  width: 38px;
  height: 38px;
  background: var(--color-primary);
  color: #fff;
  border-radius: 10px;
  font-weight: 700;
  font-size: 15px;
  transition: all 0.3s ease;
}
.brand-text {
  display: flex;
  flex-direction: column;
  line-height: 1.2;
  transition: display 0.3s ease;
}
.brand-text strong {
  color: #fff;
  font-size: 15px;
}
.brand-text small {
  color: #94a3b8;
  font-size: 11px;
}
.sidebar-nav {
  flex: 1;
  padding: 16px 12px;
  overflow-y: auto;
  transition: padding 0.3s ease;
}
.sidebar-heading {
  margin: 8px 12px;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: #64748b;
  transition: display 0.3s ease;
}
.sidebar-link {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  margin-bottom: 4px;
  border-radius: var(--radius-sm);
  font-size: 14px;
  color: #cbd5e1;
  transition: background 0.15s ease, color 0.15s ease, justify-content 0.3s ease;
}
.sidebar-link:hover {
  background: var(--color-sidebar-hover);
  color: #fff;
}
.sidebar-link.router-link-active {
  background: var(--color-sidebar-active);
  color: #fff;
}
.sidebar-icon {
  width: 20px;
  height: 20px;
  flex-shrink: 0;
}
.link-label {
  transition: display 0.3s ease;
}
.sidebar-footer {
  padding: 16px 20px;
  border-top: 1px solid rgba(255, 255, 255, 0.08);
  color: #64748b;
  font-size: 11px;
  transition: padding 0.3s ease;
}

@media (max-width: 1024px) {
  .sidebar {
    transform: translateX(-100%);
  }
  .sidebar.is-open {
    transform: translateX(0);
  }
  .sidebar.is-collapsed {
    width: var(--sidebar-width);
  }
  .sidebar.is-collapsed .sidebar-brand {
    flex-direction: row;
    padding: 18px 20px;
  }
  .sidebar.is-collapsed .brand-text {
    display: flex;
  }
  .sidebar.is-collapsed .sidebar-nav {
    padding: 16px 12px;
  }
  .sidebar.is-collapsed .sidebar-link {
    justify-content: flex-start;
    padding: 10px 12px;
    gap: 12px;
  }
  .sidebar.is-collapsed .link-label {
    display: inline;
  }
  .sidebar.is-collapsed .sidebar-heading {
    display: block;
  }
  .sidebar.is-collapsed .sidebar-footer {
    padding: 16px 20px;
  }
}
</style>
