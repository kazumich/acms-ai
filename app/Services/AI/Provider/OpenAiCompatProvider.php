<?php

namespace Acms\Plugins\AI\Services\AI\Provider;

use Acms\Plugins\AI\Services\AI\Support\HttpClient;
use Acms\Plugins\AI\Services\AI\Support\StructuredJson;
use Acms\Plugins\AI\Services\AI\Support\SseEmitter;
use Acms\Plugins\AI\Services\AI\Support\AuditLogger;

/**
 * OpenAI 互換（Chat Completions）プロバイダ。base_url を差し替えて
 * さくらのAI Engine やローカル LLM などの互換エンドポイントを利用する。
 * 純正 OpenAI（Responses API）とは別物として扱う。
 * チャットはバッファ方式（全文取得 → SSE で一括出力）で対応する。
 */
class OpenAiCompatProvider implements ProviderInterface, TextGeneratorInterface, ChatStreamerInterface, VisionInterface
{
    private string $baseUrl;

    public function __construct(
        private string $apiKey,
        private string $model,
        string $baseUrl
    ) {
        $this->baseUrl = self::normalizeBaseUrl($baseUrl);
    }

    public function id(): string
    {
        return 'compat';
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
        if ($this->baseUrl === '') {
            throw new \RuntimeException('OpenAI互換エンドポイントのURLが設定されていません。');
        }

        $dataUrl = 'data:' . $mediaType . ';base64,' . $imageBase64;
        $body = json_encode([
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $userPrompt],
                        ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                    ],
                ],
            ],
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
            throw new \RuntimeException('OpenAI互換 画像解析の応答を取得できませんでした: ' . mb_substr($resBody, 0, 300));
        }
        return $text;
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
        $systemPrompt = $instructions . StructuredJson::OUTPUT_INSTRUCTION;

        // 構造化生成では JSON モード（response_format: json_object）を要求し、
        // JSON 以外の出力や重複出力を抑制する。response_format 非対応の互換
        // エンドポイントでは HTTP エラーになり得るため、その場合は付与なしで再試行する。
        try {
            $text = $this->requestText($systemPrompt, $messages, true);
        } catch (\RuntimeException $e) {
            $text = $this->requestText($systemPrompt, $messages, false);
        }

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
            AuditLogger::error('ai_chat', 'OpenAI互換 チャット生成に失敗しました。', [
                'provider' => $this->id(),
                'reason' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            SseEmitter::error('AIの応答を取得できませんでした。');
        }
    }

    /**
     * Chat Completions を呼び出して本文テキストを返す。
     *
     * @param array<array{role: string, content: string}> $messages
     * @param bool $jsonObject true なら response_format: json_object を要求する（構造化生成用）
     * @throws \RuntimeException
     */
    private function requestText(string $instructions, array $messages, bool $jsonObject = false): string
    {
        if ($this->baseUrl === '') {
            throw new \RuntimeException('OpenAI互換エンドポイントのURLが設定されていません。');
        }

        $apiMessages = [
            ['role' => 'system', 'content' => $instructions],
        ];
        foreach ($messages as $msg) {
            $role = ($msg['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user';
            $apiMessages[] = [
                'role' => $role,
                'content' => $msg['content'] ?? '',
            ];
        }

        $payload = [
            'model' => $this->model,
            'messages' => $apiMessages,
        ];
        if ($jsonObject) {
            $payload['response_format'] = ['type' => 'json_object'];
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

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

        return $text;
    }

    private static function normalizeBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '') {
            return '';
        }

        $parts = parse_url($baseUrl);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \RuntimeException('OpenAI互換エンドポイントのURLが不正です。');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('OpenAI互換エンドポイントURLに認証情報は含められません。');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = (string) $parts['host'];
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException('OpenAI互換エンドポイントは http/https のみ指定できます。');
        }
        if ($scheme !== 'https' && !self::isLoopbackHost($host)) {
            throw new \RuntimeException('OpenAI互換エンドポイントは https を指定してください。');
        }

        return $baseUrl;
    }

    private static function isLoopbackHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === 'localhost' || $host === '::1') {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return strpos($host, '127.') === 0;
        }
        return false;
    }
}
