<?php

namespace Acms\Plugins\AI\Services\AI\Provider;

/**
 * ストリーミングチャット（SSE をクライアントへ直接出力）に対応するプロバイダ。
 */
interface ChatStreamerInterface
{
    /**
     * チャット応答を SSE としてクライアントへストリーミング出力する。
     * （出力は echo され、ヘッダー送出は呼び出し側の責務）
     *
     * @param string $instructions システム指示
     * @param array<array{role: string, content: string}> $messages 会話メッセージ
     * @param string|null $previousResponseId 直前の応答ID（OpenAI 専用の最適化。非対応プロバイダは無視）
     * @throws \RuntimeException 通信に失敗した場合
     */
    public function streamChat(string $instructions, array $messages, ?string $previousResponseId = null): void;
}
