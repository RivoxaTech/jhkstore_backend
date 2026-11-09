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


/** The variables to set for a primary colour (and the text colour on it), or null when it is not a #RRGGBB value. */
export function primaryVars(hex, onHex) {
  const brand = parse(hex)
  let rgb = parse(hex)
  if (!rgb) return null
  for (let i = 0; i < 40 && contrastWhite(rgb) < 3; i++) rgb = mix(rgb, [0, 0, 0], 0.06)
  const on = parse(onHex) || [255, 255, 255]
  return {
    '--brand': toHex(brand),
    '--on-brand': toHex(on),
    '--primary': toHex(rgb),
    '--primary-dark': toHex(mix(rgb, [0, 0, 0], 0.22)),
    '--primary-soft': toHex(mix(rgb, [255, 255, 255], 0.9)),
  }
}

const KEYS = ['--brand', '--on-brand', '--primary', '--primary-dark', '--primary-soft']

/** Sets (or, without a valid colour, removes) the panel's primary colour; `onHex` is the theme's text colour on it. */
export function applyPrimary(hex, onHex) {
  const root = document.documentElement
  const vars = primaryVars(hex, onHex)
  for (const k of KEYS) { if (vars) root.style.setProperty(k, vars[k]); else root.style.removeProperty(k) }
}
