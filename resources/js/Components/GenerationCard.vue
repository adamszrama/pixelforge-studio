<script setup lang="ts">
import { computed, ref } from 'vue';
import type { Generation } from '../types';
import MoneyAmount from './MoneyAmount.vue';
import DownloadButton from './DownloadButton.vue';
const props = defineProps<{ generation: Generation }>();
defineEmits<{ reuse: [generation: Generation]; preview: [generation: Generation] }>();
const broken = ref(false);
const ready = computed(
  () =>
    props.generation.status === 'succeeded' &&
    props.generation.image_url &&
    !props.generation.image_expired &&
    !broken.value,
);
const label = computed(() =>
  props.generation.needs_review
    ? 'Under review'
    : { queued: 'Queued', processing: 'Generating', succeeded: 'Ready', failed: 'Failed' }[
        props.generation.status
      ],
);
const placeholder = computed(() => {
  const g = props.generation;
  if (g.status === 'failed')
    return ['This one didn’t work out', 'No charge. Try a different prompt.'];
  if (g.status === 'succeeded')
    return [
      g.image_expired ? 'Image link expired' : 'Image unavailable',
      'Your prompt and cost are saved. Reuse the settings to make a new image.',
    ];
  if (g.needs_review)
    return [
      'We’re checking this request',
      'Credits stay reserved until the provider confirms the result.',
    ];
  return g.status === 'processing'
    ? ['Creating your image', 'You can leave this page. We’ll keep working.']
    : ['Your idea is in the queue', 'Generation will start automatically.'];
});
</script>
<template>
  <article class="generation-card">
    <div class="image-frame" :class="generation.status">
      <button
        v-if="ready"
        class="preview-trigger"
        type="button"
        :aria-label="`Preview image: ${generation.prompt}`"
        @click="$emit('preview', generation)"
      >
        <img
          :src="generation.image_url!"
          :alt="generation.prompt"
          loading="lazy"
          referrerpolicy="no-referrer"
          @error="broken = true"
        />
        <span class="preview-hint">↗ Open preview</span>
      </button>
      <div v-else class="image-placeholder">
        <span
          v-if="['queued', 'processing'].includes(generation.status)"
          class="spinner"
          aria-hidden="true"
        ></span>
        <span v-else class="placeholder-symbol" aria-hidden="true">{{
          generation.status === 'failed' ? '↻' : '◈'
        }}</span>
        <b>{{ placeholder[0] }}</b><small>{{ placeholder[1] }}</small>
      </div>
      <span class="status-badge" :class="generation.status"
        ><span class="status-dot"></span>{{ label }}</span
      >
    </div>
    <div class="generation-info">
      <p class="generation-prompt" :title="generation.prompt">{{ generation.prompt }}</p>
      <div class="generation-meta">
        <span>{{ generation.quality }} · {{ generation.size.replace('x', ' × ') }}</span
        ><time :datetime="generation.created_at">{{
          new Date(generation.created_at).toLocaleDateString(undefined, {
            month: 'short',
            day: 'numeric',
          })
        }}</time>
      </div>
      <p
        v-if="generation.message"
        class="generation-message"
        :class="{ failure: generation.status === 'failed' }"
      >
        {{ generation.message }}
      </p>
      <div class="cost-row">
        <div><small>Estimated</small><MoneyAmount :amount="generation.estimated_cost" /></div>
        <div>
          <small>{{ generation.status === 'failed' ? 'Charged' : 'Final cost' }}</small><strong
            ><MoneyAmount :amount="generation.actual_cost"
          /></strong>
        </div>
      </div>
      <div class="card-actions">
        <DownloadButton v-if="ready" :generation="generation" />
        <button type="button" class="button small secondary" @click="$emit('reuse', generation)">
          ↻ Reuse settings
        </button>
      </div>
      <details class="cost-details">
        <summary>Cost details</summary>
        <dl>
          <dt>Estimate</dt>
          <dd><MoneyAmount :amount="generation.estimated_cost" exact /></dd>
          <dt>Final charge</dt>
          <dd><MoneyAmount :amount="generation.actual_cost" exact /></dd>
        </dl>
        <p>USD · Provider cost + 25%. Failed generations are free.</p>
      </details>
      <p v-if="ready" class="expiry-note">Download to keep it. Image links last 24 hours.</p>
    </div>
  </article>
</template>
