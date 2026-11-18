import assert from 'node:assert/strict';
import test from 'node:test';
import { money } from '../../resources/js/types.ts';
import { visibleReportDays, type ReportDay } from '../../resources/js/reporting.ts';

test('money distinguishes missing, zero, compact, and very small amounts', () => {
  assert.equal(money(null), '—');
  assert.equal(money('0.000000000000'), '$0.00');
  assert.equal(money('0.079968750000'), '$0.0800');
  assert.equal(money('19.892628125000'), '$19.89');
  assert.equal(money('0.000000000001'), '<$0.0001');
  assert.equal(money('-0.000000000001'), '−<$0.0001');
});

test('full precision keeps the original decimal string without float conversion', () => {
  assert.equal(money('0.079968750001', 12), '$0.079968750001');
});

const day = (date: string, overrides: Partial<ReportDay> = {}): ReportDay => ({
  date,
  count: 0,
  spend: '0',
  provider: '0',
  profit: '0',
  reported: '0',
  difference: '0',
  ...overrides,
});

test('report hides inactive days and sorts newest first without mutating data', () => {
  const days = [
    day('2026-11-17', { count: 1 }),
    day('2026-11-18', { count: 2 }),
    day('2026-11-16'),
  ];
  assert.deepEqual(
    visibleReportDays(days, false).map((d) => d.date),
    ['2026-11-18', '2026-11-17'],
  );
  assert.equal(days[0]?.date, '2026-11-17');
  assert.equal(visibleReportDays(days, true).length, 3);
});

test('report never hides unmatched provider costs, tiny differences, or unverified days', () => {
  const days = [
    day('2026-11-18', { reported: '0.01', difference: '-0.01' }),
    day('2026-11-17', { difference: '0.000000000001' }),
    day('2026-11-16', { difference: null, reported: null }),
  ];
  assert.equal(visibleReportDays(days, false).length, 3);
});
