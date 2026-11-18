<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type { Auth } from '../types';
import { money } from '../types';
const page = usePage();
const auth = computed(() => page.props.auth as Auth | null);
const success = computed(() => (page.props.flash as { success?: string })?.success);
const buying = ref(false);
let purchaseId = crypto.randomUUID();
function buy() {
  buying.value = true;
  router.post(
    '/credits',
    { purchase_id: purchaseId },
    {
      preserveScroll: true,
      onSuccess: () => {
        purchaseId = crypto.randomUUID();
      },
      onFinish: () => {
        buying.value = false;
      },
    },
  );
}
</script>
<template>
  <div class="app-shell">
    <header class="topbar">
      <Link href="/studio" class="brand"
        ><img src="/favicon.svg" width="32" height="32" alt="" /><span
          >pixelforge<span class="brand-sub"> / studio</span></span
        ></Link
      >
      <nav v-if="auth" aria-label="Main navigation">
        <Link href="/studio" :class="{ active: page.url.startsWith('/studio') }">Create</Link
        ><Link
          v-if="auth.is_admin"
          href="/admin/reports"
          :class="{ active: page.url.startsWith('/admin') }"
          >Reports</Link
        >
      </nav>
      <div v-if="auth" class="account">
        <div class="wallet" :title="`Balance ${auth.balance} USD; reserved ${auth.reserved} USD`">
          <span class="dot"></span>{{ money(auth.available) }}<small> available</small>
        </div>
        <button class="button small secondary" :disabled="buying" @click="buy">
          {{ buying ? 'Adding…' : '+ Buy credits' }}</button><button
          class="signout"
          @click="router.post('/logout')"
          title="Sign out"
        >
          Sign out
        </button>
      </div>
    </header>
    <div v-if="success" role="status" class="toast">{{ success }}</div>
    <slot />
    <footer>
      <span>PIXELFORGE STUDIO</span><span>Simulated images. Real attention to the details.</span
      ><span>USD · Provider cost + 25%</span>
    </footer>
  </div>
</template>
