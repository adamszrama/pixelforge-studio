export type Auth = {
  name: string;
  is_admin: boolean;
  balance: string;
  reserved: string;
  available: string;
};
export type Generation = {
  id: string;
  prompt: string;
  status: 'queued' | 'processing' | 'succeeded' | 'failed';
  size: string;
  quality: string;
  estimated_cost: string;
  actual_cost: string | null;
  image_url: string | null;
  image_expired: boolean;
  message: string | null;
  needs_review: boolean;
  created_at: string;
};
export type Pagination<T> = {
  data: T[];
  links: { url: string | null; label: string; active: boolean }[];
  total: number;
};
export type Totals = { count: number; spend: string; provider: string; profit: string };

// Display only: accounting and every charge are calculated exactly on the server.
export const money = (amount: string | null, digits = 6) =>
  amount === null ? '—' : digits === 12 ? `$${amount}` : `$${Number(amount).toFixed(digits)}`;
