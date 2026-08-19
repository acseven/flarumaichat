import app from 'flarum/admin/app';
import ExtensionPage, { ExtensionPageAttrs } from 'flarum/admin/components/ExtensionPage';
import Button from 'flarum/common/components/Button';
import Checkbox from 'flarum/common/components/Checkbox';
import Group from 'flarum/common/models/Group';
import UsageStatsModal from './UsageStatsModal';

// Fallback models in case cached models are not available
const FALLBACK_MODELS = [
  'gpt-4.5-preview',
  'gpt-4o',
  'gpt-4o-mini',
  'gpt-4-turbo',
  'gpt-4',
  'gpt-3.5-turbo',
  'gpt-3.5-turbo-instruct',
  'o1-preview',
  'o1-mini',
  'chatgpt-4o-latest',
];

export default class ChatGptSettings extends ExtensionPage {
  loading!: boolean;
  isFetchingModels!: boolean;
  models!: Record<string, string>;

  oninit(vnode: any) {
    super.oninit(vnode);
    this.loading = false;
    this.isFetchingModels = false;
    this.models = this.getModels();
  }

  getModels() {
    try {
      const cachedModels = app.data.settings['wszdb-flarumaichat.cached_models'];
      if (cachedModels && cachedModels !== '[]') {
        const parsed = JSON.parse(cachedModels);
        if (Array.isArray(parsed) && parsed.length > 0) {
          return parsed.reduce((acc, model) => {
            acc[model.id] = model.id;
            return acc;
          }, {});
        }
      }
    } catch (e) {
      console.error('Failed to parse cached models:', e);
    }

    // Return fallback models
    return FALLBACK_MODELS.reduce((acc, modelId) => {
      acc[modelId] = modelId;
      return acc;
    }, {} as Record<string, string>);
  }

  fetchModels() {
    this.isFetchingModels = true;

    app
      .request<{ models: any[]; count: number; last_fetched: number }>({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/chatgpt/fetch-models',
        // test what is typed in the form, not what was saved last
        body: {
          api_key: this.setting('wszdb-flarumaichat.api_key')(),
          base_uri: this.setting('wszdb-flarumaichat.base_uri')(),
        },
      })
      .then(
        (response) => {
          this.isFetchingModels = false;

          // Update cached models in settings
          app.data.settings['wszdb-flarumaichat.cached_models'] = JSON.stringify(response.models);
          app.data.settings['wszdb-flarumaichat.models_last_fetched'] = response.last_fetched.toString();

          // Refresh models list
          this.models = this.getModels();

          app.alerts.show(
            {
              type: 'success',
            },
            app.translator.trans('wszdb-flarumaichat.admin.settings.models_fetched_success', {
              count: response.count,
            })
          );

          // the models came from the typed key and URI: they mean nothing until those are saved
          if (this.isChanged()) {
            app.alerts.show(
              { type: 'warning' },
              app.translator.trans('wszdb-flarumaichat.admin.settings.models_fetched_unsaved')
            );
          }

          m.redraw();
        },
        (error) => {
          this.isFetchingModels = false;

          app.alerts.show(
            {
              type: 'error',
            },
            error?.response?.error || app.translator.trans('wszdb-flarumaichat.admin.settings.fetch_models_error')
          );

          m.redraw();
        }
      );
  }

  getLastFetchedText() {
    const timestamp = parseInt(app.data.settings['wszdb-flarumaichat.models_last_fetched'] || '0');

    if (timestamp === 0) {
      return app.translator.trans('wszdb-flarumaichat.admin.settings.models_never_fetched');
    }

    const date = new Date(timestamp * 1000);
    return app.translator.trans('wszdb-flarumaichat.admin.settings.models_last_fetched', {
      date: date.toLocaleString(),
    });
  }

  blockedGroups() {
    const setting = this.setting('wszdb-flarumaichat.blocked-groups', '[]');
    let selected: string[] = [];

    try {
      selected = (JSON.parse(setting() || '[]') as any[]).map(String);
    } catch (e) {
      // a hand-edited setting row: start from nothing rather than break the page
    }

    return (
      <div className="Form-group">
        <label>{app.translator.trans('wszdb-flarumaichat.admin.settings.blocked_groups_label')}</label>
        <div className="helpText">{app.translator.trans('wszdb-flarumaichat.admin.settings.blocked_groups_help')}</div>
        <div className="ChatGptSettings-groups">
          {app.store
            .all<Group>('groups')
            .filter((group) => group.id() !== Group.GUEST_ID)
            .map((group) => (
              <Checkbox
                state={selected.includes(group.id()!)}
                onchange={(checked: boolean) =>
                  setting(JSON.stringify(checked ? [...selected, group.id()!] : selected.filter((id) => id !== group.id()!)))
                }
              >
                {group.namePlural()}
              </Checkbox>
            ))}
        </div>
      </div>
    );
  }

