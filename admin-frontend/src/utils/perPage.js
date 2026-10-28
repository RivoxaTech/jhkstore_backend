// Rows per page for the admin tables. The choice is remembered in the browser and shared by every table.
export const PER_PAGE_OPTIONS = [25, 50, 75, 100]
export const DEFAULT_PER_PAGE = 25
const KEY = 'dk_per_page'

export const isValidPerPage = (n) => PER_PAGE_OPTIONS.includes(Number(n))

export function storedPerPage() {
  try {
    const n = Number(localStorage.getItem(KEY))
    return isValidPerPage(n) ? n : DEFAULT_PER_PAGE
  } catch { return DEFAULT_PER_PAGE }
}

export function rememberPerPage(n) {
  try { localStorage.setItem(KEY, String(n)) } catch { /* private mode: the choice just is not remembered */ }
}
