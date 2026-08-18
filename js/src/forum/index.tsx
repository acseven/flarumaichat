import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import PostUser from 'flarum/forum/components/PostUser';

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
});
