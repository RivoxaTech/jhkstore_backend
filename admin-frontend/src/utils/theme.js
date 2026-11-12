// The panel wears the store's primary colour (Settings → Theme), so it matches the logo and the storefront.
// Two variables: `--brand` is the colour exactly as set (buttons, the login picture, active marks) with `--on-brand` as the text on it (the "text on the
// primary colour" of the theme, white when none is given); `--primary` is the same colour darkened just enough to be clearly visible as TEXT or an icon on a
// light background (links, soft buttons), at least 3:1.

const HEX = /^#?([0-9a-f]{6})$/i

function parse(hex) {
  const m = HEX.exec(String(hex || '').trim())
  if (!m) return null
  const n = parseInt(m[1], 16)
  return [(n >> 16) & 255, (n >> 8) & 255, n & 255]
}
const toHex = (rgb) => `#${rgb.map((v) => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, '0')).join('')}`
const mix = (a, b, t) => a.map((v, i) => v + (b[i] - v) * t)   // t = share of b
function luminance([r, g, b]) {
  const f = (v) => { const s = v / 255; return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4 }
  return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b)
}
const contrastWhite = (rgb) => 1.05 / (luminance(rgb) + 0.05)


const DARK_SURFACE_L = 0.011   // luminance of the dark panel's cards
const contrastOnDark = (rgb) => (luminance(rgb) + 0.05) / (DARK_SURFACE_L + 0.05)

/** The variables to set for a primary colour (and the text colour on it), or null when it is not a #RRGGBB value.
 *  Light panel: the colour darkened until it reads as text on white. Dark panel: lightened until it reads as text on the dark cards, and the soft
 *  tint is the colour at a low opacity (a pale tint would be a light patch on a dark page). */
export function primaryVars(hex, onHex, dark = false) {
  const brand = parse(hex)
  let rgb = parse(hex)
  if (!rgb) return null
  const on = parse(onHex) || [255, 255, 255]
  if (dark) {
    for (let i = 0; i < 40 && contrastOnDark(rgb) < 4.5; i++) rgb = mix(rgb, [255, 255, 255], 0.08)
    return {
      '--brand': toHex(brand),
      '--on-brand': toHex(on),
      '--primary': toHex(rgb),
      '--primary-dark': toHex(mix(rgb, [255, 255, 255], 0.28)),
      '--primary-soft': `rgba(${brand.map(Math.round).join(', ')}, .2)`,
    }
  }
  for (let i = 0; i < 40 && contrastWhite(rgb) < 3; i++) rgb = mix(rgb, [0, 0, 0], 0.06)
  return {
    '--brand': toHex(brand),
    '--on-brand': toHex(on),
    '--primary': toHex(rgb),
    '--primary-dark': toHex(mix(rgb, [0, 0, 0], 0.22)),
    '--primary-soft': toHex(mix(rgb, [255, 255, 255], 0.9)),
  }
}

const KEYS = ['--brand', '--on-brand', '--primary', '--primary-dark', '--primary-soft']
let last = null

/** Sets (or, without a valid colour, removes) the panel's primary colour; `onHex` is the theme's text colour on it. The colours follow the light / dark
 *  theme of the panel (call it again, or `reapplyPrimary`, when that changes). */
export function applyPrimary(hex, onHex) {
  last = [hex, onHex]
  const root = document.documentElement
  const vars = primaryVars(hex, onHex, root.dataset.theme === 'dark')
  for (const k of KEYS) { if (vars) root.style.setProperty(k, vars[k]); else root.style.removeProperty(k) }
}

/** The theme (light / dark) changed: work the primary colour out again for it. */
export function reapplyPrimary() { if (last) applyPrimary(last[0], last[1]) }
