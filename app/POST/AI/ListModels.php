<?php

namespace Acms\Plugins\AI\POST\AI;

use ACMS_POST;
use Common;
use Acms\Plugins\AI\Services\AI as ServicesAI;
use Acms\Plugins\AI\Services\AI\Support\AuditLogger;
use Acms\Plugins\AI\Services\AI\Provider\ProviderFactory;

/**
 * ACMS_POST_AI_ListModels
 * 入力されたプロバイダ・APIキーで、利用可能なモデルID一覧を返す。
 * 管理画面の「モデル取得」ボタンから呼ばれる（未保存キーの検証用）。
 * APIキーが .env で管理されている場合は入力欄が無いため、空なら .env 値で補完する。
 */
class ListModels extends ACMS_POST
{
    private const PROVIDERS = ['openai', 'anthropic', 'gemini', 'compat'];

    public function post(): mixed
    {
        if (!sessionWithAdministration()) {
            return $this->errorResponse('権限がありません。', 403, [
                'reason' => 'permission_denied',
            ]);
        }
        if ($this->csrfTokenExists() && !$this->checkCsrfToken()) {
            return $this->errorResponse('不正なトークンです。', 403, [
                'reason' => 'invalid_csrf_token',
            ]);
        }

        $provider = (string) $this->Post->get('provider');
        if (!in_array($provider, self::PROVIDERS, true)) {
            return $this->errorResponse('不明なプロバイダです。', 400, [
                'provider' => $provider,
                'reason' => 'unknown_provider',
            ]);
        }

        $apiKey = (string) $this->Post->get('apiKey');
        $organizationId = (string) $this->Post->get('organizationId');
        $projectId = (string) $this->Post->get('projectId');

        // .env 管理時はフォームにキーが無いため、空なら .env 値にフォールバックする。
        $service = new ServicesAI();
        $config = $service->getConfig();
        $apiKeyFromEnv = false;
        if ($apiKey === '') {
            $apiKey = $service->getEnvValue($provider, 'apiKey');
            $apiKeyFromEnv = $apiKey !== '';
        }
        if ($organizationId === '') {
            $organizationId = $service->getEnvValue($provider, 'organizationId');
        }
        if ($projectId === '') {
            $projectId = $service->getEnvValue($provider, 'projectId');
        }

        if ($provider === '' || $apiKey === '') {
            return $this->errorResponse('プロバイダまたはAPIキーが指定されていません。', 400, [
                'provider' => $provider,
                'reason' => 'missing_provider_or_api_key',
            ]);
        }

        $baseUrl = (string) $this->Post->get('baseUrl');
        if ($provider === 'compat') {
            // .env の API キーはブラウザに出さない値なので、送信先も保存済み設定に固定する。
            if ($apiKeyFromEnv) {
                $baseUrl = (string) $config->get('ai_compat_base_url');
            }
            try {
                $baseUrl = $this->normalizeCompatBaseUrl($baseUrl);
            } catch (\InvalidArgumentException $e) {
                return $this->errorResponse($e->getMessage(), 400, [
                    'provider' => $provider,
                    'reason' => 'invalid_compat_base_url',
                    'detail' => $e->getMessage(),
                ]);
            }
        }

        $extra = [
            'organizationId' => $organizationId,
            'projectId' => $projectId,
            'baseUrl' => $baseUrl,
        ];

        try {
            $instance = ProviderFactory::createWithKey($provider, $apiKey, $extra);
            $models = $instance->listModels();
        } catch (\Throwable $e) {
            return $this->errorResponse('モデルの取得に失敗しました。', 500, [
                'provider' => $provider,
                'reason' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
        }

        return Common::responseJson(['models' => array_values($models)]);
    }

    private function normalizeCompatBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '') {
            return '';
        }

        $parts = parse_url($baseUrl);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \InvalidArgumentException('OpenAI互換エンドポイントのURLが不正です。');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('OpenAI互換エンドポイントURLに認証情報は含められません。');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = (string) $parts['host'];
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('OpenAI互換エンドポイントは http/https のみ指定できます。');
        }
        if ($scheme !== 'https' && !$this->isLoopbackHost($host)) {
            throw new \InvalidArgumentException('OpenAI互換エンドポイントは https を指定してください。');
        }

        return $baseUrl;
    }

    private function isLoopbackHost(string $host): bool
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

    /**
     * @param array<string, mixed> $context
     */
    private function errorResponse(string $message, int $status, array $context = []): mixed
    {
        http_response_code($status);
        if (!isset($context['reason'])) {
            $context['reason'] = 'http_' . $status;
        }
        AuditLogger::logForStatus('ai_list_models', $message, $status, $context);
        return Common::responseJson(['models' => [], 'error' => $message]);
    }
}
