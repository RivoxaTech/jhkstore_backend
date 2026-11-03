<script setup>
import { computed } from 'vue'

// Live preview of the storefront theme (Settings > Theme, developer only): a product card drawn with the values currently typed in the form.
// It uses font-family names with fallbacks and never loads a font from the internet, so a font that is not installed falls back to the system one.
// Nothing here changes the look of the admin panel itself: the colours are scoped to this card.
const props = defineProps({ values: { type: Object, required: true } })

const DEFAULTS = {
  theme_primary: '#2563EB', theme_primary_contrast: '#FFFFFF', theme_accent: '#F59E0B', theme_bg: '#FFFFFF', theme_surface: '#F8FAFC',
  theme_text: '#0F172A', theme_muted: '#64748B', theme_border: '#E2E8F0',
}
const SHADOWS = { none: 'none', soft: '0 2px 8px rgba(15, 23, 42, .10)', medium: '0 10px 28px rgba(15, 23, 42, .20)' }

const colour = (key) => (/^#[0-9a-f]{6}$/i.test(String(props.values[key] ?? '')) ? props.values[key] : DEFAULTS[key])
const font = (name) => (!name || name === 'system-ui' ? 'system-ui, -apple-system, "Segoe UI", sans-serif' : `"${name}", system-ui, -apple-system, "Segoe UI", sans-serif`)

const vars = computed(() => ({
  '--tp-primary': colour('theme_primary'),
  '--tp-on-primary': colour('theme_primary_contrast'),
  '--tp-accent': colour('theme_accent'),
  '--tp-bg': colour('theme_bg'),
  '--tp-surface': colour('theme_surface'),
  '--tp-text': colour('theme_text'),
  '--tp-muted': colour('theme_muted'),
  '--tp-border': colour('theme_border'),
  '--tp-radius': props.values.theme_radius || '8px',
  '--tp-shadow': SHADOWS[props.values.theme_shadow] || SHADOWS.soft,
  '--tp-font-heading': font(props.values.theme_font_heading),
  '--tp-font-body': font(props.values.theme_font_body),
}))
</script>

<template>
  <div class="tp-stage" :style="vars" data-theme-preview>
    <article class="tp-card">
      <div class="tp-img" aria-hidden="true"><span class="tp-badge">20% off</span><span class="tp-img-label">Product picture</span></div>
      <div class="tp-body">
        <h4 class="tp-name">Everyday Sample Product</h4>
        <p class="tp-desc">A short line about the product.</p>
        <div class="tp-price"><strong>$80.00</strong> <s>$100.00</s></div>
        <button type="button" class="tp-btn" tabindex="-1">Add to cart</button>
      </div>
    </article>
  </div>
</template>

<style scoped>
.tp-stage { background: var(--tp-bg); border: 1px solid var(--border); border-radius: 10px; padding: 18px; display: grid; place-items: center; font-family: var(--tp-font-body); color: var(--tp-text); }
.tp-card { width: 230px; background: var(--tp-surface); border: 1px solid var(--tp-border); border-radius: var(--tp-radius); box-shadow: var(--tp-shadow); overflow: hidden; }
.tp-img { position: relative; height: 130px; background: var(--tp-border); display: grid; place-items: center; }
.tp-img-label { color: var(--tp-muted); font-size: 12px; }
.tp-badge { position: absolute; top: 8px; left: 8px; background: var(--tp-accent); color: var(--tp-on-primary); font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: calc(var(--tp-radius) / 2); }
.tp-body { padding: 12px; display: grid; gap: 6px; }
.tp-name { font-family: var(--tp-font-heading); font-size: 15px; font-weight: 600; color: var(--tp-text); }
.tp-desc { color: var(--tp-muted); font-size: 12px; }
.tp-price { display: flex; align-items: baseline; gap: 8px; }
.tp-price strong { font-size: 16px; color: var(--tp-text); }
.tp-price s { color: var(--tp-muted); font-size: 12.5px; }
.tp-btn { background: var(--tp-primary); color: var(--tp-on-primary); border: 0; border-radius: var(--tp-radius); height: 34px; font-family: var(--tp-font-body); font-weight: 600; font-size: 13px; cursor: default; margin-top: 4px; }
</style>
