<script setup>
const year = new Date().getFullYear()
</script>

<template>
  <footer class="footer">
    <span>© {{ year }} GH PIK2. </span>
    <span class="footer-muted"></span>
  </footer>
</template>

<style scoped>
.footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 8px;
  padding: 16px 24px;
  border-top: 1px solid var(--color-border);
  font-size: 13px;
  color: var(--color-text-muted);
  background: var(--color-surface);
}
.footer-muted {
  color: #94a3b8;
}
</style>