  content() {
    return (
      <div className="ExtensionPage-settings">
        <div className="container">
          <div className="Form">
            <div className="Form-group">
              <Button className="Button" icon="fas fa-chart-column" onclick={() => app.modal.show(UsageStatsModal)}>
                {app.translator.trans('wszdb-flarumaichat.admin.usage.button')}
              </Button>
            </div>
            {this.buildSettingComponent({
              setting: 'wszdb-flarumaichat.api_key',
              type: 'text',
              label: app.translator.trans('wszdb-flarumaichat.admin.settings.api_key_label'),
              help: app.translator.trans('wszdb-flarumaichat.admin.settings.api_key_help', {
                a: <a href="https://platform.openai.com/account/api-keys" target="_blank" rel="noopener" />,
              }),
              placeholder: 'sk-...',
            })}
            {this.buildSettingComponent({
              setting: 'wszdb-flarumaichat.base_uri',
              type: 'text',
              label: app.translator.trans('wszdb-flarumaichat.admin.settings.base_uri_label'),
              help: app.translator.trans('wszdb-flarumaichat.admin.settings.base_uri_help'),
              placeholder: 'api.openai.com',
            })}
            {this.buildSettingComponent({
              setting: 'wszdb-flarumaichat.model',
              type: 'dropdown',
              options: this.models,
              label: app.translator.trans('wszdb-flarumaichat.admin.settings.model_label'),
              help: app.translator.trans('wszdb-flarumaichat.admin.settings.model_help', {
                a: <a href="https://platform.openai.com/docs/models/overview" target="_blank" rel="noopener" />,
              }),
            })}
            <div className="Form-group">
              <label>{app.translator.trans('wszdb-flarumaichat.admin.settings.fetch_models_label')}</label>
              <div>
                <Button
                  className="Button Button--primary"
                  onclick={() => this.fetchModels()}
                  loading={this.isFetchingModels}
                  disabled={this.isFetchingModels}
                >
                  {app.translator.trans('wszdb-flarumaichat.admin.settings.fetch_models_button')}
                </Button>
                <p className="helpText">{this.getLastFetchedText()}</p>
              </div>
            </div>
            {this.buildSettingComponent({
              setting: 'wszdb-flarumaichat.max_tokens',
              type: 'number',
              label: app.translator.trans('wszdb-flarumaichat.admin.settings.max_tokens_label'),
              help: app.translator.trans('wszdb-flarumaichat.admin.settings.max_tokens_help', {
                a: <a href="https://help.openai.com/en/articles/4936856" target="_blank" rel="noopener" />,
              }),
              default: 100,
            })}
            {/* new setting for moderation */}
            {this.buildSettingComponent({
              setting: 'wszdb-flarumaichat.moderation',
              type: 'boolean',
              label: app.translator.trans('wszdb-flarumaichat.admin.settings.moderation_label'),
              help: app.translator.trans('wszdb-flarumaichat.admin.settings.moderation_help'),
            })}
            {this.buildSettingComponent({
              setting: 'wszdb-flarumaichat.user_prompt',
              type: 'text',
              label: app.translator.trans('wszdb-flarumaichat.admin.settings.user_prompt_label'),
              help: app.translator.trans('wszdb-flarumaichat.admin.settings.user_prompt_help'),
            })}
            {/* new settings for role */}
            {this.buildSettingComponent({
              setting: 'wszdb-flarumaichat.role',
              type: 'text',
              label: app.translator.trans('wszdb-flarumaichat.admin.settings.role_label'),
              help: app.translator.trans('wszdb-flarumaichat.admin.settings.role_help'),
            })}
            {/* new settings for prompt */}
            {this.buildSettingComponent({
              setting: 'wszdb-flarumaichat.prompt',
              type: 'text',
              label: app.translator.trans('wszdb-flarumaichat.admin.settings.prompt_label'),
              help: app.translator.trans('wszdb-flarumaichat.admin.settings.prompt_help'),
            })}
            {this.buildSettingComponent({
              setting: 'wszdb-flarumaichat.user_prompt_badge_text',
              type: 'text',
              label: app.translator.trans('wszdb-flarumaichat.admin.settings.user_prompt_badge_label'),
              help: app.translator.trans('wszdb-flarumaichat.admin.settings.user_prompt_badge_help'),
            })}
            {this.buildSettingComponent({
              setting: 'wszdb-flarumaichat.reply_in_private',
              type: 'boolean',
              label: app.translator.trans('wszdb-flarumaichat.admin.settings.reply_in_private_label'),
              help: app.translator.trans('wszdb-flarumaichat.admin.settings.reply_in_private_help'),
            })}
            {/*new setting for queue_active */}
            {this.buildSettingComponent({
              setting: 'wszdb-flarumaichat.queue_active',
              type: 'boolean',
              label: app.translator.trans('wszdb-flarumaichat.admin.settings.queue_active_label'),
              help: app.translator.trans('wszdb-flarumaichat.admin.settings.queue_active_help'),
            })}
            {this.buildSettingComponent({
              setting: 'wszdb-flarumaichat.answer_duration',
              type: 'number',
              label: app.translator.trans('wszdb-flarumaichat.admin.settings.answer_duration_label'),
            })}
            {this.buildSettingComponent({
              setting: 'wszdb-flarumaichat.answer_delay',
              type: 'number',
              label: app.translator.trans('wszdb-flarumaichat.admin.settings.answer_delay_label'),
              help: app.translator.trans('wszdb-flarumaichat.admin.settings.answer_delay_help'),
            })}
            {this.buildSettingComponent({
              setting: 'wszdb-flarumaichat.enable_on_reply',
              type: 'boolean',
              label: app.translator.trans('wszdb-flarumaichat.admin.settings.enable_on_reply_label'),
            })}
            {this.buildSettingComponent({
              setting: 'wszdb-flarumaichat.enable_on_discussion_started',
              type: 'boolean',
              label: app.translator.trans('wszdb-flarumaichat.admin.settings.enable_on_discussion_started_label'),
              help: app.translator.trans('wszdb-flarumaichat.admin.settings.enable_on_discussion_started_help'),
            })}
            {this.buildSettingComponent({
              setting: 'wszdb-flarumaichat.continue_to_reply',
              type: 'boolean',
              label: app.translator.trans('wszdb-flarumaichat.admin.settings.continue_to_reply_label'),
              help: app.translator.trans('wszdb-flarumaichat.admin.settings.continue_to_reply_help'),
            })}
            {this.buildSettingComponent({
              setting: 'wszdb-flarumaichat.continue_to_reply_count',
              type: 'number',
              label: app.translator.trans('wszdb-flarumaichat.admin.settings.continue_to_reply_count_label'),
              help: app.translator.trans('wszdb-flarumaichat.admin.settings.continue_to_reply_count_help'),
            })}
            {this.buildSettingComponent({
              type: 'flarum-tags.select-tags',
              setting: 'wszdb-flarumaichat.blocked-tags',
              label: app.translator.trans('wszdb-flarumaichat.admin.settings.blocked_tags_label'),
              help: app.translator.trans('wszdb-flarumaichat.admin.settings.blocked_tags_help'),
              options: {
                requireParentTag: false,
                limits: { max: { secondary: 0 } },
              },
            })}
            {this.blockedGroups()}
            {this.buildSettingComponent({
              type: 'flarum-tags.select-tags',
              setting: 'wszdb-flarumaichat.enabled-tags',
              label: app.translator.trans('wszdb-flarumaichat.admin.settings.enabled_tags_label'),
              help: app.translator.trans('wszdb-flarumaichat.admin.settings.enabled_tags_help'),
              options: {
                requireParentTag: false,
                limits: {
                  max: {
                    secondary: 0,
                  },
                },
              },
            })}
            <h3 className="ChatGptSettings-heading">
              <i className="fas fa-database" /> {app.translator.trans('wszdb-flarumaichat.admin.settings.context_heading')}
            </h3>
            {this.buildSettingComponent({
              setting: 'wszdb-flarumaichat.context_files',
              type: 'textarea',
              label: app.translator.trans('wszdb-flarumaichat.admin.settings.context_files_label'),
              help: app.translator.trans('wszdb-flarumaichat.admin.settings.context_files_help'),
            })}
            {this.buildSettingComponent({
              setting: 'wszdb-flarumaichat.context_chars',
              type: 'number',
              label: app.translator.trans('wszdb-flarumaichat.admin.settings.context_chars_label'),
              help: app.translator.trans('wszdb-flarumaichat.admin.settings.context_chars_help'),
              default: 6000,
            })}
            <h3 className="ChatGptSettings-heading">
              <i className="fas fa-robot" /> {app.translator.trans('wszdb-flarumaichat.admin.settings.zai_heading')}
            </h3>
            {this.buildSettingComponent({
              setting: 'wszdb-flarumaichat.glm_thinking',
              type: 'boolean',
              label: app.translator.trans('wszdb-flarumaichat.admin.settings.glm_thinking_label'),
              help: app.translator.trans('wszdb-flarumaichat.admin.settings.glm_thinking_help'),
            })}
            {this.buildSettingComponent({
              setting: 'wszdb-flarumaichat.web_search',
              type: 'boolean',
              label: app.translator.trans('wszdb-flarumaichat.admin.settings.web_search_label'),
              help: app.translator.trans('wszdb-flarumaichat.admin.settings.web_search_help'),
            })}
            {this.buildSettingComponent({
              setting: 'wszdb-flarumaichat.web_search_domains',
              type: 'textarea',
              label: app.translator.trans('wszdb-flarumaichat.admin.settings.web_search_domains_label'),
              help: app.translator.trans('wszdb-flarumaichat.admin.settings.web_search_domains_help'),
            })}
            <div className="Form-group">{this.submitButton()}</div>
          </div>
        </div>
      </div>
    );
  }
}
