<?php

namespace Acms\Plugins\AI\Services\AI\Provider;

/**
 * プロバイダが提供しうる機能（ケイパビリティ）の識別子。
 * 各 Provider は supports() でどの機能に対応するかを宣言する。
 */
final class Capability
{
    /** 構造化テキスト生成（タイトル・タグの候補一覧） */
    public const TEXT_GENERATION = 'text_generation';

    /** ストリーミングチャット（SSE） */
    public const CHAT_STREAM = 'chat_stream';

    /** 画像説明（Alt・キャプション）。将来 MediaAISupport 統合で使用 */
    public const VISION = 'vision';
}
