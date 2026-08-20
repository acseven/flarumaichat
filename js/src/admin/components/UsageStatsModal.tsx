import app from 'flarum/admin/app';
import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

import { cached, chart, loadStats, number, series, short, stamp, Stats } from '../usage';

export default class UsageStatsModal extends Modal {
  stats: Stats | null = cached;
  loading = !cached;
  confirmingReset = false;

  oninit(vnode: any) {
    super.oninit(vnode);
    this.load();
  }

  className() {
    return 'ChatGptUsageModal Modal--medium';
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
      <div className="Modal-body ChatGptUsage">
        <div className="ChatGptUsage-cards">
          {this.card('requests', short(api.requests))}
          {this.card('total_tokens', short(tokens))}
          {this.card('answers', short(posts ? posts.answers : 0))}
          {this.card('failures', short(api.failures), api.failures > 0)}
        </div>

        {chart(
          series(this.stats.answers_daily, (value) => Number(value)),
          app.translator.trans('wszdb-flarumaichat.admin.usage.chart_answers')
        )}
        {chart(
          series(this.stats.daily, (day) => (day.prompt_tokens || 0) + (day.completion_tokens || 0)),
          app.translator.trans('wszdb-flarumaichat.admin.usage.chart_tokens')
        )}

        <div className="ChatGptUsage-columns">
          {this.rows('api_heading', [
            ['model', this.stats.model || '—'],
            ['prompt_tokens', number(api.prompt_tokens)],
            ['completion_tokens', number(api.completion_tokens)],
            ['cached_tokens', number(api.cached_tokens || 0)],
            [
              'cache_hit',
              api.prompt_tokens ? Math.round(((api.cached_tokens || 0) / api.prompt_tokens) * 100) + '%' : '—',
            ],
            ['avg_tokens', api.requests ? number(Math.round(tokens / api.requests)) : '—'],
            ['since', stamp(api.since)],
            ['last', stamp(api.last)],
          ])}
          {posts &&
            this.rows('posts_heading', [
              ['answers_7d', number(posts.answers_7d)],
              ['answers_30d', number(posts.answers_30d)],
              ['discussions', number(posts.discussions)],
              ['avg_length', number(posts.avg_length)],
              ['first_answer', stamp(posts.first_at)],
              ['last_answer', stamp(posts.last_at)],
            ])}
        </div>

        <p className="helpText">{app.translator.trans('wszdb-flarumaichat.admin.usage.help')}</p>
        <Button className="Button Button--danger Button--text" onclick={() => this.reset()}>
          {app.translator.trans(
            this.confirmingReset ? 'wszdb-flarumaichat.admin.usage.reset_confirm' : 'wszdb-flarumaichat.admin.usage.reset'
          )}
        </Button>
      </div>
    );
  }

  card(key: string, value: string, warn = false) {
    return (
      <div className={'ChatGptUsage-card' + (warn ? ' ChatGptUsage-card--warn' : '')}>
        <div className="ChatGptUsage-cardValue">{value}</div>
        <div className="ChatGptUsage-cardLabel">{app.translator.trans('wszdb-flarumaichat.admin.usage.' + key)}</div>
      </div>
    );
  }

  rows(headingKey: string, rows: [string, string][]) {
    return (
      <div className="ChatGptUsage-column">
        <h4>{app.translator.trans('wszdb-flarumaichat.admin.usage.' + headingKey)}</h4>
        {rows.map(([key, value]) => (
          <div className="ChatGptUsage-row">
            <span>{app.translator.trans('wszdb-flarumaichat.admin.usage.' + key)}</span>
            <span>{value}</span>
          </div>
        ))}
      </div>
    );
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
    // a spinner only when there is nothing to show yet
    this.loading = !this.stats;

    loadStats(reset)
      .then((stats) => {
        this.stats = stats;
        this.loading = false;
      })
      .catch(() => {
        this.loading = false;
      });
  }
}
