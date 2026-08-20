<?php

namespace Wszdb\FlarumAiChat\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use OpenAI;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Wszdb\FlarumAiChat\Endpoint;

class FetchModelsController implements RequestHandlerInterface
{
    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        // the admin is trying a provider out, so the values typed into the form
        // win over the saved ones; an empty field falls back to what is saved
        $body = (array) $request->getParsedBody();
        $apiKey = Arr::get($body, 'api_key') ?: $this->settings->get('wszdb-flarumaichat.api_key');
        $baseUri = Arr::get($body, 'base_uri') ?: $this->settings->get('wszdb-flarumaichat.base_uri');

        if (!$apiKey) {
            return new JsonResponse([
                'error' => 'OpenAI client not configured. Please check your API key and base URI settings.'
            ], 400);
        }

        // the same safety check the client factory runs, before anything dials out
        try {
            $baseUri = Endpoint::assertSafe($baseUri);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        try {
            $client = OpenAI::factory()
                ->withApiKey($apiKey)
                ->withBaseUri($baseUri)
                ->make();

            // Fetch models from OpenAI API
            $response = $client->models()->list();

            // Filter for chat-compatible models (gpt-*, chatgpt-*, o1-*, etc.)
            $chatModels = array_filter($response->data, function ($model) {
                return True;
            });

            // Sort models by created date (newest first)
            usort($chatModels, function ($a, $b) {
                return $b->created - $a->created;
            });

            // Convert to simple array of model objects
            $models = array_map(function ($model) {
                return [
                    'id' => $model->id,
                    'created' => $model->created,
                    'owned_by' => $model->owned_by ?? 'unknown'
                ];
            }, $chatModels);

            // Store in settings as JSON
            $this->settings->set('wszdb-flarumaichat.cached_models', json_encode($models));
            $this->settings->set('wszdb-flarumaichat.models_last_fetched', time());

            return new JsonResponse([
                'models' => $models,
                'count' => count($models),
                'last_fetched' => time()
            ], 200);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Failed to fetch models: ' . $e->getMessage()
            ], 500);
        }
    }
}
