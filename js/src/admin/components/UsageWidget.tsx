import app from 'flarum/admin/app';
import DashboardWidget from 'flarum/admin/components/DashboardWidget';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

import UsageStatsModal from './UsageStatsModal';
import { chart, loadStats, series, short, Stats } from '../usage';

export default class UsageWidget extends DashboardWidget {
  stats: Stats | null = null;
  loading = true;

  oninit(vnode: any) {
    super.oninit(vnode);

    loadStats()
      .then((stats) => {
        this.stats = stats;
        this.loading = false;
      })
      .catch(() => {
        this.loading = false;
      });
  }

  className() {
    return 'ChatGptUsageWidget';
  }

  content() {
    return (
      <div className="ChatGptUsage">
        <h2>{app.translator.trans('wszdb-flarumaichat.admin.usage.title')}</h2>
        {this.loading || !this.stats ? LoadingIndicator.component({ display: 'block' }) : this.figures(this.stats)}
        <div className="ChatGptUsage-more">
          <Button className="Button Button--text" onclick={() => app.modal.show(UsageStatsModal)}>
            {app.translator.trans('wszdb-flarumaichat.admin.usage.button')}
          </Button>
        </div>
      </div>
    );
  }

  figures(stats: Stats) {
    const tokens = (stats.api.prompt_tokens || 0) + (stats.api.completion_tokens || 0);

    return (
      <div className="ChatGptUsage-widgetBody">
        <div className="ChatGptUsage-cards">
          {this.card('answers', short(stats.posts ? stats.posts.answers : 0))}
          {this.card('requests', short(stats.api.requests))}
          {this.card('total_tokens', short(tokens))}
          {this.card('failures', short(stats.api.failures), stats.api.failures > 0)}
        </div>
        {chart(
          series(stats.answers_daily, (value) => Number(value)),
          app.translator.trans('wszdb-flarumaichat.admin.usage.chart_answers')
        )}
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
}
