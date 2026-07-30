const fs = require('fs');

let kartu_content = fs.readFileSync("frontend-vue/src/views/reports/ReportKartu.vue", "utf-8");

// Refactor ReportKartu.vue (Remove gate control logic)
kartu_content = kartu_content.replace('title="Laporan Transaksi"', 'title="Laporan Transaksi Kartu"');
kartu_content = kartu_content.replace('subtitle="Rekap & detail transaksi akses kartu (harian, bulanan, tahunan)"', 'subtitle="Rekap & detail transaksi akses kartu"');

// Remove gate control script variables
kartu_content = kartu_content.replace(/\/\/ Gate control report[\s\S]*?const gateControlError = ref\(''\)/g, "");
kartu_content = kartu_content.replace(/const gateColumns = \[[\s\S]*?\]\n/g, "");
kartu_content = kartu_content.replace(/\/\/ Client-side pagination for the gate control[\s\S]*?const gateControlSummary = computed\(\(\) => gateControlData\.value\?\.summary \|\| null\)/g, "");
kartu_content = kartu_content.replace(/\/\/ Gate control detail modal[\s\S]*?const showGateDetailModal = ref\(false\)/g, "");
kartu_content = kartu_content.replace(/\/\/ Gate detail modal image state[\s\S]*?gateDetailImageError\.value = 'Gagal memuat gambar CCTV\.'\n  }\n}/g, "");
kartu_content = kartu_content.replace(/async function loadGateControl\(\)[\s\S]*?gateControlLoading\.value = false\n  }\n}/g, "");
kartu_content = kartu_content.replace(/function switchDetailType\(type\) {\n  activeDetailType\.value = type\n  if \(type === 'gate'\) loadGateControl\(\)\n}/g, "");
kartu_content = kartu_content.replace(/\/\/ When viewing the gate control sub-tab, show gate-specific stats\n  if \(activeTab\.value === 'detail' && activeDetailType\.value === 'gate' && gateControlSummary\.value\) {[\s\S]*?\n  }\n/g, "");
kartu_content = kartu_content.replace(/\/\/ Reset gate control cache so it reloads with new filters\n  gateControlData\.value = null\n  gateControlError\.value = ''\n/g, "");
kartu_content = kartu_content.replace(/\/\/ If the gate sub-tab is already open, eagerly reload its data too\n    if \(activeDetailType\.value === 'gate'\) {\n      loadGateControl\(\)\n    }\n/g, "");
kartu_content = kartu_content.replace(/if \(kind === 'gate-control'\) {[\s\S]*?return\n    }\n/g, "");
kartu_content = kartu_content.replace(/\/\/ Reset gate control cache when filters change\nwatch\([\s\S]*?\(\) => {\n    gateControlData\.value = null\n    gateControlError\.value = ''\n    \/\/ If gate sub-tab is active, reload with new filters\n    if \(activeDetailType\.value === 'gate'\) {\n      loadGateControl\(\)\n    }\n  },\n\)/g, "");
kartu_content = kartu_content.replace(/watch\(showGateDetailModal, \(open\) => {\n  if \(\!open\) {\n    cleanupGateDetailImages\(\)\n    gateDetailImageError\.value = ''\n  }\n}\)/g, "");
kartu_content = kartu_content.replace('cleanupGateDetailImages()', '');

// HTML replacements for Kartu
kartu_content = kartu_content.replace('v-if="activeTab !== \'detail\' || activeDetailType === \'tap\'"', 'v-if="activeTab !== \'detail\'"');
let sub_tabs_match = kartu_content.match(/<!-- Detail sub-tabs -->[\s\S]*?<\/div>\s*<!-- Tap Kartu sub-tab -->/);
if (sub_tabs_match) {
    kartu_content = kartu_content.replace(sub_tabs_match[0], "");
}
kartu_content = kartu_content.replace('<template v-if="activeDetailType === \'tap\'">', '<template v-if="true">');
kartu_content = kartu_content.replace(/<!-- Kontrol Gate sub-tab -->[\s\S]*?<\/template>\n      <\/template>/, "</template>\n      </template>");
kartu_content = kartu_content.replace(/<!-- Gate control detail popup -->[\s\S]*?<\/Modal>/, "");
kartu_content = kartu_content.replace(/\/\* Sub-tabs \(inside the Detail tab\) \*\/[\s\S]*/, "</style>\n");
kartu_content = kartu_content.replace("const activeDetailType = ref('tap') // 'tap' | 'gate'", "");

fs.writeFileSync("frontend-vue/src/views/reports/ReportKartu.vue", kartu_content, "utf-8");

// Refactor ReportList.vue (Make it exclusively for Gate Control)
let list_content = fs.readFileSync("frontend-vue/src/views/reports/ReportList.vue", "utf-8");

list_content = list_content.replace('title="Laporan Transaksi"', 'title="Laporan Kontrol Gate"');
list_content = list_content.replace('subtitle="Rekap & detail transaksi akses kartu (harian, bulanan, tahunan)"', 'subtitle="Laporan aktivitas kontrol gate (harian, bulanan, tahunan)"');

