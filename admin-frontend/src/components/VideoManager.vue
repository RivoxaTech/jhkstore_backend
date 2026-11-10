<script setup>
import { computed, ref } from 'vue'
import { ArrowDown, ArrowUp, ImagePlus, Trash2, UploadCloud } from '@lucide/vue'
import { api } from '@/api/client.js'
import { useUiStore } from '@/stores/ui.js'
import { formatBytes } from '@/utils/format.js'

// The uploaded videos of a product (MP4 or WEBM, no conversion): upload with a progress bar and an optional poster picture, replace a poster,
// reorder, delete. On a saved product every action calls the API at once. On a NEW product a chosen video waits in `pending` ([{ key, file, poster }])
// and the product form uploads it right after the product is saved. `modelValue` is the server's list [{ id, name, mime, size, url, poster_url }].
// The video LINK (YouTube, Vimeo or a direct file) is a field of the product form itself.
const props = defineProps({
  productId: { type: Number, default: null },
  limits: { type: Object, default: () => ({ max_files: 2, max_mb: 50, poster_max_mb: 2 }) },
})
const videos = defineModel({ type: Array, default: () => [] })
const pending = defineModel('pending', { type: Array, default: () => [] })
const ui = useUiStore()

const file = ref(null)
const poster = ref(null)
const progress = ref(null)     // null = idle, 0..100 while uploading
const error = ref('')
const busy = computed(() => progress.value !== null)
const staging = computed(() => props.productId === null)
const full = computed(() => (staging.value ? pending.value.length : videos.value.length) >= props.limits.max_files)

const TYPES = ['video/mp4', 'video/webm']
function pickVideo(e) {
  const f = e.target.files[0]
  e.target.value = ''
  error.value = ''
  if (!f) return
  if (!TYPES.includes(f.type) && !/\.(mp4|webm)$/i.test(f.name)) { error.value = 'The video must be an MP4 or WEBM file.'; return }
  if (f.size > props.limits.max_mb * 1024 * 1024) { error.value = `The video must be ${props.limits.max_mb} MB or smaller (this one is ${formatBytes(f.size)}).`; return }
  file.value = f
  // choosing the file IS the upload: a saved product gets it at once (with the progress bar), a new product keeps it and uploads it when the product is saved
  if (staging.value) stage(); else upload()
}
function pickPoster(e) {
  const f = e.target.files[0]
  e.target.value = ''
  if (!f) return
  if (!/\.(png|jpe?g|webp)$/i.test(f.name)) { error.value = 'The poster must be a PNG, JPG or WEBP picture.'; return }
  if (f.size > props.limits.poster_max_mb * 1024 * 1024) { error.value = `The poster must be ${props.limits.poster_max_mb} MB or smaller.`; return }
  poster.value = f
}

const messageOf = (e) => (e.status === 422 ? Object.values(e.errors).flat()[0] || e.message : e.message)

/** New product: keep the chosen video (and poster) until the product is saved. */
function stage() {
  if (!file.value) return
  pending.value = [...pending.value, { key: `${Date.now()}-${pending.value.length}`, file: file.value, poster: poster.value }]
  file.value = null
  poster.value = null
}
const unstage = (key) => { pending.value = pending.value.filter(p => p.key !== key) }

async function upload() {
  if (!file.value || busy.value) return
  error.value = ''
  progress.value = 0
  try {
    const form = new FormData()
    form.append('video', file.value)
    if (poster.value) form.append('poster', poster.value)
    videos.value = (await api.postFormProgress(`admin/products/${props.productId}/videos`, form, (p) => { progress.value = p })).data
    file.value = null
    poster.value = null
    ui.toast('Video added.')
  } catch (e) {
    error.value = messageOf(e)
  } finally {
    progress.value = null
  }
}

async function replacePoster(v, e) {
  const f = e.target.files[0]
  e.target.value = ''
  if (!f) return
  error.value = ''
  try {
    const form = new FormData()
    form.append('poster', f)
    videos.value = (await api.postForm(`admin/products/${props.productId}/videos/${v.id}/poster`, form)).data
    ui.toast('Poster changed.')
  } catch (err) {
    error.value = messageOf(err)
  }
}

async function remove(v) {
  if (!window.confirm(`Delete the video "${v.name}"?`)) return
  try {
    videos.value = (await api.del(`admin/products/${props.productId}/videos/${v.id}`)).data
    ui.toast('Video deleted.', 'info')
  } catch (e) {
    error.value = messageOf(e)
  }
}

async function move(i, by) {
  const ids = videos.value.map(v => v.id)
  ;[ids[i], ids[i + by]] = [ids[i + by], ids[i]]
  try {
    videos.value = (await api.put(`admin/products/${props.productId}/videos/order`, { ids })).data
  } catch (e) {
    error.value = messageOf(e)
  }
}
</script>

