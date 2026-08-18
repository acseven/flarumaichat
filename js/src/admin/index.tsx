import app from 'flarum/admin/app';
import { extend } from 'flarum/common/extend';
import DashboardPage from 'flarum/admin/components/DashboardPage';
import ChatGptSettings from './components/ChatGptSettings';
import UsageWidget from './components/UsageWidget';

app.initializers.add('wszdb-flarumaichat', () => {
  extend(DashboardPage.prototype, 'availableWidgets', (widgets: any) => {
    widgets.add('chatgpt-usage', <UsageWidget />, 19);
  });

  app.extensionData
    .for('wszdb-flarumaichat')
    .registerPermission(
      {
        label: app.translator.trans('wszdb-flarumaichat.admin.permissions.use_chatgpt_assistant_label'),
        icon: 'fas fa-comment',
        permission: 'discussion.useChatGPTAssistant',
        allowGuest: false,
      },
      'start'
    )
    .registerPermission(
      {
        label: app.translator.trans('wszdb-flarumaichat.admin.permissions.trigger_chatgpt_assistant_label'),
        icon: 'fas fa-robot',
        permission: 'discussion.triggerChatGPTAssistant',
        allowGuest: false,
      },
      'moderate'
    )
    .registerPage(ChatGptSettings);
});
