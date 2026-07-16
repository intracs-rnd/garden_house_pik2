<script setup>
import { ref } from 'vue'
import { RouterView } from 'vue-router'
import Sidebar from './Sidebar.vue'
import Navbar from './Navbar.vue'
import Footer from './Footer.vue'

const sidebarOpen = ref(false)
const sidebarCollapsed = ref(false)

function toggleSidebar() {
  sidebarOpen.value = !sidebarOpen.value
}

function toggleSidebarCollapse() {
  sidebarCollapsed.value = !sidebarCollapsed.value
}

function closeSidebar() {
  sidebarOpen.value = false
}
</script>

<template>
  <div class="layout">
    <Sidebar :open="sidebarOpen" :collapsed="sidebarCollapsed" @navigate="closeSidebar" @toggle="toggleSidebar" />

    <!-- Backdrop for mobile sidebar -->
    <div v-if="sidebarOpen" class="layout-backdrop" @click="closeSidebar"></div>

    <div class="layout-main" :class="{ 'is-collapsed': sidebarCollapsed }">
      <Navbar @toggle-sidebar="toggleSidebar" @toggle-sidebar-collapse="toggleSidebarCollapse" />
      <main class="layout-content">
        <RouterView />
      </main>
      <Footer />
    </div>
  </div>
</template>

<style scoped>
.layout {
  min-height: 100vh;
}
.layout-main {
  margin-left: var(--sidebar-width);
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  transition: margin-left 0.3s ease;
}
.layout-main.is-collapsed {
  margin-left: var(--sidebar-collapsed-width, 80px);
}
.layout-content {
  flex: 1;
  padding: 24px;
  max-width: 1200px;
  width: 100%;
  margin: 0 auto;
}
.layout-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.5);
  z-index: 40;
  display: none;
}

@media (max-width: 1024px) {
  .layout-main {
    margin-left: 0;
  }
  .layout-main.is-collapsed {
    margin-left: 0;
  }
  .layout-backdrop {
    display: block;
  }
}
</style>
