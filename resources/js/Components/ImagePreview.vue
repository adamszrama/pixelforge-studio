<script setup lang="ts">
import { onBeforeUnmount, ref, watch, nextTick } from 'vue';
import type { Generation } from '../types';
import DownloadButton from './DownloadButton.vue';
const props = defineProps<{ generation: Generation | null }>();
const emit = defineEmits<{ close: [] }>();
const dialog = ref<HTMLDialogElement | null>(null);
const failed = ref(false);
let previousOverflow = '';
watch(
  () => props.generation,
  async (generation) => {
    failed.value = false;
    if (generation) {
      await nextTick();
      if (!dialog.value?.open) {
        previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        dialog.value?.showModal();
      }
    } else {
      dialog.value?.close();
      document.body.style.overflow = previousOverflow;
    }
  },
);
onBeforeUnmount(() => {
  document.body.style.overflow = previousOverflow;
});
</script>
<template>
  <dialog
    ref="dialog"
    class="image-dialog"
    aria-labelledby="preview-title"
    @cancel.prevent="emit('close')"
    @click="
      (event) => {
        if (event.target === dialog) emit('close');
      }
    "
  >
    <div v-if="generation" class="preview-panel">
      <div class="preview-header">
        <h2 id="preview-title">Image preview</h2>
        <button class="icon-button" autofocus aria-label="Close preview" @click="emit('close')">
          ✕
        </button>
      </div>
      <div class="preview-canvas">
        <img
          v-if="!failed && !generation.image_expired"
          :src="generation.image_url!"
          :alt="generation.prompt"
          referrerpolicy="no-referrer"
          @error="failed = true"
        />
        <p v-else role="status">This image is no longer available.</p>
      </div>
      <div class="preview-footer">
        <p>{{ generation.prompt }}</p>
        <DownloadButton v-if="!failed && !generation.image_expired" :generation="generation" />
      </div>
    </div>
  </dialog>
</template>
