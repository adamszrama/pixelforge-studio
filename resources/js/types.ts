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
  reference_images: string[];
  download_url: string;
  image_url_expires_at: string | null;
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

// Display only: exact accounting remains on the server. Full values are available in details.
export const money = (amount: string | null, digits?: number): string => {
  if (amount === null) return '—';
  if (digits === 12) return `$${amount}`;
  const value = Number(amount);
  if (value === 0) return '$0.00';
  if (digits !== undefined) return `$${value.toFixed(digits)}`;
  if (Math.abs(value) < 0.0001) return value < 0 ? '−<$0.0001' : '<$0.0001';
  return `$${value.toFixed(Math.abs(value) < 1 ? 4 : 2)}`;
};
