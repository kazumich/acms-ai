<?php

namespace Acms\Plugins\AI\Services\AI\Provider;

use Acms\Plugins\AI\Services\AI as ServicesAI;
use Acms\Plugins\AI\Services\AI\Endpoints\ResponsesClient;
use Acms\Plugins\AI\Services\AI\Endpoints\StreamingResponsesClient;

/**
 * OpenAI（純正）プロバイダ。Responses API を利用する。
 * 構造化テキスト生成とストリーミングチャットに対応する。
 */
class OpenAiProvider implements ProviderInterface, TextGeneratorInterface, ChatStreamerInterface, VisionInterface
{
    public function __construct(
        private string $apiKey,
        private string $model,
        private string $organizationId = '',
        private string $projectId = ''
    ) {
    }

    public function id(): string
    {
        return 'openai';
    }

    public function supports(string $capability): bool
    {
        return in_array(
            $capability,
            [Capability::TEXT_GENERATION, Capability::CHAT_STREAM, Capability::VISION],
            true
        );
    }

    public function describeImage(
        string $systemPrompt,
        string $userPrompt,
        string $imageBase64,
        string $mediaType
    ): string {
        $client = new ResponsesClient($this->apiKey, $this->model);
        $client->createPayload();
        $client->setInstructions($systemPrompt);
        $dataUrl = 'data:' . $mediaType . ';base64,' . $imageBase64;
        $client->addInput('user', [
            $client->createTextContent($userPrompt),
            $client->createImageContent($dataUrl),
        ]);

        $result = $client->request();
        if ($result === null) {
            throw new \RuntimeException('画像の解析に失敗しました。');
        }
        $text = ResponsesClient::extractText($result);
        if (!is_string($text) || $text === '') {
            throw new \RuntimeException('画像解析の応答を取得できませんでした。');
        }
        return $text;
    }

    /**
     * @return array<string>
     */
    public function listModels(): array
    {
        $service = new ServicesAI();
        $models = $service->auth($this->organizationId, $this->projectId, $this->apiKey);
        return $models ?? [];
    }

    /**
     * @param array<array{role: string, content: string}> $messages
     * @return array<array{content: string}>
     */
    public function generateStructuredList(string $instructions, array $messages, string $schemaName): array
    {
        $client = new ResponsesClient($this->apiKey, $this->model);
        $client->createPayload();
        $client->setInstructions($instructions);

        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = $msg['content'] ?? '';
            $client->addInput($role, [
                $client->createTextContent($content, $role)
            ]);
        }

        $client->setTextFormat([
            'type' => 'json_schema',
            'name' => $schemaName,
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'content' => ['type' => 'string']
                            ],
                            'required' => ['content'],
                            'additionalProperties' => false
                        ]
                    ]
                ],
                'required' => ['items'],
                'additionalProperties' => false
            ]
        ]);

        $result = $client->request();
        if ($result === null) {
            throw new \RuntimeException('データを取得できませんでした。');
        }

        $text = ResponsesClient::extractText($result);
        if (!$text) {
            throw new \RuntimeException('データを取得できませんでした。');
        }

        $decoded = json_decode($text, true);
        if (!$decoded || !isset($decoded['items'])) {
            throw new \RuntimeException('有効な形式のデータを取得できませんでした。');
        }

        return $decoded['items'];
    }

    /**
     * @param array<array{role: string, content: string}> $messages
     */
    public function streamChat(string $instructions, array $messages, ?string $previousResponseId = null): void
    {
        $client = new StreamingResponsesClient($this->apiKey, $this->model);
        $client->createPayload();
        $client->setInstructions($instructions);

        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = $msg['content'] ?? '';
            $client->addInput($role, [
                $client->createTextContent($content, $role)
            ]);
        }

        if ($previousResponseId) {
            $client->setPreviousResponseId($previousResponseId);
        }

        $client->stream();
    }
}
