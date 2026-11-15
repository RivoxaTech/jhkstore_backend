<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { Clock, LifeBuoy, Play } from '@lucide/vue'
import { useAuthStore } from '@/stores/auth.js'

// Help: short, silent how-to videos with the steps written under each one. The list (titles, steps, lengths) is `help/videos.json`, written together with
// the videos by scripts/help-videos (so the text always matches the pictures). A video is shown to the people who may use the screen it is about.
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const base = import.meta.env.BASE_URL
const videos = ref([])
const loading = ref(true)
const failed = ref(false)

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

const shown = computed(() => videos.value.filter((v) => !v.can || auth.can(v.can)))
const current = computed(() => shown.value.find((v) => v.slug === route.query.v) || shown.value[0] || null)
const pick = (slug) => router.replace({ name: 'help', query: { v: slug } })
const file = (slug, ext) => `${base}help/${slug}.${ext}`
const length = (s) => `${Math.floor(s / 60)}:${String(Math.round(s % 60)).padStart(2, '0')}`
const size = (b) => (b >= 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`)
</script>

<template>
  <div class="page-head">
    <div>
      <h1>Help</h1>
      <div class="crumbs"><RouterLink :to="{ name: 'dashboard' }">Dashboard</RouterLink> › <span>Help</span></div>
    </div>
  </div>

  <div v-if="loading" class="card"><div class="card__body"><div class="skeleton" style="height: 320px" /></div></div>

  <section v-else-if="failed || !shown.length" class="card">
    <div class="card__body help-empty" data-testid="help-empty">
      <LifeBuoy :size="34" />
      <h2>No videos here yet</h2>
      <p class="muted">{{ failed ? 'The help videos are not installed on this site.' : 'There are no help videos for the screens you can use.' }}</p>
    </div>
  </section>

  <div v-else class="help" data-testid="help">
    <nav class="help__list" aria-label="Help videos">
      <button
        v-for="v in shown" :key="v.slug" type="button" class="help__item" :class="{ 'is-active': current && v.slug === current.slug }"
        :aria-current="current && v.slug === current.slug ? 'true' : undefined" :data-video="v.slug" @click="pick(v.slug)"
      >
        <span class="help__thumb"><img :src="file(v.slug, 'jpg')" :alt="''" loading="lazy" /><Play :size="16" /></span>
        <span class="help__item-text"><strong>{{ v.title }}</strong><small><Clock :size="11" /> {{ length(v.duration) }}</small></span>
      </button>
    </nav>

    <article v-if="current" class="card help__main">
      <div class="help__player">
        <video :key="current.slug" controls playsinline preload="metadata" :poster="file(current.slug, 'jpg')" :src="file(current.slug, 'mp4')" data-testid="help-video" />
      </div>
      <div class="card__body help__body">
        <h2>{{ current.title }}</h2>
        <p class="muted">{{ current.summary }}</p>
        <h3>Steps</h3>
        <ol class="help__steps">
          <li v-for="(s, i) in current.steps" :key="i">{{ s }}</li>
        </ol>
        <p class="help__meta muted">{{ length(current.duration) }} · {{ size(current.bytes) }} · no sound, the steps are written on the video.</p>
        <RouterLink v-if="current.open" class="btn btn-soft" :to="{ name: current.open.route }">{{ current.open.text }}</RouterLink>
      </div>
    </article>
  </div>
</template>

<style scoped>
.help { display: grid; grid-template-columns: 270px minmax(0, 1fr); gap: 12px; align-items: start; max-width: 1180px; }
.help__list { display: grid; gap: 6px; position: sticky; top: calc(var(--topbar-h) + 14px); }
.help__item { display: flex; align-items: center; gap: 10px; padding: 7px; border-radius: 12px; border: 1px solid var(--border-light); background: var(--surface); text-align: left; transition: .15s; }
.help__item:hover { border-color: var(--primary); }
.help__item.is-active { border-color: var(--primary); background: var(--primary-soft); }
.help__thumb { position: relative; flex: none; width: 76px; height: 44px; border-radius: 8px; overflow: hidden; background: var(--soft); display: grid; place-items: center; }
.help__thumb img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
.help__thumb svg { position: relative; color: #fff; filter: drop-shadow(0 1px 3px rgba(0, 0, 0, .5)); }
.help__item-text { min-width: 0; display: grid; gap: 2px; }
.help__item-text strong { font-size: 12.5px; font-weight: 600; line-height: 1.3; }
.help__item-text small { display: inline-flex; align-items: center; gap: 4px; color: var(--text-3); font-size: 11px; }
.help__main { overflow: hidden; }
.help__player { background: #0b1118; aspect-ratio: 16 / 9; }
.help__player video { width: 100%; height: 100%; display: block; background: #0b1118; }
.help__body { display: grid; gap: 8px; }
.help__body h2 { font-size: 17px; }
.help__body h3 { font-size: 12px; text-transform: uppercase; letter-spacing: .05em; color: var(--text-3); margin-top: 6px; }
.help__steps { margin: 0; padding-left: 20px; display: grid; gap: 4px; }
.help__steps li { padding-left: 4px; line-height: 1.5; }
.help__meta { font-size: 11.5px; }
.help__body .btn { justify-self: start; margin-top: 4px; }
.help-empty { display: grid; justify-items: center; gap: 6px; padding: 36px 14px; color: var(--text-3); text-align: center; }
.help-empty h2 { font-size: 15px; color: var(--text); }
@media (max-width: 900px) {
  .help { grid-template-columns: minmax(0, 1fr); }
  .help__list { position: static; display: flex; overflow-x: auto; gap: 8px; padding-bottom: 4px; }
  .help__item { flex: none; width: 230px; }
}
</style>
