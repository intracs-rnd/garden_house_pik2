/**
 * Helpers for handling binary file responses (e.g. PDF downloads).
 */

/** Trigger a browser download for a Blob under the given filename. */
export function downloadBlob(blob, filename = 'download') {
  const url = window.URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  window.URL.revokeObjectURL(url)
}

/** Open a Blob in a new browser tab (inline preview). */
export function openBlob(blob) {
  const url = window.URL.createObjectURL(blob)
  window.open(url, '_blank')
  // Give the new tab time to load before releasing the object URL.
  setTimeout(() => window.URL.revokeObjectURL(url), 15000)
}
