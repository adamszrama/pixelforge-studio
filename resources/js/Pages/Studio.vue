<script setup lang="ts">
import { Head, Link, router, useForm, usePage, usePoll } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import Shell from '../Components/Shell.vue';
import type { Auth, Generation, Pagination } from '../types';
import { money } from '../types';
const props = defineProps<{ generations: Pagination<Generation> }>();
const page = usePage();
const form = useForm({
  request_id: crypto.randomUUID(),
  prompt: '',
  size: '1024x1024',
  quality: 'medium',
  reference_images: [] as string[],
});
const references = ref('');
const quote = ref<{ estimate: string; maximum: string } | null>(null);
const quoteError = ref('');
const quoting = ref(false);
const brokenImages = ref(new Set<string>());
const auth = computed(() => page.props.auth as Auth);
const canAfford = computed(
  () => quote.value && Number(auth.value.available) >= Number(quote.value.maximum),
);
const active = computed(
  () => props.generations.data.filter((g) => ['queued', 'processing'].includes(g.status)).length,
);
let timer: ReturnType<typeof setTimeout>;
let controller: AbortController | null = null;
let revision = 0;
watch([() => form.prompt, () => form.size, () => form.quality, references], () => {
  clearTimeout(timer);
  controller?.abort();
  revision++;
  quote.value = null;
  quoteError.value = '';
  quoting.value = false;
  form.reference_images = references.value
    .split('\n')
    .map((s) => s.trim())
    .filter(Boolean);
  if (!form.prompt.trim()) return;
  quoting.value = true;
  const current = revision;
  timer = setTimeout(async () => {
    controller = new AbortController();
    try {
      const response = await fetch('/generations/quote', {
        method: 'POST',
        credentials: 'same-origin',
        signal: controller.signal,
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-XSRF-TOKEN': decodeURIComponent(
            document.cookie
              .split('; ')
              .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
              ?.slice(11) ?? '',
          ),
        },
        body: JSON.stringify({
          prompt: form.prompt,
          size: form.size,
          quality: form.quality,
          reference_images: form.reference_images,
        }),
      });
      const data = await response.json();
      if (current !== revision) return;
      if (!response.ok)
        throw new Error(data.message || 'Pricing is unavailable. Please refresh and try again.');
      quote.value = data;
    } catch (error) {
      if (current === revision && !(error instanceof DOMException && error.name === 'AbortError'))
        quoteError.value = error instanceof Error ? error.message : 'Unable to load pricing.';
    } finally {
      if (current === revision) quoting.value = false;
    }
  }, 350);
});
onBeforeUnmount(() => {
  clearTimeout(timer);
  controller?.abort();
});
usePoll(5000, { only: ['generations', 'auth'] });
function submit() {
  if (!quote.value || form.processing) return;
  form.post('/generations', {
    preserveScroll: true,
    onSuccess: () => {
      form.request_id = crypto.randomUUID();
    },
  });
}
function reuse(g: Generation) {
  form.prompt = g.prompt;
  form.size = g.size;
  form.quality = g.quality;
  form.request_id = crypto.randomUUID();
  window.scrollTo({ top: 0, behavior: 'smooth' });
}
</script>
<template>
  <Shell
    ><Head title="Create" />
    <main class="studio-main">
      <section class="page-heading">
        <div>
          <div class="eyebrow">FROM A THOUGHT TO A THOUSAND PIXELS</div>
          <h1>What do you <em>imagine?</em></h1>
          <p>Describe it. Make it. See where it takes you.</p>
        </div>
        <span class="studio-label"><span class="dot"></span> YOUR PERSONAL STUDIO</span>
      </section>
      <div class="workspace">
        <section class="composer card">
          <div class="section-title">
            <h2>Create an image</h2>
            <span>01 / THE IDEA</span>
          </div>
          <form @submit.prevent="submit">
            <label for="prompt">Your prompt</label>
            <div class="prompt-wrap">
              <textarea
                id="prompt"
                v-model="form.prompt"
                placeholder="A sunlit room with a single olive tree, soft shadows, warm terracotta, shot on film…"
                maxlength="4000"
                required
                rows="6"
              ></textarea
              ><span>{{ Array.from(form.prompt).length }} / 4,000</span>
            </div>
            <div class="field-group">
              <label>Canvas size</label>
              <div class="size-options">
                <button
                  v-for="option in [
                    { value: '1024x1024', name: 'Square', shape: 'square' },
                    { value: '1536x1024', name: 'Landscape', shape: 'landscape' },
                    { value: '1024x1536', name: 'Portrait', shape: 'portrait' },
                  ]"
                  :key="option.value"
                  type="button"
                  :class="{ selected: form.size === option.value }"
                  :aria-pressed="form.size === option.value"
                  @click="form.size = option.value"
                >
                  <i :class="option.shape"></i><b>{{ option.name }}</b
                  ><small>{{ option.value.replace('x', ' × ') }}</small>
                </button>
              </div>
            </div>
            <div class="field-group">
              <label for="quality"
                >Quality <span class="label-note">More detail, more tokens</span></label
              ><select id="quality" v-model="form.quality">
                <option value="low">Low — quick explorations</option>
                <option value="medium">Medium — a little more detail</option>
                <option value="high">High — the finishing touch</option>
              </select>
            </div>
            <details class="references">
              <summary>Reference images <span>Optional · up to 4</span></summary>
              <label for="references" class="hint"
                >One public HTTP or HTTPS image URL per line.</label
              ><textarea
                id="references"
                v-model="references"
                rows="3"
                placeholder="https://example.com/reference.jpg"
              ></textarea>
            </details>
            <div class="quote-panel" aria-live="polite">
              <div>
                <span>Estimated total</span
                ><strong>{{
                  quoting ? 'Calculating…' : quote ? money(quote.estimate) : '—'
                }}</strong>
              </div>
              <p v-if="quote">
                Up to {{ money(quote.maximum) }} reserved at current rates. Final charge uses actual
                tokens and the provider’s creation-time rates, plus 25%.
              </p>
              <p v-else>
                {{ quoteError || 'Add a prompt to see your estimate before generating.' }}
              </p>
              <p>Failed generations are free. Unused reserved credits return automatically.</p>
            </div>
            <div v-if="Object.keys(form.errors).length" class="error-box" role="alert">
              <p v-for="error in form.errors" :key="error">{{ error }}</p>
            </div>
            <p v-if="quote && !canAfford" class="credit-notice">
              Add demo credits using “Buy credits” above to cover the reservation.
            </p>
            <button
              class="button primary generate"
              :disabled="form.processing || !quote || !canAfford"
            >
              {{ form.processing ? 'Adding to your queue…' : 'Generate image' }}<span>↗</span>
            </button>
            <p class="form-footnote">No real payments. Every token accounted for.</p>
          </form>
        </section>
        <section class="history">
          <div class="section-title history-heading">
            <div>
              <h2>
                Your creations <span class="count">{{ generations.total }}</span>
              </h2>
              <p>A little collection of your imagination.</p>
            </div>
            <span class="live"
              ><span class="dot"></span
              >{{ active ? `${active} in progress` : 'Updates live' }}</span
            >
          </div>
          <div v-if="!generations.data.length" class="empty-state">
            <div class="empty-art" aria-hidden="true">
              <div></div>
              <i></i><b>✳</b>
            </div>
            <div class="eyebrow">A BLANK CANVAS, FOR NOW</div>
            <h3>Good things start<br />with a little curiosity.</h3>
            <p>
              Your images will appear here as they’re ready.<br />Try a scene, a mood, or something
              unexpected.
            </p>
            <button
              class="text-button"
              @click="
                form.prompt =
                  'A quiet sculpture garden at sunrise, warm stone, soft green foliage [succeed]'
              "
            >
              Try a little inspiration ↗
            </button>
          </div>
          <div v-else class="generation-grid">
            <article v-for="g in generations.data" :key="g.id" class="generation-card">
              <div class="image-frame" :class="g.status">
                <img
                  v-if="g.image_url && !g.image_expired && !brokenImages.has(g.id)"
                  :src="g.image_url"
                  :alt="g.prompt"
                  loading="lazy"
                  referrerpolicy="no-referrer"
                  @error="brokenImages.add(g.id)"
                />
                <div v-else class="image-placeholder">
                  <span
                    v-if="g.status === 'processing' || g.status === 'queued'"
                    class="spinner"
                  ></span
                  ><span v-else class="placeholder-symbol">{{
                    g.status === 'failed' ? '↻' : '◈'
                  }}</span
                  ><b>{{
                    g.status === 'failed'
                      ? 'A fresh start?'
                      : g.status === 'succeeded'
                        ? 'Image link expired'
                        : g.needs_review
                          ? 'Checking your request'
                          : g.status === 'processing'
                            ? 'Bringing it to life'
                            : 'An idea in the queue'
                  }}</b
                  ><small>{{
                    g.status === 'failed'
                      ? 'No credits charged'
                      : g.status === 'succeeded'
                        ? 'Provider images are available for 24 hours.'
                        : 'You can leave this page. We’ll keep working.'
                  }}</small>
                </div>
                <span class="status-badge" :class="g.status">{{ g.status }}</span>
              </div>
              <div class="generation-info">
                <p class="generation-prompt" :title="g.prompt">{{ g.prompt }}</p>
                <div class="generation-meta">
                  <span>{{ g.quality }} · {{ g.size.replace('x', ' × ') }}</span
                  ><time :datetime="g.created_at">{{
                    new Date(g.created_at).toLocaleDateString(undefined, {
                      month: 'short',
                      day: 'numeric',
                    })
                  }}</time>
                </div>
                <p
                  v-if="g.message"
                  class="generation-message"
                  :class="{ failure: g.status === 'failed' }"
                >
                  {{ g.message }}
                </p>
                <div class="cost-row">
                  <div>
                    <small>Estimate</small
                    ><span :title="g.estimated_cost">{{ money(g.estimated_cost) }}</span>
                  </div>
                  <div>
                    <small>Actual</small
                    ><strong :title="g.actual_cost || 'Awaiting final usage'">{{
                      money(g.actual_cost)
                    }}</strong>
                  </div>
                  <button class="reuse" @click="reuse(g)" title="Reuse this prompt">↗</button>
                </div>
              </div>
            </article>
          </div>
          <nav v-if="generations.total > 12" class="pagination" aria-label="History pages">
            <template v-for="(link, i) in generations.links" :key="i"
              ><Link
                v-if="link.url"
                :href="link.url"
                preserve-scroll
                :class="{ active: link.active }"
                >{{ link.label.replace(/&laquo;|&raquo;/g, '').trim() }}</Link
              ><span v-else>{{ link.label.replace(/&laquo;|&raquo;/g, '').trim() }}</span></template
            >
          </nav>
        </section>
      </div>
    </main></Shell
  >
</template>
