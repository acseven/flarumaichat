<?php

namespace Wszdb\FlarumAiChat\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Carbon;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Wszdb\FlarumAiChat\Usage;

class StatsController implements RequestHandlerInterface
{
    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        if ($request->getMethod() === 'DELETE') {
            Usage::reset($this->settings);
        }

        $botId = (int) $this->settings->get('wszdb-flarumaichat.user_prompt');

        return new JsonResponse([
            'api' => Usage::totals($this->settings),
            'posts' => $botId ? $this->postStats($botId) : null,
            'model' => (string) $this->settings->get('wszdb-flarumaichat.model'),
        ]);
    }

    private function postStats(int $botId): array
    {
        $answers = fn () => Post::query()->where('type', 'comment')->where('user_id', $botId);
        $now = Carbon::now();

        return [
            'answers' => $answers()->count(),
            'answers_7d' => $answers()->where('created_at', '>=', $now->copy()->subDays(7))->count(),
            'answers_30d' => $answers()->where('created_at', '>=', $now->copy()->subDays(30))->count(),
            'discussions' => $answers()->distinct()->count('discussion_id'),
            'first_at' => $answers()->min('created_at'),
            'last_at' => $answers()->max('created_at'),
            'avg_length' => (int) round((float) $answers()->selectRaw('AVG(CHAR_LENGTH(content)) as l')->value('l')),
        ];
    }
}
