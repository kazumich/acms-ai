<?php

namespace Acms\Plugins\AI\Services\AI\Provider;

/**
 * 構造化テキスト生成（タイトル・タグの候補一覧）に対応するプロバイダ。
 */
interface TextGeneratorInterface
{
    /**
     * 会話メッセージから候補一覧を構造化出力で生成する。
     *
     * @param string $instructions システム指示
     * @param array<array{role: string, content: string}> $messages 会話メッセージ
     * @param string $schemaName 構造化出力のスキーマ名
     * @return array<array{content: string}> 候補一覧（各要素は content キーを持つ）
     * @throws \RuntimeException 生成に失敗した場合
     */
    public function generateStructuredList(string $instructions, array $messages, string $schemaName): array;
}
