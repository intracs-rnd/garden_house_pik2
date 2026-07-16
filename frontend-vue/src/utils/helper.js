/**
 * Generic helpers used across the app.
 */

/** Vehicle status map (matches the Laravel Kendaraan model). */
export const KENDARAAN_STATUS = {
  1: { label: 'Aktif', variant: 'success' },
  2: { label: 'Non Aktif', variant: 'muted' },
  3: { label: 'Blacklist', variant: 'danger' },
}

export const KENDARAAN_STATUS_OPTIONS = Object.entries(KENDARAAN_STATUS).map(
  ([value, meta]) => ({ value: Number(value), label: meta.label }),
)

/** Access card status map (matches the Laravel Kartu model). */
export const KARTU_STATUS = {
  1: { label: 'Aktif', variant: 'success' },
  2: { label: 'Non Aktif', variant: 'muted' },
  3: { label: 'Blacklist', variant: 'danger' },
}

export const KARTU_STATUS_OPTIONS = Object.entries(KARTU_STATUS).map(
  ([value, meta]) => ({ value: Number(value), label: meta.label }),
)

/**
 * Access-decision reason codes returned by the gate (tab-in / tab-out).
 * Mirrors App\Models\Kartu::REASON_* on the backend.
 */
export const KARTU_ACCESS_REASON = {
  ok: { label: 'Akses diberikan', variant: 'success' },
  grace_period: { label: 'Masa tenggang', variant: 'warning' },
  unknown_card: { label: 'Kartu tidak dikenali', variant: 'danger' },
  inactive: { label: 'Kartu tidak aktif', variant: 'muted' },
  blacklisted: { label: 'Diblokir (blacklist)', variant: 'danger' },
  outstanding_payment: { label: 'Ada tunggakan', variant: 'danger' },
  not_yet_valid: { label: 'Belum berlaku', variant: 'muted' },
  expired: { label: 'Masa berlaku habis', variant: 'danger' },
}

export function kartuReasonMeta(reason) {
  return KARTU_ACCESS_REASON[reason] || { label: reason || 'Tidak diketahui', variant: 'muted' }
}

/** User role options (matches backend validation: superadmin, admin, staff, user). */
export const USER_ROLES = [
  { value: 'superadmin', label: 'Super Admin' },
  { value: 'admin', label: 'Admin' },
  // { value: 'staff', label: 'Staff' },
  { value: 'user', label: 'User' },
]

export const USER_ROLE_VARIANT = {
  superadmin: 'primary',
  admin: 'info',
  staff: 'warning',
  user: 'muted',
}

/** User type options (matches backend validation: warga, tamu). */
export const USER_TYPES = [
  { value: 'warga', label: 'Warga' },
  { value: 'tamu', label: 'Tamu' },
]

export const USER_TYPE_VARIANT = {
  warga: 'success',
  tamu: 'warning',
}

/**
 * Extract a friendly error message from an Axios error, taking the API's
 * { message, errors } envelope into account.
 */
export function extractErrorMessage(error, fallback = 'Terjadi kesalahan. Silakan coba lagi.') {
  const data = error?.response?.data
  if (data?.message) return data.message
  if (data?.errors) {
    const first = Object.values(data.errors)[0]
    if (Array.isArray(first)) return first[0]
  }
  if (error?.message) return error.message
  return fallback
}

/**
 * Extract Laravel validation errors as a flat { field: message } object.
 */
export function extractValidationErrors(error) {
  const errors = error?.response?.data?.errors
  if (!errors) return {}
  return Object.keys(errors).reduce((acc, key) => {
    acc[key] = Array.isArray(errors[key]) ? errors[key][0] : errors[key]
    return acc
  }, {})
}

/** Simple debounce utility. */
export function debounce(fn, delay = 350) {
  let timer
  return (...args) => {
    clearTimeout(timer)
    timer = setTimeout(() => fn(...args), delay)
  }
}
