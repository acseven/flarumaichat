<?php

/*
 * This file is part of wszdb/flarumaichat.
 *
 * Copyright (c) 2025 wszdb.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Wszdb\FlarumAiChat;

use Flarum\Discussion\Event\Saving;
use Flarum\Discussion\Event\Started;
use Flarum\Extend;
use Flarum\Http\Middleware\HandleErrors;
use Flarum\Post\Event\Posted;
use Flarum\Api\Serializer\ForumSerializer;
use Flarum\Api\Serializer\PostSerializer;
use Wszdb\FlarumAiChat\Controller\FetchModelsController;
use Wszdb\FlarumAiChat\Controller\StatsController;
use Wszdb\FlarumAiChat\Controller\TriggerReplyController;
use Wszdb\FlarumAiChat\Listener\ReplyToCommentPost;
use Wszdb\FlarumAiChat\Listener\ReplyToPost;
use Wszdb\FlarumAiChat\Middleware\ModerationMiddleware;
use Tobscure\JsonApi\ErrorHandler;

return [
    (new Extend\Middleware('api'))
        ->add(ModerationMiddleware::class),
    (new Extend\Middleware('forum'))
        ->add(ModerationMiddleware::class),
    (new Extend\Middleware('admin'))
        ->add(ModerationMiddleware::class),

    (new Extend\Middleware('frontend'))
        ->insertBefore(HandleErrors::class, ModerationMiddleware::class),

    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/less/forum.less'),
    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js')
        ->css(__DIR__ . '/less/admin.less'),
    (new Extend\Locales(__DIR__ . '/locale')),

    (new Extend\Routes('api'))
        ->post('/chatgpt/fetch-models', 'chatgpt.fetch-models', FetchModelsController::class)
        ->post('/chatgpt/reply/{id}', 'chatgpt.reply', TriggerReplyController::class)
        ->get('/chatgpt/stats', 'chatgpt.stats', StatsController::class)
        ->delete('/chatgpt/stats', 'chatgpt.stats.reset', StatsController::class),

    (new Extend\ApiSerializer(ForumSerializer::class))
        ->attribute('chatGptBlockedTags', fn () => BlockedTags::ids())
        ->attribute('chatGptManualOverrideGroups', fn () => BlockedGroups::overrideIds()),

    (new Extend\ApiSerializer(PostSerializer::class))
        ->attribute('canTriggerChatGptAssistant', fn ($serializer, $post) => $post->discussion
            && $serializer->getActor()->can('discussion.triggerChatGPTAssistant', $post->discussion)),

    (new Extend\ServiceProvider())
        ->register(BindingsProvider::class)
        ->register(ClientProvider::class),

    (new Extend\Event())
        ->listen(Started::class, ReplyToPost::class),
    (new Extend\Event())
        ->listen(Posted::class, ReplyToCommentPost::class),
//    (new Extend\Event())
//        ->listen(Saving::class, SavingPost::class),

    (new Extend\Settings())
        ->default('wszdb-flarumaichat.model', 'gpt-3.5-turbo-instruct')
        ->default('wszdb-flarumaichat.enable_on_discussion_started', true)
        ->default('wszdb-flarumaichat.max_tokens', 100)
        ->default('wszdb-flarumaichat.user_prompt_badge_text', 'Assistant')
        ->default('wszdb-flarumaichat.queue_active', true)
        // do not answer inside private discussions (fof/byobu) unless enabled
        ->default('wszdb-flarumaichat.reply_in_private', false)
        // GLM reasoning: slower and thinking tokens eat max_tokens
        ->default('wszdb-flarumaichat.glm_thinking', false)
        // z.ai web search, and the domains it may draw on
        ->default('wszdb-flarumaichat.web_search', false)
        ->default('wszdb-flarumaichat.web_search_domains', '')
        // local data files the answer may quote from
        ->default('wszdb-flarumaichat.context_files', '')
        // characters of local data the prompt may carry
        ->default('wszdb-flarumaichat.context_chars', 6000)
        // quote threads of this forum that a post links to
        ->default('wszdb-flarumaichat.linked_threads', true)
        // new setting for answer duration in minutes (default 5)
        ->default('wszdb-flarumaichat.answer_duration', 0)
        // extra wait in seconds, added on top of the answer duration
        ->default('wszdb-flarumaichat.answer_delay', 5)
        // If any user replied to post, the AI will not reply to that post setting
        ->default('wszdb-flarumaichat.reply_to_post', true)
        ->default('wszdb-flarumaichat.role', 'You are a forum user')
        ->default('wszdb-flarumaichat.prompt',
            'Write a arguable or thankfully opinion asking or arguing something about an answer that has talked about "[title]" and who talked about [content]. Don\'t talk about what you would like or don\'t like. Speak in a close tone, like you are writing in a Tech Forum. Be random and unpredictable. Answer in [language].')
        ->default('wszdb-flarumaichat.continue_to_reply', true)
        ->default('wszdb-flarumaichat.continue_to_reply_count', 5)
        ->default('wszdb-flarumaichat.moderation', false)
        ->default('wszdb-flarumaichat.base_uri', 'https://api.openai.com/v1/')
        ->default('wszdb-flarumaichat.blocked-tags', '[]')
        ->default('wszdb-flarumaichat.blocked-groups', '[]')
        ->default('wszdb-flarumaichat.manual-override-groups', '[]')
        ->default('wszdb-flarumaichat.cached_models', '[]')
        ->default('wszdb-flarumaichat.models_last_fetched', 0)
        ->serializeToForum('chatGptUserPromptId', 'wszdb-flarumaichat.user_prompt')
        ->serializeToForum('chatGptBadgeText', 'wszdb-flarumaichat.user_prompt_badge_text'),
];
