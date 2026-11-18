<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Shell from '../Components/Shell.vue';
import MoneyAmount from '../Components/MoneyAmount.vue';
import type { Totals } from '../types';
import { visibleReportDays, type ReportDay } from '../reporting';
const props = defineProps<{
  days: ReportDay[];
  users: (Totals & { name: string; email: string; days: (Totals & { date: string })[] })[];
  totals: Totals;
  start: string;
  end: string;
  error: string | null;
  reviewCount: number;
}>();
const page = usePage();
const start = ref(props.start);
const end = ref(props.end);
const loading = ref(false);
const showAll = ref(false);
const exact = ref(false);
const visibleDays = computed(() => visibleReportDays(props.days, showAll.value));
const differences = computed(
  () => props.days.filter((day) => day.difference !== null && Number(day.difference) !== 0).length,
);
const unverified = computed(() => props.error || props.days.some((day) => day.difference === null));
const reconciliation = computed(() =>
  differences.value
    ? `${differences.value} day(s) need attention`
    : unverified.value
      ? 'Reconciliation incomplete'
      : 'All costs match',
);
function filter() {
  loading.value = true;
  router.get(
    '/admin/reports',
    { start: start.value, end: end.value },
    {
      preserveState: true,
      onFinish: () => {
        loading.value = false;
      },
    },
  );
}
</script>
<template>
  <Shell
    ><Head title="Reports" />
    <main class="report-main">
      <section class="page-heading">
        <div>
          <div class="eyebrow">WORKSPACE ANALYTICS</div>
          <h1>A clear view of <em>every dollar.</em></h1>
          <p>Charges, provider costs, and profit. All in one place.</p>
        </div>
        <span class="studio-label">USD · UTC</span>
      </section>
      <form class="date-filter card" @submit.prevent="filter">
        <label>From<input v-model="start" type="date" required /></label
        ><label>Through<input v-model="end" type="date" required /></label
        ><button class="button primary" :disabled="loading">
          {{ loading ? 'Updating…' : 'Update report' }}</button
        ><span class="filter-note">Up to 92 days · completion date in UTC</span>
      </form>
      <div v-if="Object.keys(page.props.errors).length" role="alert" class="error-box">
        <p v-for="message in page.props.errors" :key="message">{{ message }}</p>
      </div>
      <div v-if="error" role="alert" class="error-box">{{ error }}</div>
      <div v-if="reviewCount" class="error-box">
        {{ reviewCount }} request(s) need operator review. Credits remain reserved until the
        provider result is confirmed.
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
          :class="{ 'profit-card': key === 'profit' }"
        >
          <span>{{ label }}</span><strong
            ><MoneyAmount :amount="totals[key]" :exact="exact" /></strong
          ><small
            >{{ totals.count }} successful
            {{ totals.count === 1 ? 'generation' : 'generations' }}</small
          >
        </div>
      </div>
      <section class="card report-table">
        <div class="report-section-heading">
          <div>
            <h2>Daily reconciliation</h2>
            <p>Newest first. Compare recorded costs with Pixelforge.</p>
          </div>
          <span class="reconciliation-badge" :class="{ warning: differences || unverified }"
            >{{ differences || unverified ? '◷' : '✓' }} {{ reconciliation }}</span
          >
        </div>
        <div class="table-controls">
          <label class="toggle"
            ><input v-model="showAll" type="checkbox" /> Show days without activity</label
          ><label class="toggle"><input v-model="exact" type="checkbox" /> Full precision</label
          ><span>{{ visibleDays.length }} of {{ days.length }} days</span>
        </div>
        <div v-if="!visibleDays.length" class="report-empty">
          <h3>No activity in this period</h3>
          <p>Choose another date range or show days without activity.</p>
        </div>
        <div v-else class="table-scroll">
          <table>
            <thead>
              <tr>
                <th>Day (UTC)</th>
                <th>User charges</th>
                <th>Recorded cost</th>
                <th>Pixelforge cost</th>
                <th>Difference</th>
                <th>Profit</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="day in visibleDays" :key="day.date">
                <td class="day-cell">
                  {{ day.date
                  }}<small>{{ day.count }} {{ day.count === 1 ? 'image' : 'images' }}</small>
                </td>
                <td><MoneyAmount :amount="day.spend" :exact="exact" /></td>
                <td><MoneyAmount :amount="day.provider" :exact="exact" /></td>
                <td><MoneyAmount :amount="day.reported" :exact="exact" /></td>
                <td>
                  <span v-if="day.difference === null" class="table-status warning">Unverified</span
                  ><span
                    v-else-if="Number(day.difference) === 0 && !exact"
                    class="table-status matched"
                    >✓ Matched</span
                  ><MoneyAmount
                    v-else
                    :amount="day.difference"
                    :exact="exact"
                    :class="{ failure: Number(day.difference) !== 0 }"
                  />
                </td>
                <td class="profit-value"><MoneyAmount :amount="day.profit" :exact="exact" /></td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="table-footnote">
          Compact amounts are rounded for readability. Turn on full precision for exact values.
          Missing provider data is always shown.
        </p>
      </section>
      <section class="card report-table">
        <div class="report-section-heading">
          <div>
            <h2>By user</h2>
            <p>Open a user to see their daily breakdown.</p>
          </div>
          <span class="count">{{ users.length }} users</span>
        </div>
        <p v-if="!users.length" class="report-empty">
          No successful generations in this date range.
        </p>
        <details v-for="user in users" :key="user.email" class="user-report">
          <summary>
            <span class="user-identity"
              ><b class="avatar">{{ user.name.charAt(0).toUpperCase() }}</b
              ><span
                ><b>{{ user.name }}</b
                ><small>{{ user.email }}</small></span
              ></span
            ><strong
              ><MoneyAmount :amount="user.spend" :exact="exact" /> charged
              <span aria-hidden="true">⌄</span></strong
            >
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
                <tr
                  v-for="day in [...user.days].sort((a, b) => b.date.localeCompare(a.date))"
                  :key="day.date"
                >
                  <td>{{ day.date }}</td>
                  <td>{{ day.count }}</td>
                  <td><MoneyAmount :amount="day.spend" :exact="exact" /></td>
                  <td><MoneyAmount :amount="day.provider" :exact="exact" /></td>
                  <td><MoneyAmount :amount="day.profit" :exact="exact" /></td>
                </tr>
                <tr>
                  <th>Total</th>
                  <td>{{ user.count }}</td>
                  <td><MoneyAmount :amount="user.spend" :exact="exact" /></td>
                  <td><MoneyAmount :amount="user.provider" :exact="exact" /></td>
                  <td><MoneyAmount :amount="user.profit" :exact="exact" /></td>
                </tr>
              </tbody>
            </table>
          </div>
        </details>
      </section>
    </main>
  </Shell>
</template>
