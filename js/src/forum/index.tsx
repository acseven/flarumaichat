import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import Button from 'flarum/common/components/Button';
import PostUser from 'flarum/forum/components/PostUser';
import PostControls from 'flarum/forum/utils/PostControls';

// mirrors BlockedTags::block() on the server, so the control is not offered where it would be refused
function isBlocked(discussion: any): boolean {
  const blocked: number[] = app.forum.attribute('chatGptBlockedTags') || [];

  if (!blocked.length) return false;

  // a tag the store has not loaded comes back as undefined, and a tag loaded
  // without its parent has no parent(), so every step is checked before use
  return (discussion?.tags?.() || []).some((tag: any) => {
    if (typeof tag?.id !== 'function') return false;

    if (blocked.includes(Number(tag.id()))) return true;

    const parent = typeof tag.parent === 'function' ? tag.parent() : null;

    return typeof parent?.id === 'function' && blocked.includes(Number(parent.id()));
  });
}

function triggerReply(post: any) {
  app.alerts.show({ type: 'warning' }, app.translator.trans('wszdb-flarumaichat.forum.post_controls.trigger_started'));

  app
    .request({
      method: 'POST',
      url: app.forum.attribute('apiUrl') + '/chatgpt/reply/' + post.id(),
    })
    // the answer is a new post at the end of the stream, easiest way to show it is a reload
    .then(() => window.location.reload());
}

app.initializers.add('wszdb-flarumaichat', () => {
  extend(PostUser.prototype, 'view', function (this: any, view: any) {
    const post = this.attrs?.post;
    const user = post?.user();

    // hidden (soft-deleted) posts collapse, the absolutely-positioned badge would float over the next post
    if (!user || post.isHidden() || app.forum.attribute('chatGptUserPromptId') !== user.id()) return;

    if (view.children && Array.isArray(view.children)) {
      view.children.push(
        <div className="UserPromo-badge">
          <div className="badge">{app.forum.attribute('chatGptBadgeText')}</div>
        </div>
      );
    }
  });

  extend(PostControls, 'moderationControls', function (items: any, post: any) {
    if (!app.forum.attribute('canTriggerChatGptAssistant')) return;
    if (post.contentType() !== 'comment' || post.isHidden()) return;
    if (isBlocked(post.discussion())) return;

    items.add(
      'triggerChatGptAssistant',
      <Button icon="fas fa-robot" onclick={() => triggerReply(post)}>
        {app.translator.trans('wszdb-flarumaichat.forum.post_controls.trigger_assistant')}
      </Button>
    );
  });
});
