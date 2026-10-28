<script setup>
import { ref } from 'vue'
import { api } from '@/api/client.js'
import AppModal from '@/components/AppModal.vue'

// Quick edit of the tags of one product (from the product list).
const props = defineProps({
  product: { type: Object, required: true }, // list row: { id, name, tags: [{ id }] }
  tags: { type: Array, required: true },     // all tags [{ id, name, color, is_active }]
})
const emit = defineEmits(['close', 'saved'])

const selected = ref(props.product.tags.map(t => t.id))
const busy = ref(false)
const message = ref('')

async function save() {
  busy.value = true
  message.value = ''
  try {
    const res = await api.put(`admin/products/${props.product.id}/tags`, { tag_ids: selected.value })
    emit('saved', props.product, res.data.tags)
  } catch (e) {
    message.value = e.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AppModal title="Edit tags" @close="emit('close')">
    <p class="muted" style="margin-bottom: 10px">Where <strong>{{ product.name }}</strong> shows on the website (best seller, hot selling, ...).</p>
    <div v-if="message" class="alert alert--error" role="alert">{{ message }}</div>
    <div class="tag-pick">
      <label v-for="t in tags" :key="t.id">
        <input v-model="selected" type="checkbox" :value="t.id" />
        <span class="tag-chip" :style="{ '--c': t.color || undefined }"><i class="dot" />{{ t.name }}<template v-if="!t.is_active"> (off)</template></span>
      </label>
    </div>
    <template #footer>
      <button class="btn btn-outline" type="button" :disabled="busy" @click="emit('close')">Cancel</button>
      <button class="btn btn-primary" type="button" :disabled="busy" @click="save"><span v-if="busy" class="spinner" /> Save tags</button>
    </template>
  </AppModal>
</template>