// Script cleanup for Gate Control
list_content = list_content.replace("const activeTab = ref('rekap') // 'rekap' | 'detail'", "");
list_content = list_content.replace("const activeDetailType = ref('tap') // 'tap' | 'gate'", "");
list_content = list_content.replace(/const recap = ref\(null\)\nconst detail = ref\(null\)/, "");
list_content = list_content.replace(/const detailColumns = \[[\s\S]*?\]\n/, "");
list_content = list_content.replace(/const selectedRow = ref\(null\)[\s\S]*?imageError\.value = 'Sebagian gambar gagal dimuat\.'\n  }\n}/g, "");
list_content = list_content.replace(/\/\/ Client-side pagination for the detail tab\.[\s\S]*?detailPage\.value = 1\n}/g, "");
list_content = list_content.replace("function switchDetailType(type) {\n  activeDetailType.value = type\n  if (type === 'gate') loadGateControl()\n}", "");
list_content = list_content.replace(/async function openDetail\(row\) {[\s\S]*?}\n/g, "");
list_content = list_content.replace(/const timelineHead = computed[\s\S]*?}\) \|\| 'Periode'\)\)\n/g, "");
list_content = list_content.replace(/const summary = computed[\s\S]*?detail\.value\?\.summary,\n\)\n/g, "");

list_content = list_content.replace(/const summaryCards = computed\(\(\) => \{[\s\S]*?\}/, `const summaryCards = computed(() => {
  if (gateControlSummary.value) {
    const s = gateControlSummary.value
    return [
      { label: 'Total Event', value: s.total, color: '#4f46e5' },
      { label: 'Buka Gate', value: s.open, color: '#16a34a' },
    ]
  }
  return []
}`);

list_content = list_content.replace(/async function generate\(\) {[\s\S]*?finally {\n    loading\.value = false\n  }\n}/, `async function generate() {
  loadGateControl()
}`);

list_content = list_content.replace(/async function download[\s\S]*?if \(format === 'excel'\) {[\s\S]*?return\n    }\n/, `async function download(kind, { format = 'pdf', preview = false } = {}) {
  downloading.value = preview ? \`\${kind}-preview\` : \`\${kind}-\${format}\`
  try {
    const stamp = new Date().toISOString().slice(0, 10)
    const params = { ...buildParams(), download: preview ? undefined : 1 }
    if (format === 'excel') {
      const blob = await reportApi.gateControlExcel(buildParams())
      downloadBlob(blob, \`laporan-kontrol-gate-\${filters.value.period}-\${stamp}.xlsx\`)
      toast.success('Excel berhasil diunduh.')
    } else if (preview) {
      const blob = await reportApi.gateControlPdf(params)
      openBlob(blob)
    } else {
      const blob = await reportApi.gateControlPdf(params)
      downloadBlob(blob, \`laporan-kontrol-gate-\${filters.value.period}-\${stamp}.pdf\`)
      toast.success('PDF berhasil diunduh.')
    }
`);

list_content = list_content.replace(/watch\(showDetailModal, \(open\) => {[\s\S]*?}\)\n/g, "");
list_content = list_content.replace("cleanupSelectedImages()", "");

// HTML replacements for Gate Control
list_content = list_content.replace(/<Loader v-if="loading && !recap" text="Memuat laporan\.\.\." \/>[\s\S]*?<template v-else-if="recap">/, '<template v-if="true">');

list_content = list_content.replace(/<div class="report-actions">[\s\S]*?<\/div>/, `<div class="report-actions">
            <Button variant="secondary" size="sm" :loading="downloading === 'gate-control-pdf'" @click="download('gate-control', { format: 'pdf' })">
              ⬇ PDF
            </Button>
            <Button variant="secondary" size="sm" :loading="downloading === 'gate-control-excel'" @click="download('gate-control', { format: 'excel' })">
              ⬇ Excel
            </Button>
            <Button variant="ghost" size="sm" :loading="downloading === 'gate-control-preview'" @click="download('gate-control', { preview: true })">
              👁 Pratinjau PDF
            </Button>
          </div>`);

list_content = list_content.replace(/<!-- Tabs -->[\s\S]*?<\/div>/, "");
list_content = list_content.replace(/<!-- Rekap tab -->[\s\S]*?<!-- Detail tab -->/, "");
list_content = list_content.replace(/<template v-else>[\s\S]*?<!-- Kontrol Gate sub-tab -->/, "");
list_content = list_content.replace("<template v-else>", "");
list_content = list_content.replace(/<!-- Gate control download toolbar -->[\s\S]*?<\/div>/, "");
list_content = list_content.replace(/<!-- Detail popup -->[\s\S]*?<\/Modal>/, "");

list_content = list_content.replace(/<\/template>\n      <\/template>\n    <\/template>/, "</template>");
list_content = list_content.replace(/<\/template>\n    <\/template>/, "</template>");

list_content = list_content.replace(/gateControlLoading\.value/g, "loading.value");
list_content = list_content.replace(/gateControlLoading/g, "loading");

list_content = list_content.replace(/<!-- Detail sub-tabs -->[\s\S]*?<\/div>/, "");
list_content = list_content.replace(/<template v-if="activeDetailType === 'tap'">[\s\S]*?<\/template>/, "");

fs.writeFileSync("frontend-vue/src/views/reports/ReportList.vue", list_content, "utf-8");