<template>
  <div class="vm" data-testid="video-manager">
    <ul v-if="staging && pending.length" class="vm__list" data-testid="video-pending">
      <li v-for="p in pending" :key="p.key">
        <span class="vm__poster"><UploadCloud :size="20" /></span>
        <div class="grow"><strong>{{ p.file.name }}</strong><small class="muted">{{ formatBytes(p.file.size) }} · uploaded when you save the product</small></div>
        <button class="btn btn-soft-danger btn-sm btn-icon" type="button" :aria-label="`Remove ${p.file.name}`" @click="unstage(p.key)"><Trash2 :size="14" /></button>
      </li>
    </ul>
    <ul v-if="!staging && videos.length" class="vm__list">
      <li v-for="(v, i) in videos" :key="v.id" :data-video="v.id">
        <span class="vm__poster"><img v-if="v.poster_url" :src="v.poster_url" :alt="`Poster of ${v.name}`" /><UploadCloud v-else :size="20" /></span>
        <div class="grow">
          <a :href="v.url" target="_blank" rel="noopener"><strong>{{ v.name }}</strong></a>
          <small class="muted">{{ v.mime }} · {{ formatBytes(v.size) }}<template v-if="!v.poster_url"> · no poster picture</template></small>
        </div>
        <label class="btn btn-soft btn-sm btn-icon" title="Change the poster picture"><ImagePlus :size="14" /><input type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" :aria-label="`Poster of ${v.name}`" @change="replacePoster(v, $event)" /></label>
        <button class="btn btn-soft btn-sm btn-icon" type="button" :disabled="i === 0" aria-label="Move up" @click="move(i, -1)"><ArrowUp :size="14" /></button>
        <button class="btn btn-soft btn-sm btn-icon" type="button" :disabled="i === videos.length - 1" aria-label="Move down" @click="move(i, 1)"><ArrowDown :size="14" /></button>
        <button class="btn btn-soft-danger btn-sm btn-icon" type="button" :aria-label="`Delete ${v.name}`" @click="remove(v)"><Trash2 :size="14" /></button>
      </li>
    </ul>

    <div v-if="!full" class="vm__add">
      <label class="btn btn-primary btn-sm"><UploadCloud :size="14" /> {{ busy ? 'Uploading...' : 'Upload video' }}<input type="file" accept="video/mp4,video/webm" class="sr-only" aria-label="Video file" data-testid="video-input" :disabled="busy" @change="pickVideo" /></label>
      <label class="btn btn-outline btn-sm"><ImagePlus :size="14" /> {{ poster ? 'Change poster' : 'Poster picture (optional, pick it first)' }}<input type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" aria-label="Poster picture" :disabled="busy" @change="pickPoster" /></label>
      <span v-if="file && busy" class="muted">{{ file.name }} ({{ formatBytes(file.size) }})</span>
    </div>
    <div v-if="busy" class="vm__bar" role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100"><i :style="{ width: `${progress}%` }" /><span>{{ progress }}%</span></div>
    <p v-else-if="full" class="hint">{{ limits.max_files }} videos is the most a product can have. Delete one to add another.</p>
    <p class="hint">MP4 or WEBM, up to {{ limits.max_mb }} MB each, at most {{ limits.max_files }} videos. The file is shown as it is (no conversion), so keep it small. The server's upload limit (upload_max_filesize) must allow it.</p>
    <p v-if="error" class="err" role="alert">{{ error }}</p>
  </div>
</template>

<style scoped>
.vm { display: grid; gap: 8px; }
.vm__list { list-style: none; display: grid; gap: 6px; }
.vm__list li { display: flex; align-items: center; gap: 8px; padding: 6px 8px; border: 1px solid var(--border-light); border-radius: 8px; }
.vm__poster { width: 56px; height: 36px; border-radius: 6px; background: var(--muted-soft); display: grid; place-items: center; overflow: hidden; color: var(--text-3); flex: none; }
.vm__poster img { width: 100%; height: 100%; object-fit: cover; }
.grow { flex: 1; min-width: 0; }
.grow small { display: block; }
.vm__add { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.vm__bar { position: relative; height: 18px; background: var(--muted-soft); border-radius: 9px; overflow: hidden; }
.vm__bar i { display: block; height: 100%; background: var(--primary); transition: width .15s; }
.vm__bar span { position: absolute; inset: 0; display: grid; place-items: center; font-size: 11px; font-weight: 600; color: #fff; mix-blend-mode: difference; }
label.btn { cursor: pointer; }
</style>
