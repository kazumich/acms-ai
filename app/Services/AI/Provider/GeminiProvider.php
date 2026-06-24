<?php

namespace Acms\Plugins\AI\Services\AI\Provider;

use Acms\Plugins\AI\Services\AI\Support\HttpClient;
use Acms\Plugins\AI\Services\AI\Support\StructuredJson;
use Acms\Plugins\AI\Services\AI\Support\SseEmitter;
use Acms\Plugins\AI\Services\AI\Support\AuditLogger;

/**
 * Google Gemini プロバイダ。generateContent API を利用する。
 * 構造化出力はプロンプト指示＋寛容パースで候補一覧を得る。
 * チャットはバッファ方式（全文取得 → SSE で一括出力）で対応する。
 */
class GeminiProvider implements ProviderInterface, TextGeneratorInterface, ChatStreamerInterface, VisionInterface
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
        $body = json_encode([
            'systemInstruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $userPrompt],
                        ['inline_data' => ['mime_type' => $mediaType, 'data' => $imageBase64]],
                    ],
                ],
            ],
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
            throw new \RuntimeException('Gemini 画像解析の応答を取得できませんでした: ' . mb_substr($resBody, 0, 300));
        }
        return $text;
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
        $text = $this->requestText($instructions . StructuredJson::OUTPUT_INSTRUCTION, $messages);
        return StructuredJson::extractItems($text);
    }

    /**
     * @param array<array{role: string, content: string}> $messages
     */
    public function streamChat(string $instructions, array $messages, ?string $previousResponseId = null): void
    {
        try {
            $text = $this->requestText($instructions, $messages);
            SseEmitter::delta($text);
            SseEmitter::completed();
        } catch (\Throwable $e) {
            AuditLogger::error('ai_chat', 'Gemini チャット生成に失敗しました。', [
                'provider' => $this->id(),
                'reason' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            SseEmitter::error($e->getMessage());
        }
    }

    /**
     * generateContent を呼び出して本文テキストを返す。
     *
     * @param array<array{role: string, content: string}> $messages
     * @throws \RuntimeException
     */
    private function requestText(string $instructions, array $messages): string
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
                'parts' => [['text' => $instructions]],
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

        return $text;
    }
}
