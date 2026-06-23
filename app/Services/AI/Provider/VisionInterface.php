<?php

namespace Acms\Plugins\AI\Services\AI\Provider;

/**
 * 画像を入力として説明テキストを生成する（vision）プロバイダ。
 * 画像Alt・キャプション等のメディアAI機能で使用する。
 */
interface VisionInterface
{
    /**
     * 画像とプロンプトから応答テキストを生成して返す。
     * 構造化が必要な場合は、systemPrompt/userPrompt 側で JSON 出力を指示し、
     * 呼び出し側でパースする（このメソッドはモデルの生テキストを返す）。
     *
     * @param string $systemPrompt システム指示
     * @param string $userPrompt ユーザー指示
     * @param string $imageBase64 画像の base64（データURLのプレフィックスは付けない）
     * @param string $mediaType 画像の MIME タイプ（例: image/jpeg）
     * @return string モデルの応答テキスト
     * @throws \RuntimeException 生成に失敗した場合
     */
    public function describeImage(
        string $systemPrompt,
        string $userPrompt,
        string $imageBase64,
        string $mediaType
    ): string;
}
