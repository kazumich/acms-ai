<?php

namespace Acms\Plugins\AI\POST;

use Common;
use Acms\Plugins\AI\Services\AI as ServicesAI;
use Acms\Plugins\AI\Services\AI\Support\AuditLogger;
use Acms\Plugins\AI\Services\AI\Provider\ProviderFactory;
use Acms\Plugins\AI\Services\AI\Provider\TextGeneratorInterface;

trait AIPostTrait
{
    /**
     * @var string
     */
    protected $apiKey = "";

    /**
     * @var string
     */
    protected $model = "";

    /**
     * @var string
     */
    protected $provider = "";

    protected function initAiConfig(): void
    {
        try {
            $ServiceAI = new ServicesAI();
            $config = $ServiceAI->getConfig();
            $cred = $ServiceAI->getActiveCredentials($config);
            $this->provider = $cred['provider'];
            if ($cred['apiKey'] && $cred['model']) {
                $this->apiKey = $cred['apiKey'];
                $this->model = $cred['model'];
            }
        } catch (\Exception $e) {
            AuditLogger::error($this->aiLogAction(), 'AI設定の初期化に失敗しました。', [
                'provider' => $this->provider,
                'reason' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
        }
    }

    /**
     * プロンプトメッセージの前に差し込む追加メッセージ。
     * デフォルトは無し。サブクラスで上書きする。
     *
     * @return array<array{role: string, content: string}>
     */
    protected function prependMessages(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $logContext
     */
    private function errorResponse(string $message, array $logContext = [], int $errorCode = 500): mixed
    {
        $response = ['message' => $message, 'errorCode' => $errorCode];
        $context = empty($logContext) ? $response : $logContext;
        if ($this->provider !== '' && !isset($context['provider'])) {
            $context['provider'] = $this->provider;
        }
        AuditLogger::logForStatus(
            $this->aiLogAction(),
            $message,
            $errorCode,
            $context
        );
        return Common::responseJson($response);
    }

    protected function guardAdminRequest(): mixed
    {
        if (!sessionWithAdministration()) {
            http_response_code(403);
            return $this->errorResponse('権限がありません。', ['reason' => 'permission_denied'], 403);
        }
        if ($this->csrfTokenExists() && !$this->checkCsrfToken()) {
            http_response_code(403);
            return $this->errorResponse('不正なトークンです。', ['reason' => 'invalid_csrf_token'], 403);
        }
        return null;
    }

    /**
     * @param array<array{role: string, content: string}> $promptMessages
     */
    protected function executeAiRequest(string $instructions, string $schemaName, array $promptMessages): mixed
    {
        if (!$this->apiKey || !$this->model) {
            return $this->errorResponse('APIキーまたはモデルの設定がありません。', [
                'reason' => 'missing_api_key_or_model',
            ]);
        }

        $provider = ProviderFactory::create();
        if (!$provider instanceof TextGeneratorInterface) {
            return $this->errorResponse('選択中のAIプロバイダはテキスト生成に対応していません。', [
                'reason' => 'unsupported_provider',
            ]);
        }

        $messages = array_merge($this->prependMessages(), $promptMessages);

        try {
            $items = $provider->generateStructuredList($instructions, $messages, $schemaName);
        } catch (\Throwable $e) {
            return $this->errorResponse('データを取得できませんでした。', [
                'schema' => $schemaName,
                'reason' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
        }

        return Common::responseJson($items);
    }

    protected function aiLogAction(): string
    {
        $parts = explode('\\', static::class);
        $name = strtolower((string) end($parts));
        return 'ai_' . ($name ?: 'request');
    }
}
