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

    return [
      <div className="ChatGptUsage-cards">
        <div className="ChatGptUsage-card">
          <div className="ChatGptUsage-cardValue">{short(stats.posts ? stats.posts.answers : 0)}</div>
          <div className="ChatGptUsage-cardLabel">{app.translator.trans('wszdb-flarumaichat.admin.usage.answers')}</div>
        </div>
        <div className="ChatGptUsage-card">
          <div className="ChatGptUsage-cardValue">{short(stats.api.requests)}</div>
          <div className="ChatGptUsage-cardLabel">{app.translator.trans('wszdb-flarumaichat.admin.usage.requests')}</div>
        </div>
        <div className="ChatGptUsage-card">
          <div className="ChatGptUsage-cardValue">{short(tokens)}</div>
          <div className="ChatGptUsage-cardLabel">{app.translator.trans('wszdb-flarumaichat.admin.usage.total_tokens')}</div>
        </div>
        <div className={'ChatGptUsage-card' + (stats.api.failures ? ' ChatGptUsage-card--warn' : '')}>
          <div className="ChatGptUsage-cardValue">{short(stats.api.failures)}</div>
          <div className="ChatGptUsage-cardLabel">{app.translator.trans('wszdb-flarumaichat.admin.usage.failures')}</div>
        </div>
      </div>,
      chart(
        series(stats.answers_daily, (value) => Number(value)),
        app.translator.trans('wszdb-flarumaichat.admin.usage.chart_answers')
      ),
    ];
  }
}
