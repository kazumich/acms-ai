<?php

namespace Acms\Plugins\AI\Services\AI\Provider;

use Acms\Plugins\AI\Services\AI\Support\HttpClient;
use Acms\Plugins\AI\Services\AI\Support\StructuredJson;

/**
 * Claude（Anthropic）プロバイダ。Messages API を利用する。
 * 構造化出力には非対応のため、プロンプト指示＋寛容パースで候補一覧を得る。
 */
class AnthropicProvider implements ProviderInterface, TextGeneratorInterface
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const MODELS_ENDPOINT = 'https://api.anthropic.com/v1/models?limit=100';
    private const API_VERSION = '2023-06-01';
    private const MAX_TOKENS = 1024;

    public function __construct(
        private string $apiKey,
        private string $model
    ) {
    }

    public function id(): string
    {
        return 'anthropic';
    }

    public function supports(string $capability): bool
    {
        return $capability === Capability::TEXT_GENERATION;
    }

    /**
     * @return array<string>
     */
    public function listModels(): array
    {
        if ($this->apiKey === '') {
            return [];
        }
        [$status, $body] = HttpClient::get(self::MODELS_ENDPOINT, [
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: ' . self::API_VERSION,
        ]);
        if ($status >= 400) {
            return [];
        }
        $data = json_decode($body, true);
        $ids = [];
        foreach (($data['data'] ?? []) as $m) {
            if (isset($m['id'])) {
                $ids[] = (string) $m['id'];
            }
        }
        return $ids;
    }

    /**
     * @param array<array{role: string, content: string}> $messages
     * @return array<array{content: string}>
     */
    public function generateStructuredList(string $instructions, array $messages, string $schemaName): array
    {
        $apiMessages = [];
        foreach ($messages as $msg) {
            $role = ($msg['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user';
            $apiMessages[] = [
                'role' => $role,
                'content' => $msg['content'] ?? '',
            ];
        }

        $body = json_encode([
            'model' => $this->model,
            'max_tokens' => self::MAX_TOKENS,
            'system' => $instructions . StructuredJson::OUTPUT_INSTRUCTION,
            'messages' => $apiMessages,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        [$status, $resBody] = HttpClient::postJson(self::ENDPOINT, [
            'Content-Type: application/json',
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: ' . self::API_VERSION,
        ], $body);

        if ($status >= 400) {
            throw new \RuntimeException('Claude API エラー (HTTP ' . $status . '): ' . mb_substr($resBody, 0, 300));
        }

        $data = json_decode($resBody, true);
        $text = $data['content'][0]['text'] ?? null;
        if (!is_string($text) || $text === '') {
            throw new \RuntimeException('Claude 応答の解析に失敗しました: ' . mb_substr($resBody, 0, 300));
        }

        return StructuredJson::extractItems($text);
    }
}
