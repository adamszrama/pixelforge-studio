<script setup lang="ts">
import { money } from '../types';
defineProps<{ amount: string | null; exact?: boolean }>();
</script>
<template>
  <span class="money-amount" :title="amount === null ? 'Not available yet' : `$${amount} USD`">{{
    money(amount, exact ? 12 : undefined)
  }}</span>
</template>
