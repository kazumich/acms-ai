<?php

namespace Acms\Plugins\AI\Services\AI\Provider;

use Acms\Plugins\AI\Services\AI\Support\HttpClient;
use Acms\Plugins\AI\Services\AI\Support\StructuredJson;

/**
 * Google Gemini プロバイダ。generateContent API を利用する。
 * 構造化出力はプロンプト指示＋寛容パースで候補一覧を得る。
 */
class GeminiProvider implements ProviderInterface, TextGeneratorInterface
{
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(
        private string $apiKey,
        private string $model
    ) {
    }

    public function id(): string
    {
        return 'gemini';
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
        [$status, $body] = HttpClient::get(self::BASE . '/models?key=' . rawurlencode($this->apiKey));
        if ($status >= 400) {
            return [];
        }
        $data = json_decode($body, true);
        $ids = [];
        foreach (($data['models'] ?? []) as $m) {
            $methods = $m['supportedGenerationMethods'] ?? [];
            if (!empty($methods) && !in_array('generateContent', $methods, true)) {
                continue;
            }
            if (isset($m['name'])) {
                $ids[] = preg_replace('@^models/@', '', (string) $m['name']);
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
        $contents = [];
        foreach ($messages as $msg) {
            $role = ($msg['role'] ?? 'user') === 'assistant' ? 'model' : 'user';
            $contents[] = [
                'role' => $role,
                'parts' => [['text' => $msg['content'] ?? '']],
            ];
        }

        $body = json_encode([
            'systemInstruction' => [
                'parts' => [['text' => $instructions . StructuredJson::OUTPUT_INSTRUCTION]],
            ],
            'contents' => $contents,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $url = self::BASE . '/models/' . rawurlencode($this->model)
            . ':generateContent?key=' . rawurlencode($this->apiKey);

        [$status, $resBody] = HttpClient::postJson($url, [
            'Content-Type: application/json',
        ], $body);

        if ($status >= 400) {
            throw new \RuntimeException('Gemini API エラー (HTTP ' . $status . '): ' . mb_substr($resBody, 0, 300));
        }

        $data = json_decode($resBody, true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!is_string($text) || $text === '') {
            throw new \RuntimeException('Gemini 応答の解析に失敗しました: ' . mb_substr($resBody, 0, 300));
        }

        return StructuredJson::extractItems($text);
    }
}
