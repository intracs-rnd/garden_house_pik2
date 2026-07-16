<script setup>
import { RouterLink } from 'vue-router'
</script>

<template>
  <div class="notfound">
    <div class="notfound-content">
      <h1>404</h1>
      <h2>Halaman Tidak Ditemukan</h2>
      <p>Maaf, halaman yang Anda cari tidak tersedia atau telah dipindahkan.</p>
      <RouterLink to="/" class="btn btn-primary">Kembali ke Dashboard</RouterLink>
    </div>
  </div>
</template>

<style scoped>
.notfound {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  background: var(--color-bg);
}
.notfound-content {
  text-align: center;
}
.notfound-content h1 {
  font-size: 96px;
  font-weight: 800;
  color: var(--color-primary);
  line-height: 1;
}
.notfound-content h2 {
  font-size: 24px;
  margin-top: 8px;
}
.notfound-content p {
  color: var(--color-text-muted);
  margin: 12px 0 24px;
}
</style>
