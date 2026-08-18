import app from 'flarum/admin/app';
import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

interface Stats {
  api: { requests: number; failures: number; prompt_tokens: number; completion_tokens: number; since: number; last: number };
  posts: { answers: number; answers_7d: number; answers_30d: number; discussions: number; first_at: string; last_at: string; avg_length: number } | null;
  model: string;
}

function number(value: number): string {
  return Number(value || 0).toLocaleString();
}

function stamp(value: number | string | null): string {
  if (!value) return '—';

  // the API sends unix seconds for its own counters and MySQL datetimes (UTC) for posts
  const date = typeof value === 'number' ? new Date(value * 1000) : new Date(String(value).replace(' ', 'T') + 'Z');

  return date.toLocaleString();
}

export default class UsageStatsModal extends Modal {
  stats: Stats | null = null;
  loading = true;
  confirmingReset = false;

  oninit(vnode: any) {
    super.oninit(vnode);
    this.load();
  }

  className() {
    return 'ChatGptUsageModal Modal--small';
  }

  title() {
    return app.translator.trans('wszdb-flarumaichat.admin.usage.title');
  }

  content() {
    if (this.loading || !this.stats) {
      return <div className="Modal-body">{LoadingIndicator.component({ display: 'block' })}</div>;
    }

    const { api, posts } = this.stats;
    const tokens = (api.prompt_tokens || 0) + (api.completion_tokens || 0);

    return (
      <div className="Modal-body">
        <table className="ChatGptUsageModal-table">
          {this.rows('wszdb-flarumaichat.admin.usage.api_heading', [
            ['model', this.stats.model || '—'],
            ['requests', number(api.requests)],
            ['failures', number(api.failures)],
            ['prompt_tokens', number(api.prompt_tokens)],
            ['completion_tokens', number(api.completion_tokens)],
            ['total_tokens', number(tokens)],
            ['avg_tokens', api.requests ? number(Math.round(tokens / api.requests)) : '—'],
            ['since', stamp(api.since)],
            ['last', stamp(api.last)],
          ])}
          {posts &&
            this.rows('wszdb-flarumaichat.admin.usage.posts_heading', [
              ['answers', number(posts.answers)],
              ['answers_7d', number(posts.answers_7d)],
              ['answers_30d', number(posts.answers_30d)],
              ['discussions', number(posts.discussions)],
              ['avg_length', number(posts.avg_length)],
              ['first_answer', stamp(posts.first_at)],
              ['last_answer', stamp(posts.last_at)],
            ])}
        </table>
        <p className="helpText">{app.translator.trans('wszdb-flarumaichat.admin.usage.help')}</p>
        <Button className="Button Button--danger" onclick={() => this.reset()}>
          {app.translator.trans(
            this.confirmingReset ? 'wszdb-flarumaichat.admin.usage.reset_confirm' : 'wszdb-flarumaichat.admin.usage.reset'
          )}
        </Button>
      </div>
    );
  }

  rows(headingKey: string, rows: [string, string][]) {
    return [
      <tr>
        <th colspan="2">{app.translator.trans(headingKey)}</th>
      </tr>,
      ...rows.map(([key, value]) => (
        <tr>
          <td>{app.translator.trans('wszdb-flarumaichat.admin.usage.' + key)}</td>
          <td>{value}</td>
        </tr>
      )),
    ];
  }

  reset() {
    if (!this.confirmingReset) {
      this.confirmingReset = true;
      return;
    }

    this.confirmingReset = false;
    this.load(true);
  }

  load(reset = false) {
    this.loading = true;

    app
      .request<Stats>({
        method: reset ? 'DELETE' : 'GET',
        url: app.forum.attribute('apiUrl') + '/chatgpt/stats',
      })
      .then((stats) => {
        this.stats = stats;
        this.loading = false;
      })
      .catch(() => {
        this.loading = false;
      });
  }
}
