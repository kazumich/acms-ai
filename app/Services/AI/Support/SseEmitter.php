<?php

namespace Acms\Plugins\AI\Services\AI\Support;

/**
 * チャットのストリーミング応答を、フロントが解釈する SSE イベント形式で出力するヘルパー。
 *
 * フロント（use-chat.ts の processSSEStream）は以下のイベントを解釈する:
 *  - response.output_text.delta : { delta: "差分テキスト" }
 *  - response.completed         : { response: { id } }
 *  - error                      : { message }
 *
 * 非ストリーミングなプロバイダ（Claude/Gemini/互換）は、全文を1回の delta として出し、
 * completed で締める「バッファ方式」でこの形式に合わせる。
 */
class SseEmitter
{
    public static function delta(string $text): void
    {
        self::send(['type' => 'response.output_text.delta', 'delta' => $text]);
    }

    public static function completed(?string $id = null): void
    {
        self::send(['type' => 'response.completed', 'response' => ['id' => $id]]);
    }

    public static function error(string $message): void
    {
        self::send(['type' => 'error', 'message' => $message]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function send(array $payload): void
    {
        echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }
}
