<script setup lang="ts">
import { ref } from 'vue';
import type { Generation } from '../types';
const props = defineProps<{ generation: Generation }>();
const downloading = ref(false);
const error = ref('');
async function download() {
  if (downloading.value) return;
  downloading.value = true;
  error.value = '';
  try {
    const response = await fetch(props.generation.download_url, {
      headers: { Accept: 'application/json' },
    });
    if (!response.ok) {
      const data = await response.json().catch(() => ({}));
      throw new Error(data.message || 'Download unavailable. Please try again.');
    }
    if (!response.headers.get('Content-Type')?.startsWith('image/png')) {
      throw new Error('Your session may have expired. Refresh the page and try again.');
    }
    const url = URL.createObjectURL(await response.blob());
    const link = document.createElement('a');
    link.href = url;
    link.download = `pixelforge-${props.generation.id}.png`;
    document.body.append(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : 'Download failed. Please try again.';
  } finally {
    downloading.value = false;
  }
}
</script>
<template>
  <div class="download-action">
    <button type="button" class="button small primary" :disabled="downloading" @click="download">
      <svg
        width="16"
        height="16"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.8"
        aria-hidden="true"
      >
        <path d="M12 3v12m-5-5 5 5 5-5M5 16v5h14v-5" />
      </svg>
      {{ downloading ? 'Downloading…' : 'Download PNG' }}
    </button>
    <p v-if="error" class="failure action-error" role="alert">{{ error }}</p>
  </div>
</template>
