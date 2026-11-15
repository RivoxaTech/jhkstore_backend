<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { ArrowLeft, Clock, LifeBuoy, Play, Search } from '@lucide/vue'
import { useAuthStore } from '@/stores/auth.js'
import { useSiteStore } from '@/stores/site.js'

// Help, laid out like a video site: a grid of videos (a picture, the length, a title, a short description) and, when one is opened, a watch page with the
// player, the written steps (each with its time: press one to jump there, the one being shown lights up) and the other videos beside it.
// The list is `help/videos.json`, written together with the videos by scripts/help-videos, so the text always matches the pictures.
// Every account sees every video (they teach the whole panel); "Open the screen" shows only for the screens the account may use.
const auth = useAuthStore()
const site = useSiteStore()
const route = useRoute()
const base = import.meta.env.BASE_URL
const videos = ref([])
const loading = ref(true)
const failed = ref(false)
const query = ref('')

onMounted(async () => {
  try {
    const res = await fetch(`${base}help/videos.json`, { cache: 'no-cache' })
    if (!res.ok) throw new Error('missing')
    videos.value = (await res.json()).videos || []
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
})

const slug = computed(() => String(route.params.slug || ''))
const current = computed(() => videos.value.find((v) => v.slug === slug.value) || null)
const others = computed(() => videos.value.filter((v) => v.slug !== slug.value))
const shown = computed(() => {
  const q = query.value.trim().toLowerCase()
  return q ? videos.value.filter((v) => `${v.title} ${v.summary} ${v.topic || ''}`.toLowerCase().includes(q)) : videos.value
})
const steps = computed(() => (current.value?.steps || []).map((s) => (typeof s === 'string' ? { text: s, at: null } : s)))

// the videos come through the backend (it answers byte ranges, so a step can jump to its time); the dev server of Vite does that by itself
const file = (s, ext) => (ext === 'mp4' && !import.meta.env.DEV ? `/admin/help-media/${s}.mp4` : `${base}help/${s}.${ext}`)
const length = (s) => `${Math.floor(s / 60)}:${String(Math.round(s % 60)).padStart(2, '0')}`
const size = (b) => (b >= 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`)
const mayOpen = computed(() => Boolean(current.value?.open && (!current.value.can || auth.can(current.value.can))))

// the player and the step it is at
const player = ref(null)
const now = ref(0)
const activeStep = computed(() => steps.value.reduce((n, s, i) => (s.at !== null && now.value + 0.3 >= s.at ? i : n), -1))
function jump(step) {
  if (step.at === null || !player.value) return
  player.value.currentTime = Math.max(0, step.at - 0.2)
  player.value.play().catch(() => { /* the browser wants a click first: the picture is at the right step anyway */ })
  player.value.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
}
watch(slug, () => { now.value = 0; window.scrollTo({ top: 0 }) })
</script>

<template>
  <div class="page-head">
    <div>
      <h1>Help</h1>
      <div class="crumbs">
        <RouterLink :to="{ name: 'dashboard' }">Dashboard</RouterLink> ›
        <RouterLink v-if="slug" :to="{ name: 'help' }">Help</RouterLink><span v-else>Help</span>
        <template v-if="current"> › <span>{{ current.title }}</span></template>
      </div>
    </div>
    <div v-if="!slug && videos.length > 3" class="page-actions help-search">
      <Search :size="15" />
      <input v-model="query" class="input" type="search" placeholder="Search the videos" aria-label="Search the videos" />
    </div>
  </div>

  <div v-if="loading" class="help-grid" aria-hidden="true">
    <div v-for="n in 6" :key="n" class="hv"><div class="skeleton hv__thumb" /><div class="hv__meta"><div class="skeleton hv__avatar" /><div style="flex: 1"><div class="skeleton" style="height: 14px; width: 80%" /><div class="skeleton" style="height: 12px; width: 55%; margin-top: 8px" /></div></div></div>
  </div>

  <section v-else-if="failed || !videos.length" class="card">
    <div class="card__body help-empty" data-testid="help-empty">
      <LifeBuoy :size="34" />
      <h2>No videos here yet</h2>
      <p class="muted">The help videos are not installed on this site.</p>
    </div>
  </section>

  <!-- ===================================================== the watch page -->
  <div v-else-if="slug" class="watch" data-testid="help-watch">
    <section v-if="!current" class="card"><div class="card__body help-empty"><LifeBuoy :size="34" /><h2>We could not find that video</h2><RouterLink class="btn btn-soft" :to="{ name: 'help' }">All videos</RouterLink></div></section>
    <template v-else>
      <div class="watch__main">
        <div class="watch__player"><video ref="player" :key="current.slug" controls playsinline preload="metadata" :poster="file(current.slug, 'jpg')" :src="file(current.slug, 'mp4')" data-testid="help-video" @timeupdate="now = $event.target.currentTime" /></div>
        <h2 class="watch__title">{{ current.title }}</h2>
        <p class="watch__meta muted"><span v-if="current.topic" class="chip">{{ current.topic }}</span> <Clock :size="12" /> {{ length(current.duration) }} · {{ size(current.bytes) }} · no sound, the steps are written on the video</p>
        <p class="watch__about">{{ current.summary }}</p>
        <RouterLink v-if="mayOpen" class="btn btn-soft" :to="{ name: current.open.route }">{{ current.open.text }}</RouterLink>

        <h3 class="watch__h">Steps</h3>
        <ol class="steps" data-testid="help-steps">
          <li v-for="(s, i) in steps" :key="i">
            <button type="button" class="step" :class="{ 'is-now': i === activeStep, 'is-done': activeStep > i }" :disabled="s.at === null" :data-step="i + 1" @click="jump(s)">
              <b>{{ i + 1 }}</b><span>{{ s.text }}</span><time v-if="s.at !== null">{{ length(s.at) }}</time>
            </button>
          </li>
        </ol>
      </div>

      <aside class="watch__side" aria-label="More videos">
        <h3 class="watch__h">More videos</h3>
        <RouterLink v-for="v in others" :key="v.slug" :to="{ name: 'help', params: { slug: v.slug } }" class="side" :data-video="v.slug">
          <span class="side__thumb"><img :src="file(v.slug, 'jpg')" alt="" loading="lazy" /><em>{{ length(v.duration) }}</em></span>
          <span class="side__text"><strong>{{ v.title }}</strong><small>{{ v.topic || 'Help' }}</small></span>
        </RouterLink>
      </aside>
    </template>
  </div>

  <!-- ===================================================== the grid -->
  <template v-else>
    <p v-if="!shown.length" class="muted" data-testid="help-none">No video matches "{{ query }}".</p>
    <div class="help-grid" data-testid="help">
      <RouterLink v-for="v in shown" :key="v.slug" :to="{ name: 'help', params: { slug: v.slug } }" class="hv" :data-video="v.slug">
        <span class="hv__thumb">
          <img :src="file(v.slug, 'jpg')" :alt="''" loading="lazy" />
          <em>{{ length(v.duration) }}</em>
          <span class="hv__play"><Play :size="26" /></span>
        </span>
        <span class="hv__meta">
          <span class="hv__avatar"><img v-if="site.faviconUrl || site.logoUrl" :src="site.faviconUrl || site.logoUrl" alt="" /><template v-else>{{ (site.name || 'S').charAt(0) }}</template></span>
          <span class="hv__text">
            <strong>{{ v.title }}</strong>
            <span class="hv__desc">{{ v.summary }}</span>
            <small>{{ v.topic || 'Help' }} · {{ length(v.duration) }}</small>
          </span>
        </span>
      </RouterLink>
    </div>
  </template>
</template>

<style scoped>
.help-search { position: relative; }
.help-search svg { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: var(--text-3); pointer-events: none; }
.help-search .input { width: 240px; padding-left: 34px; }

/* the grid: three across, like a video site */
.help-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 22px 18px; }
.hv { display: grid; gap: 10px; align-content: start; color: inherit; border-radius: 14px; }
.hv__thumb { position: relative; display: block; aspect-ratio: 16 / 9; border-radius: 12px; overflow: hidden; background: var(--soft); box-shadow: var(--shadow-sm); }
.hv__thumb img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; transition: transform .25s; }
.hv:hover .hv__thumb img { transform: scale(1.04); }
.hv__thumb em { position: absolute; right: 8px; bottom: 8px; padding: 1px 6px; border-radius: 6px; background: rgba(0, 0, 0, .78); color: #fff; font: 600 11.5px/1.5 var(--font); font-style: normal; }
.hv__play { position: absolute; inset: 0; display: grid; place-items: center; color: #fff; background: rgba(15, 23, 42, .28); opacity: 0; transition: opacity .2s; }
.hv__play svg { padding: 11px; box-sizing: content-box; border-radius: 50%; background: rgba(15, 23, 42, .6); }
.hv:hover .hv__play, .hv:focus-visible .hv__play { opacity: 1; }
.hv__meta { display: flex; gap: 11px; align-items: flex-start; }
.hv__avatar { flex: none; width: 36px; height: 36px; border-radius: 50%; overflow: hidden; display: grid; place-items: center; background: var(--brand, var(--primary)); color: var(--on-brand, #fff); font-weight: 600; font-size: 14px; border: 1px solid var(--border-light); }
.hv__avatar img { width: 100%; height: 100%; object-fit: cover; background: #fff; }
.hv__text { min-width: 0; display: grid; gap: 3px; }
.hv__text strong { font-size: 14.5px; font-weight: 600; line-height: 1.35; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.hv__desc { font-size: 12.5px; line-height: 1.45; color: var(--text-2); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.hv__text small { font-size: 11.5px; color: var(--text-3); }
.hv:hover .hv__text strong { color: var(--primary); }

/* the watch page */
.watch { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 24px; align-items: start; max-width: 1380px; }
.watch__player { background: #0b1118; border-radius: 14px; overflow: hidden; aspect-ratio: 1280 / 760; box-shadow: var(--shadow-md); }
.watch__player video { width: 100%; height: 100%; display: block; background: #0b1118; }
.watch__title { font-size: 19px; margin-top: 14px; line-height: 1.3; }
.watch__meta { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: 6px; font-size: 12px; }
.watch__about { margin: 10px 0; line-height: 1.55; color: var(--text); }
.watch__h { font-size: 12px; text-transform: uppercase; letter-spacing: .05em; color: var(--text-3); margin: 18px 0 8px; }
.steps { list-style: none; display: grid; gap: 6px; }
.step { width: 100%; display: grid; grid-template-columns: 28px minmax(0, 1fr) auto; gap: 10px; align-items: center; text-align: left; padding: 9px 12px; border-radius: 12px; border: 1px solid var(--border-light); background: var(--surface); transition: .15s; }
.step:not(:disabled):hover { border-color: var(--primary); }
.step b { width: 26px; height: 26px; border-radius: 50%; display: grid; place-items: center; background: var(--muted-soft); color: var(--text-2); font-size: 12px; font-weight: 600; }
.step span { line-height: 1.4; }
.step time { font-size: 11.5px; color: var(--primary); background: var(--primary-soft); border-radius: 8px; padding: 1px 8px; font-variant-numeric: tabular-nums; }
.step.is-done b { background: var(--success-soft); color: var(--success); }
.step.is-now { border-color: var(--primary); background: var(--primary-soft); }
.step.is-now b { background: var(--brand, var(--primary)); color: var(--on-brand, #fff); }
.step:disabled { cursor: default; }
.watch__side { display: grid; gap: 10px; position: sticky; top: calc(var(--topbar-h) + 14px); }
.watch__side .watch__h { margin: 0 0 2px; }
.side { display: flex; gap: 10px; color: inherit; border-radius: 12px; }
.side__thumb { position: relative; flex: none; width: 150px; aspect-ratio: 16 / 9; border-radius: 10px; overflow: hidden; background: var(--soft); }
.side__thumb img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
.side__thumb em { position: absolute; right: 5px; bottom: 5px; padding: 0 5px; border-radius: 5px; background: rgba(0, 0, 0, .78); color: #fff; font: 600 10.5px/1.5 var(--font); font-style: normal; }
.side__text { min-width: 0; display: grid; gap: 2px; align-content: start; }
.side__text strong { font-size: 13px; line-height: 1.35; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.side__text small { font-size: 11.5px; color: var(--text-3); }
.side:hover strong { color: var(--primary); }
.chip { display: inline-block; }

.help-empty { display: grid; justify-items: center; gap: 8px; padding: 36px 14px; color: var(--text-3); text-align: center; }
.help-empty h2 { font-size: 15px; color: var(--text); }

@media (max-width: 1180px) { .watch { grid-template-columns: minmax(0, 1fr); } .watch__side { position: static; } .side__thumb { width: 168px; } }
@media (max-width: 1000px) { .help-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 640px) {
  .help-grid { grid-template-columns: minmax(0, 1fr); gap: 18px; }
  .hv__thumb { border-radius: 10px; }
  .help-search .input { width: 100%; }
  .step { padding: 8px 10px; grid-template-columns: 26px minmax(0, 1fr) auto; }
  .watch__player { border-radius: 10px; }
}
</style>
