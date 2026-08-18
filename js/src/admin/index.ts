import app from 'flarum/admin/app';
import ChatGptSettings from './components/ChatGptSettings';

app.initializers.add('wszdb-flarumaichat', () => {
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
