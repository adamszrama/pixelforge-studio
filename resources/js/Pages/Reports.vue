<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import Shell from '../Components/Shell.vue';
import { money, type Totals } from '../types';
type Day = Totals & { date: string; reported: string | null; difference: string | null };
const props = defineProps<{
  days: Day[];
  users: (Totals & { name: string; email: string; days: (Totals & { date: string })[] })[];
  totals: Totals;
  start: string;
  end: string;
  error: string | null;
  reviewCount: number;
}>();
const start = ref(props.start),
  end = ref(props.end);
function filter() {
  router.get('/admin/reports', { start: start.value, end: end.value }, { preserveState: true });
}
</script>
<template>
  <Shell
    ><Head title="Operator report" />
    <main class="report-main">
      <div class="eyebrow">THE NUMBERS BEHIND THE PICTURES</div>
      <h1>Every token, <em>accounted for.</em></h1>
      <p>
        Revenue, provider costs and profit by completion day in UTC. Amounts shown to all 12 decimal
        places.
      </p>
      <form class="date-filter" @submit.prevent="filter">
        <label>From<input v-model="start" type="date" required /></label
        ><label>Through<input v-model="end" type="date" required /></label
        ><button class="button primary">Update report</button>
      </form>
      <div v-if="error" role="alert" class="error-box">{{ error }}</div>
      <div v-if="reviewCount" class="error-box">
        {{ reviewCount }} request(s) need operator review. Inspect pending requests and use the
        documented reconciliation command.
      </div>
      <div class="report-stats">
        <div
          v-for="(label, key) in {
            spend: 'User charges',
            provider: 'Provider costs',
            profit: 'Gross profit',
          }"
          :key="key"
          class="card"
        >
          <span>{{ label }}</span><strong :title="totals[key]">{{ money(totals[key], 9) }}</strong
          ><small>{{ totals.count }} successful generations</small>
        </div>
      </div>
      <section class="card report-table">
        <h2>Daily reconciliation</h2>
        <p>
          Provider totals are fetched from this environment’s API key. A nonzero difference needs
          investigation; in-flight updates may briefly differ.
        </p>
        <div class="table-scroll">
          <table>
            <thead>
              <tr>
                <th>Day (UTC)</th>
                <th>User charges</th>
                <th>Local provider cost</th>
                <th>Provider /usage</th>
                <th>Difference</th>
                <th>Profit</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="day in days" :key="day.date">
                <td>{{ day.date }}</td>
                <td>{{ money(day.spend, 12) }}</td>
                <td>{{ money(day.provider, 12) }}</td>
                <td>{{ money(day.reported, 12) }}</td>
                <td :class="{ failure: day.difference !== null && Number(day.difference) !== 0 }">
                  {{ money(day.difference, 12) }}
                </td>
                <td>{{ money(day.profit, 12) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
      <section class="card report-table">
        <h2>By user</h2>
        <p v-if="!users.length">No completed generations in this date range.</p>
        <details v-for="user in users" :key="user.email" class="user-report">
          <summary>
            <span>{{ user.name }} · {{ user.email }}</span
            ><strong>{{ money(user.spend, 12) }} charged</strong>
          </summary>
          <div class="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Day (UTC)</th>
                  <th>Images</th>
                  <th>User charges</th>
                  <th>Provider cost</th>
                  <th>Profit</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="day in user.days" :key="day.date">
                  <td>{{ day.date }}</td>
                  <td>{{ day.count }}</td>
                  <td>{{ money(day.spend, 12) }}</td>
                  <td>{{ money(day.provider, 12) }}</td>
                  <td>{{ money(day.profit, 12) }}</td>
                </tr>
                <tr>
                  <th>Total</th>
                  <td>{{ user.count }}</td>
                  <td>{{ money(user.spend, 12) }}</td>
                  <td>{{ money(user.provider, 12) }}</td>
                  <td>{{ money(user.profit, 12) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </details>
      </section>
    </main></Shell
  >
</template>
