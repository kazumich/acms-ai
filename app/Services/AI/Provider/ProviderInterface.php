<?php

namespace Acms\Plugins\AI\Services\AI\Provider;

/**
 * AI プロバイダの基底インターフェース。
 * 具体的な機能は TextGeneratorInterface / ChatStreamerInterface などの
 * ケイパビリティ別インターフェースで表現し、対応可否は supports() で宣言する。
 */
interface ProviderInterface
{
    /**
     * プロバイダ識別子（'openai' など）。
     */
    public function id(): string;

    /**
     * 設定済みの認証情報で利用可能なモデルID一覧を返す。
     *
     * @return array<string>
     */
    public function listModels(): array;

    /**
     * 指定したケイパビリティ（Capability の定数）に対応するか。
     */
    public function supports(string $capability): bool;
}
