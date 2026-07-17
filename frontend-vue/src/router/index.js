import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const routes = [
  {
    path: '/',
    component: () => import('@/components/layout/DefaultLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      { path: '', redirect: '/dashboard' },
      {
        path: 'dashboard',
        name: 'dashboard',
        component: () => import('@/views/dashboard/Dashboard.vue'),
        meta: { title: 'Dashboard' },
      },
      // Profil pengguna yang sedang login
      {
        path: 'profile',
        name: 'profile',
        component: () => import('@/views/users/Profile.vue'),
        meta: { title: 'Profil Saya' },
      },
      // Users
      {
        path: 'users',
        name: 'users.index',
        component: () => import('@/views/users/UserList.vue'),
        meta: { title: 'Data Warga', feature: 'users' },
      },
      {
        path: 'users/create',
        name: 'users.create',
        component: () => import('@/views/users/UserCreate.vue'),
        meta: { title: 'Tambah Data Warga', feature: 'users', featureLevel: 'manage' },
      },
      {
        path: 'users/:id/edit',
        name: 'users.edit',
        component: () => import('@/views/users/UserEdit.vue'),
        meta: { title: 'Edit Data Warga', feature: 'users', featureLevel: 'manage' },
        props: true,
      },
      // Kendaraan
      {
        path: 'kendaraan',
        name: 'kendaraan.index',
        component: () => import('@/views/kendaraan/KendaraanList.vue'),
        meta: { title: 'Kendaraan', feature: 'kendaraan' },
      },
      {
        path: 'kendaraan/create',
        name: 'kendaraan.create',
        component: () => import('@/views/kendaraan/KendaraanCreate.vue'),
        meta: { title: 'Tambah Kendaraan', feature: 'kendaraan', featureLevel: 'manage' },
      },
      {
        path: 'kendaraan/:id/edit',
        name: 'kendaraan.edit',
        component: () => import('@/views/kendaraan/KendaraanEdit.vue'),
        meta: { title: 'Edit Kendaraan', feature: 'kendaraan', featureLevel: 'manage' },
        props: true,
      },
      // Kartu Akses
      {
        path: 'kartu',
        name: 'kartu.index',
        component: () => import('@/views/kartu/KartuList.vue'),
        meta: { title: 'Kartu Akses', feature: 'kartu' },
      },
      {
        path: 'kartu/gate',
        name: 'kartu.gate',
        component: () => import('@/views/kartu/KartuGate.vue'),
        meta: { title: 'Simulasi Gate', feature: 'kartu_gate' },
      },
      {
        path: 'kartu/create',
        name: 'kartu.create',
        component: () => import('@/views/kartu/KartuCreate.vue'),
        meta: { title: 'Tambah Kartu Akses', feature: 'kartu', featureLevel: 'manage' },
      },
      {
        path: 'kartu/:id/edit',
        name: 'kartu.edit',
        component: () => import('@/views/kartu/KartuEdit.vue'),
        meta: { title: 'Edit Kartu Akses', feature: 'kartu', featureLevel: 'manage' },
        props: true,
      },
      // Laporan (rekap & detail transaksi: harian / bulanan / tahunan, PDF)
      {
        path: 'laporan',
        name: 'reports.index',
        component: () => import('@/views/reports/ReportList.vue'),
        meta: { title: 'Laporan', feature: 'reports' },
      },
      // Pengaturan hak akses (RBAC) — hanya untuk peran dengan akses "manage".
      {
        path: 'pengaturan/hak-akses',
        name: 'settings.access-control',
        component: () => import('@/views/settings/AccessControl.vue'),
        meta: { title: 'Pengaturan Hak Akses', feature: 'access_control', featureLevel: 'manage' },
      },
      // Pengaturan kamera live CCTV (URL RTSP dinamis).
      {
        path: 'pengaturan/kamera',
        name: 'settings.cameras',
        component: () => import('@/views/settings/CameraSettings.vue'),
        meta: { title: 'Pengaturan Kamera', feature: 'cameras', featureLevel: 'manage' },
      },
      // Log error / bug aplikasi — hanya untuk Super Admin.
      {
        path: 'log-error',
        name: 'logs.index',
        component: () => import('@/views/logs/LogList.vue'),
        meta: { title: 'Log Error', superAdmin: true },
      },
      // MQTT Test (untuk development/testing)
      {
        path: 'mqtt-test',
        name: 'mqtt.test',
        component: () => import('@/views/MqttTest.vue'),
        meta: { title: 'MQTT Test' },
      },
      // Image Upload API Test (untuk development/testing)
      {
        path: 'image-test',
        name: 'image.test',
        component: () => import('@/views/ImageTest.vue'),
        meta: { title: 'Image Upload Test' },
      },
      // Image Upload API Test - Debug Version
      {
        path: 'image-test-debug',
        name: 'image.test.debug',
        component: () => import('@/views/ImageTestDebug.vue'),
        meta: { title: 'Image Test Debug' },
      },
    ],
  },
  // Auth (guest only)
  {
    path: '/login',
    name: 'login',
    component: () => import('@/views/auth/Login.vue'),
    meta: { guestOnly: true, title: 'Masuk' },
  },
  {
    path: '/register',
    name: 'register',
    component: () => import('@/views/auth/Register.vue'),
    meta: { guestOnly: true, title: 'Daftar' },
  },
  {
    path: '/forgot-password',
    name: 'forgot-password',
    component: () => import('@/views/auth/ForgotPassword.vue'),
    meta: { guestOnly: true, title: 'Lupa Password' },
  },
  {
    path: '/reset-password',
    name: 'reset-password',
    component: () => import('@/views/auth/ResetPassword.vue'),
    meta: { guestOnly: true, title: 'Reset Password' },
  },
  // 404
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('@/views/NotFound.vue'),
    meta: { title: 'Halaman Tidak Ditemukan' },
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior() {
    return { top: 0 }
  },
})

const appName = import.meta.env.VITE_APP_NAME || 'GH PIK2'

router.beforeEach((to) => {
  const auth = useAuthStore()

  document.title = to.meta.title ? `${to.meta.title} · ${appName}` : appName

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  if (to.meta.guestOnly && auth.isAuthenticated) {
    return { name: 'dashboard' }
  }

  // Super-admin-only routes (e.g. the error log). Non-super-admins are sent
  // back to the dashboard.
  if (to.meta.superAdmin && auth.isAuthenticated && !auth.isSuperAdmin) {
    return { name: 'dashboard' }
  }

  // Feature-based access control (RBAC). Routes may declare:
  //   meta.feature       -> the feature key required
  //   meta.featureLevel  -> 'view' (default) or 'manage'
  // Super admin bypasses every check. Users without sufficient access are
  // redirected back to the dashboard.
  if (to.meta.feature && auth.isAuthenticated) {
    const needsManage = to.meta.featureLevel === 'manage'
    const allowed = needsManage
      ? auth.canManage(to.meta.feature)
      : auth.hasFeature(to.meta.feature)

    if (!allowed) {
      return { name: 'dashboard' }
    }
  }

  return true
})

export default router
