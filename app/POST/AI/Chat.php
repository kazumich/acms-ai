<?php

namespace Acms\Plugins\AI\POST\AI;

use ACMS_POST;
use Acms\Plugins\AI\POST\AIPostTrait;
use Acms\Plugins\AI\Services\AI\Provider\ProviderFactory;
use Acms\Plugins\AI\Services\AI\Provider\ChatStreamerInterface;
use Acms\Plugins\AI\Services\AI\Support\AuditLogger;

/**
 * ACMS_POST_AI_Chat
 * Streaming chat endpoint. Outputs SSE directly to client.
 */
class Chat extends ACMS_POST
{
    use AIPostTrait;

    public function post(): mixed
    {
        $guardResponse = $this->guardAdminRequest();
        if ($guardResponse !== null) {
            return $guardResponse;
        }

        $this->initAiConfig();

        if (!$this->apiKey || !$this->model) {
            return $this->errorJsonResponse('APIキーまたはモデルの設定がありません。', 500, [
                'reason' => 'missing_api_key_or_model',
            ]);
        }

        $silent = $this->Post->get("silent") === '1';
        $messages = $this->resolveMessages();

        if (empty($messages)) {
            return $this->errorJsonResponse('無効なリクエストです。', 400, [
                'reason' => 'empty_messages',
            ]);
        }

        $provider = ProviderFactory::create();
        if (!$provider instanceof ChatStreamerInterface) {
            return $this->errorJsonResponse('選択中のAIプロバイダはチャットに対応していません。', 400, [
                'reason' => 'unsupported_provider',
            ]);
        }

        $silentInstruction = $silent
            ? "\n\n## SILENT MODE (highest priority)\n" .
              "This is an automated request. " .
              "You MUST output the result wrapped in <correction>...</correction>. " .
              "Never omit the tag regardless of how simple or ambiguous the request is.\n"
            : "";
        $instructions =
            "You are a helpful assistant. Respond in Japanese unless the user asks otherwise.\n" .
            "\n" .
            "## Text Processing Tasks\n" .
            "When the user requests text transformation or processing "
            . "(rewriting, proofreading, summarizing, translating, paraphrasing, simplifying, expanding, "
            . "lengthening, etc.), "
            . "**always output the processed result exactly as requested**.\n" .
            "If the target text is not explicitly specified, use the most recently handled text in the "
            . "conversation.\n" .
            "\n" .
            "## Rules for the <correction> Tag (Highest Priority)\n" .
            "Whenever you process or generate text, you **must** wrap the result in a <correction> tag. "
            . "There are no exceptions to this rule.\n" .
            "\n" .
            "Before the <correction> tag, place only a brief sentence describing what you did.\n" .
            "All processed or generated text must be written inside the <correction> tag. "
            . "Do not write the result outside the tag.\n" .
            "\n" .
            "The only case where the <correction> tag may be omitted:\n" .
            "- Pure responses that involve no text processing or generation whatsoever "
            . "(greetings, simple yes/no answers only)\n" .
            "\n" .
            "Always use the tag in the following cases:\n" .
            "- Any form of text processing: translation, proofreading, summarizing, rewriting, paraphrasing, etc.\n" .
            "- Generating or creating new text\n" .
            "- Any response that includes a processed result requested by the user\n" .
            "\n" .
            "## Output Example\n" .
            "Here is the simplified version.\n" .
            "<correction>\n" .
            "The simplified text\n" .
            "</correction>" .
            $silentInstruction;

        // Stream output directly - must run before any other output
        if (ob_get_level()) {
            ob_end_clean();
        }
        @ini_set('zlib.output_compression', '0');
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }

        try {
            $provider->streamChat($instructions, $messages);
        } catch (\Exception $e) {
            AuditLogger::error($this->aiLogAction(), 'チャットのストリーミングに失敗しました。', [
                'reason' => $e->getMessage(),
                'exception' => get_class($e),
                'silent' => $silent,
            ]);
            echo "data: " . json_encode(['type' => 'error', 'message' => $e->getMessage()]) . "\n\n";
        }

        exit;
    }

    /**
     * POST から会話履歴を取り出して正規化する。
     * `messages`（role/content の配列 JSON）を優先し、無ければ単発 `input` を1メッセージとして扱う。
     *
     * @return array<array{role: string, content: string}>
     */
    private function resolveMessages(): array
    {
        $raw = $this->Post->get("messages");
        $decoded = $raw ? json_decode($raw, true) : null;

        if (!is_array($decoded)) {
            $input = (string) $this->Post->get("input");
            return $input !== '' ? [['role' => 'user', 'content' => $input]] : [];
        }

        $messages = [];
        foreach ($decoded as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $content = isset($msg['content']) ? (string) $msg['content'] : '';
            if ($content === '') {
                continue;
            }
            $role = (isset($msg['role']) && $msg['role'] === 'assistant') ? 'assistant' : 'user';
            $messages[] = ['role' => $role, 'content' => $content];
        }

        return $messages;
    }

    /**
     * @param array<string, mixed> $data
     * @return mixed
     */
    private function jsonResponse(array $data): mixed
    {
        return \Common::responseJson($data);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function errorJsonResponse(string $message, int $status, array $context = []): mixed
    {
        http_response_code($status);
        if ($this->provider !== '' && !isset($context['provider'])) {
            $context['provider'] = $this->provider;
        }
        AuditLogger::logForStatus($this->aiLogAction(), $message, $status, $context);
        return $this->jsonResponse(['message' => $message, 'errorCode' => $status]);
    }
}
