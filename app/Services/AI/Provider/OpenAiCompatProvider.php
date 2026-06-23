<?php

namespace Acms\Plugins\AI\Services\AI\Provider;

use Acms\Plugins\AI\Services\AI\Support\HttpClient;
use Acms\Plugins\AI\Services\AI\Support\StructuredJson;

/**
 * OpenAI 互換（Chat Completions）プロバイダ。base_url を差し替えて
 * さくらのAI Engine やローカル LLM などの互換エンドポイントを利用する。
 * 純正 OpenAI（Responses API）とは別物として扱う。
 */
class OpenAiCompatProvider implements ProviderInterface, TextGeneratorInterface
{
    private string $baseUrl;

    public function __construct(
        private string $apiKey,
        private string $model,
        string $baseUrl
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function id(): string
    {
        return 'compat';
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
        if ($this->apiKey === '' || $this->baseUrl === '') {
            return [];
        }
        [$status, $body] = HttpClient::get($this->baseUrl . '/models', [
            'Authorization: Bearer ' . $this->apiKey,
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
        if ($this->baseUrl === '') {
            throw new \RuntimeException('OpenAI互換エンドポイントのURLが設定されていません。');
        }

        $apiMessages = [
            ['role' => 'system', 'content' => $instructions . StructuredJson::OUTPUT_INSTRUCTION],
        ];
        foreach ($messages as $msg) {
            $role = ($msg['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user';
            $apiMessages[] = [
                'role' => $role,
                'content' => $msg['content'] ?? '',
            ];
        }

        $body = json_encode([
            'model' => $this->model,
            'messages' => $apiMessages,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        [$status, $resBody] = HttpClient::postJson($this->baseUrl . '/chat/completions', [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ], $body);

        if ($status >= 400) {
            throw new \RuntimeException('OpenAI互換 API エラー (HTTP ' . $status . '): ' . mb_substr($resBody, 0, 300));
        }

        $data = json_decode($resBody, true);
        $text = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($text) || $text === '') {
            throw new \RuntimeException('OpenAI互換 応答の解析に失敗しました: ' . mb_substr($resBody, 0, 300));
        }

        return StructuredJson::extractItems($text);
    }
}
