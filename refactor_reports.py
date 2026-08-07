import re
import os

with open("frontend-vue/src/views/reports/ReportKartu.vue", "r", encoding="utf-8") as f:
    kartu_content = f.read()

# Refactor ReportKartu.vue (Remove gate control logic)
kartu_content = kartu_content.replace('title="Laporan Transaksi"', 'title="Laporan Transaksi Kartu"')
kartu_content = kartu_content.replace('subtitle="Rekap & detail transaksi akses kartu (harian, bulanan, tahunan)"', 'subtitle="Rekap & detail transaksi akses kartu"')

# Remove gate control script variables
kartu_content = re.sub(r"// Gate control report.*?const gateControlError = ref\(''\)", "", kartu_content, flags=re.DOTALL)
kartu_content = re.sub(r"const gateColumns = \[.*?\]\n", "", kartu_content, flags=re.DOTALL)
kartu_content = re.sub(r"// Client-side pagination for the gate control.*?const gateControlSummary = computed\(\(\) => gateControlData\.value\?\.summary \|\| null\)", "", kartu_content, flags=re.DOTALL)
kartu_content = re.sub(r"// Gate control detail modal.*?const showGateDetailModal = ref\(false\)", "", kartu_content, flags=re.DOTALL)
kartu_content = re.sub(r"// Gate detail modal image state.*?gateDetailImageError\.value = 'Gagal memuat gambar CCTV\.'\n  }\n}", "", kartu_content, flags=re.DOTALL)
kartu_content = re.sub(r"async function loadGateControl\(\).*?gateControlLoading\.value = false\n  }\n}", "", kartu_content, flags=re.DOTALL)
kartu_content = re.sub(r"function switchDetailType\(type\) {\n  activeDetailType\.value = type\n  if \(type === 'gate'\) loadGateControl\(\)\n}", "", kartu_content, flags=re.DOTALL)
kartu_content = re.sub(r"// When viewing the gate control sub-tab, show gate-specific stats\n  if \(activeTab\.value === 'detail' && activeDetailType\.value === 'gate' && gateControlSummary\.value\) {.*?\n  }\n", "", kartu_content, flags=re.DOTALL)
kartu_content = re.sub(r"// Reset gate control cache so it reloads with new filters\n  gateControlData\.value = null\n  gateControlError\.value = ''\n", "", kartu_content, flags=re.DOTALL)
kartu_content = re.sub(r"// If the gate sub-tab is already open, eagerly reload its data too\n    if \(activeDetailType\.value === 'gate'\) {\n      loadGateControl\(\)\n    }\n", "", kartu_content, flags=re.DOTALL)
kartu_content = re.sub(r"if \(kind === 'gate-control'\) {.*?return\n    }\n", "", kartu_content, flags=re.DOTALL)
kartu_content = re.sub(r"// Reset gate control cache when filters change\nwatch\(\n  \(\) => \({\n    period: filters\.value\.period,\n    day: filters\.value\.day,\n    month: filters\.value\.month,\n    year: filters\.value\.year,\n    time_from: filters\.value\.time_from,\n    time_to: filters\.value\.time_to,\n    no_plat: filters\.value\.no_plat,\n    direction: filters\.value\.direction,\n    access_granted: filters\.value\.access_granted,\n    gate: filters\.value\.gate,\n  }\),\n  \(\) => {\n    gateControlData\.value = null\n    gateControlError\.value = ''\n    // If gate sub-tab is active, reload with new filters\n    if \(activeDetailType\.value === 'gate'\) {\n      loadGateControl\(\)\n    }\n  },\n\)", "", kartu_content, flags=re.DOTALL)
kartu_content = re.sub(r"watch\(showGateDetailModal, \(open\) => {\n  if \(\!open\) {\n    cleanupGateDetailImages\(\)\n    gateDetailImageError\.value = ''\n  }\n}\)", "", kartu_content, flags=re.DOTALL)
kartu_content = kartu_content.replace('cleanupGateDetailImages()', '')

# HTML replacements for Kartu
kartu_content = kartu_content.replace('v-if="activeTab !== \'detail\' || activeDetailType === \'tap\'"', 'v-if="activeTab !== \'detail\'"')
# Remove sub-tabs
sub_tabs_match = re.search(r"<!-- Detail sub-tabs -->.*?</div>\s*<!-- Tap Kartu sub-tab -->", kartu_content, flags=re.DOTALL)
if sub_tabs_match:
    kartu_content = kartu_content.replace(sub_tabs_match.group(0), "")
kartu_content = kartu_content.replace('<template v-if="activeDetailType === \'tap\'">', '<template v-if="true">')
kartu_content = re.sub(r"<!-- Kontrol Gate sub-tab -->.*?</template>\n      </template>", "</template>\n      </template>", kartu_content, flags=re.DOTALL)
kartu_content = re.sub(r"<!-- Gate control detail popup -->.*?</Modal>", "", kartu_content, flags=re.DOTALL)
kartu_content = re.sub(r"/\* Sub-tabs \(inside the Detail tab\) \*/.*", "", kartu_content, flags=re.DOTALL)
kartu_content = kartu_content.replace("const activeDetailType = ref('tap') // 'tap' | 'gate'", "")

with open("frontend-vue/src/views/reports/ReportKartu.vue", "w", encoding="utf-8") as f:
    f.write(kartu_content)

# Refactor ReportList.vue (Make it exclusively for Gate Control)
with open("frontend-vue/src/views/reports/ReportList.vue", "r", encoding="utf-8") as f:
    list_content = f.read()

list_content = list_content.replace('title="Laporan Transaksi"', 'title="Laporan Kunjuangan Visitor"')
list_content = list_content.replace('subtitle="Rekap & detail transaksi akses kartu (harian, bulanan, tahunan)"', 'subtitle="Laporan aktivitas kontrol gate (harian, bulanan, tahunan)"')

