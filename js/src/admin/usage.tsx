import app from 'flarum/admin/app';

export interface Stats {
  api: {
    requests: number;
    failures: number;
    prompt_tokens: number;
    completion_tokens: number;
    cached_tokens?: number;
    since: number;
    last: number;
  };
  daily: Record<string, { requests: number; failures: number; prompt_tokens: number; completion_tokens: number }>;
  posts: {
    answers: number;
    answers_7d: number;
    answers_30d: number;
    discussions: number;
    first_at: string;
    last_at: string;
    avg_length: number;
  } | null;
  answers_daily: Record<string, number>;
  model: string;
}

export interface Point {
  date: string;
  value: number;
}

// ponytail: booting Flarum for the API call costs about half a second, so the
// last answer is kept and shown while the next one is on its way.
export let cached: Stats | null = null;

export function loadStats(reset = false): Promise<Stats> {
  return app
    .request<Stats>({
      method: reset ? 'DELETE' : 'GET',
      url: app.forum.attribute('apiUrl') + '/chatgpt/stats',
    })
    .then((stats) => (cached = stats));
}

export function number(value: number): string {
  return Number(value || 0).toLocaleString();
}

export function short(value: number): string {
  const n = Number(value || 0);

  if (n >= 1000000) return (n / 1000000).toFixed(1).replace('.0', '') + 'M';
  if (n >= 1000) return (n / 1000).toFixed(1).replace('.0', '') + 'K';

  return String(n);
}

export function stamp(value: number | string | null): string {
  if (!value) return '—';

  // the API sends unix seconds for its own counters and MySQL datetimes (UTC) for posts
  const date = typeof value === 'number' ? new Date(value * 1000) : new Date(String(value).replace(' ', 'T') + 'Z');

  return date.toLocaleString();
}

/**
 * The last `days` days, in UTC, with the days nothing happened filled in.
 */
export function series(values: Record<string, any>, read: (day: any) => number, days = 30): Point[] {
  const points: Point[] = [];
  const day = new Date();

  day.setUTCDate(day.getUTCDate() - (days - 1));

  for (let i = 0; i < days; i++) {
    const date = day.toISOString().slice(0, 10);

    points.push({ date, value: values[date] === undefined ? 0 : read(values[date]) });
    day.setUTCDate(day.getUTCDate() + 1);
  }

  return points;
}

/**
 * A bar chart, in plain SVG: no library, no colours of its own.
 */
export function chart(points: Point[], label: string) {
  const max = Math.max(1, ...points.map((p) => p.value));
  const width = 100 / points.length;

  return (
    <div className="ChatGptUsage-chart">
      <div className="ChatGptUsage-chartHead">
        <span>{label}</span>
        <span className="ChatGptUsage-chartMax">{max > 1 || points.some((p) => p.value) ? short(max) : ''}</span>
      </div>
      <svg viewBox="0 0 100 24" preserveAspectRatio="none" role="img">
        {points.map((point, index) => {
          const height = point.value ? Math.max(1, (point.value / max) * 24) : 0;

          return (
            <rect x={index * width + width * 0.15} y={24 - height} width={width * 0.7} height={height}>
              <title>
                {point.date}: {number(point.value)}
              </title>
            </rect>
          );
        })}
      </svg>
      <div className="ChatGptUsage-chartFoot">
        <span>{points[0].date}</span>
        <span>{points[points.length - 1].date}</span>
      </div>
    </div>
  );
}
