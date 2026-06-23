<?php

namespace Acms\Plugins\AI\Services\AI\Provider;

use Acms\Plugins\AI\Services\AI as ServicesAI;

/**
 * 設定（config の ai_provider）に応じて AI プロバイダを生成するファクトリ。
 * プロバイダ追加時は build() に分岐を加える。
 */
class ProviderFactory
{
    /**
     * 現在の設定で有効な AI プロバイダを生成する。
     */
    public static function create(): ProviderInterface
    {
        $service = new ServicesAI();
        $config = $service->getConfig();
        $cred = $service->getActiveCredentials($config);

        return self::build($cred['provider'], $cred);
    }

    /**
     * 明示的に渡したキーでプロバイダを生成する（モデル一覧取得など、未保存キーの検証用）。
     *
     * @param array{model?: string, organizationId?: string, projectId?: string, baseUrl?: string} $extra
     */
    public static function createWithKey(string $providerId, string $apiKey, array $extra = []): ProviderInterface
    {
        return self::build($providerId, [
            'apiKey' => $apiKey,
            'model' => $extra['model'] ?? '',
            'organizationId' => $extra['organizationId'] ?? '',
            'projectId' => $extra['projectId'] ?? '',
            'baseUrl' => $extra['baseUrl'] ?? '',
        ]);
    }

    /**
     * @param array{apiKey: string, model?: string, organizationId?: string, projectId?: string, baseUrl?: string} $cred
     */
    private static function build(string $providerId, array $cred): ProviderInterface
    {
        switch ($providerId) {
            case 'anthropic':
                return new AnthropicProvider($cred['apiKey'], $cred['model'] ?? '');
            case 'gemini':
                return new GeminiProvider($cred['apiKey'], $cred['model'] ?? '');
            case 'compat':
                return new OpenAiCompatProvider($cred['apiKey'], $cred['model'] ?? '', $cred['baseUrl'] ?? '');
            case 'openai':
            default:
                return new OpenAiProvider(
                    $cred['apiKey'],
                    $cred['model'] ?? '',
                    $cred['organizationId'] ?? '',
                    $cred['projectId'] ?? ''
                );
        }
    }
}
