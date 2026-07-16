/**
 * Lightweight client-side validators.
 * Each returns an error string, or an empty string when valid.
 */

export function required(value, label = 'Field') {
  if (value === null || value === undefined || String(value).trim() === '') {
    return `${label} wajib diisi.`
  }
  return ''
}

export function email(value) {
  if (!value) return ''
  const pattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
  return pattern.test(value) ? '' : 'Format email tidak valid.'
}

export function minLength(value, length, label = 'Field') {
  if (!value) return ''
  return String(value).length >= length
    ? ''
    : `${label} minimal ${length} karakter.`
}

export function matches(value, other, label = 'Konfirmasi') {
  return value === other ? '' : `${label} tidak cocok.`
}

/**
 * Run a set of rules and return the first error found.
 * @param {Array<() => string>} rules
 */
export function firstError(rules) {
  for (const rule of rules) {
    const error = rule()
    if (error) return error
  }
  return ''
}
