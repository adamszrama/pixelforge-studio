import type { Totals } from './types';
export type ReportDay = Totals & {
  date: string;
  reported: string | null;
  difference: string | null;
};

export function visibleReportDays(days: ReportDay[], showAll: boolean): ReportDay[] {
  return [...days]
    .sort((a, b) => b.date.localeCompare(a.date))
    .filter(
      (day) =>
        showAll ||
        day.count > 0 ||
        Number(day.provider) !== 0 ||
        Number(day.reported) !== 0 ||
        day.difference === null ||
        Number(day.difference) !== 0,
    );
}