# Script cleanup for Gate Control
list_content = list_content.replace("const activeTab = ref('rekap') // 'rekap' | 'detail'", "")
list_content = list_content.replace("const activeDetailType = ref('tap') // 'tap' | 'gate'", "")
list_content = re.sub(r"const recap = ref\(null\)\nconst detail = ref\(null\)", "", list_content)
list_content = re.sub(r"const detailColumns = \[.*?\]\n", "", list_content, flags=re.DOTALL)
list_content = re.sub(r"const selectedRow = ref\(null\).*?imageError\.value = 'Sebagian gambar gagal dimuat\.'\n  }\n}", "", list_content, flags=re.DOTALL)
list_content = re.sub(r"// Client-side pagination for the detail tab\..*?detailPage\.value = 1\n}", "", list_content, flags=re.DOTALL)
list_content = list_content.replace("function switchDetailType(type) {\n  activeDetailType.value = type\n  if (type === 'gate') loadGateControl()\n}", "")
list_content = re.sub(r"async function openDetail\(row\) {.*?}\n", "", list_content, flags=re.DOTALL)
list_content = re.sub(r"const timelineHead = computed.*?}\) \|\| 'Periode'\)\)\n", "", list_content, flags=re.DOTALL)
list_content = re.sub(r"const summary = computed.*?detail\.value\?\.summary,\n\)\n", "", list_content, flags=re.DOTALL)

list_content = re.sub(r"const summaryCards = computed\(\(\) => \{.*?\}", """const summaryCards = computed(() => {
  if (gateControlSummary.value) {
    const s = gateControlSummary.value
    return [
      { label: 'Total Event', value: s.total, color: '#4f46e5' },
      { label: 'Buka Gate', value: s.open, color: '#16a34a' },
    ]
  }
  return []
}""", list_content, flags=re.DOTALL)

list_content = re.sub(r"async function generate\(\) {.*?finally {\n    loading\.value = false\n  }\n}", """async function generate() {
  loadGateControl()
}""", list_content, flags=re.DOTALL)

list_content = re.sub(r"async function download.*?if \(format === 'excel'\) {.*?return\n    }\n", """async function download(kind, { format = 'pdf', preview = false } = {}) {
  downloading.value = preview ? `${kind}-preview` : `${kind}-${format}`
  try {
    const stamp = new Date().toISOString().slice(0, 10)
    const params = { ...buildParams(), download: preview ? undefined : 1 }
    if (format === 'excel') {
      const blob = await reportApi.gateControlExcel(buildParams())
      downloadBlob(blob, `laporan-kontrol-gate-${filters.value.period}-${stamp}.xlsx`)
      toast.success('Excel berhasil diunduh.')
    } else if (preview) {
      const blob = await reportApi.gateControlPdf(params)
      openBlob(blob)
    } else {
      const blob = await reportApi.gateControlPdf(params)
      downloadBlob(blob, `laporan-kontrol-gate-${filters.value.period}-${stamp}.pdf`)
      toast.success('PDF berhasil diunduh.')
    }
""", list_content, flags=re.DOTALL)

list_content = re.sub(r"watch\(showDetailModal, \(open\) => {.*?}\)\n", "", list_content, flags=re.DOTALL)
list_content = list_content.replace("cleanupSelectedImages()", "")

# HTML replacements for Gate Control
list_content = re.sub(r"<Loader v-if=\"loading && !recap\" text=\"Memuat laporan\.\.\.\" />.*?<template v-else-if=\"recap\">", "<template v-if=\"true\">", list_content, flags=re.DOTALL)

list_content = re.sub(r"<div class=\"report-actions\">.*?</div>", """<div class="report-actions">
            <Button variant="secondary" size="sm" :loading="downloading === 'gate-control-pdf'" @click="download('gate-control', { format: 'pdf' })">
              ⬇ PDF
            </Button>
            <Button variant="secondary" size="sm" :loading="downloading === 'gate-control-excel'" @click="download('gate-control', { format: 'excel' })">
              ⬇ Excel
            </Button>
            <Button variant="ghost" size="sm" :loading="downloading === 'gate-control-preview'" @click="download('gate-control', { preview: true })">
              👁 Pratinjau PDF
            </Button>
          </div>""", list_content, flags=re.DOTALL)

list_content = re.sub(r"<!-- Tabs -->.*?</div>", "", list_content, flags=re.DOTALL)
list_content = re.sub(r"<!-- Rekap tab -->.*?<!-- Detail tab -->", "", list_content, flags=re.DOTALL)
list_content = re.sub(r"<template v-else>.*?<!-- Kontrol Gate sub-tab -->", "", list_content, flags=re.DOTALL)
list_content = list_content.replace("<template v-else>", "")
list_content = re.sub(r"<!-- Gate control download toolbar -->.*?</div>", "", list_content, flags=re.DOTALL)
list_content = re.sub(r"<!-- Detail popup -->.*?</Modal>", "", list_content, flags=re.DOTALL)

list_content = re.sub(r"</template>\n      </template>\n    </template>", "</template>", list_content)
list_content = list_content.replace("</template>\n    </template>", "</template>")

list_content = list_content.replace("gateControlLoading.value", "loading.value")
list_content = list_content.replace("gateControlLoading", "loading")

list_content = re.sub(r"<!-- Detail sub-tabs -->.*?</div>", "", list_content, flags=re.DOTALL)
list_content = re.sub(r"<template v-if=\"activeDetailType === 'tap'\">.*?</template>", "", list_content, flags=re.DOTALL)


with open("frontend-vue/src/views/reports/ReportList.vue", "w", encoding="utf-8") as f:
    f.write(list_content)
