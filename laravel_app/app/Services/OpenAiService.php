<?php

namespace App\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

class OpenAiService
{
    public function __construct(private readonly HttpFactory $http) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{request:array<string,mixed>,response:array<string,mixed>,content:string,model:string}
     */
    public function requestStructuredJson(array $payload): array
    {
        $model = (string) config('services.openai.model', 'gpt-4.1-mini');
        $timeout = (int) config('services.openai.timeout_seconds', 30);

        $schemaName = (string) ($payload['schema_name'] ?? 'weekend_candidate_ranker');

        $requestPayload = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $payload['system_prompt'],
                ],
                [
                    'role' => 'user',
                    'content' => json_encode($payload['user_payload'], JSON_THROW_ON_ERROR),
                ],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $schemaName,
                    'strict' => true,
                    'schema' => $payload['json_schema'],
                ],
            ],
        ];

        $response = $this->http
            ->baseUrl('https://api.openai.com/v1')
            ->timeout($timeout)
            ->withToken((string) config('services.openai.api_key'))
            ->acceptJson()
            ->post('/chat/completions', $requestPayload)
            ->throw();

        /** @var array<string,mixed> $responseJson */
        $responseJson = $response->json();

        $content = data_get($responseJson, 'choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('OpenAI response did not include assistant message content.');
        }

        return [
            'request' => $requestPayload,
            'response' => $responseJson,
            'content' => $content,
            'model' => (string) data_get($responseJson, 'model', $model),
        ];
    }
}
