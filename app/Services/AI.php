<?php

namespace Acms\Plugins\AI\Services;

use DB;
use SQL;
use Field;
use Config;
use Exception;
use Acms\Plugins\AI\Services\AI\Support\AuditLogger;

class AI
{
    public const SAKURA_AI_ENGINE_BASE_URL = 'https://api.ai.sakura.ad.jp/v1';

    /**
     * @param string $organizationId ChatGPTの組織キー
     * @param string $projectId ChatGPTのプロジェクトキー
     * @param string $apiKey ChatGPTのAPIキー
     * @return array|null $response 使用できるモデルの配列、失敗するとnull
    */
    public function auth(string $organizationId, string $projectId, string $apiKey)
    {
        if (!$organizationId || !$projectId || !$apiKey) {
            return null;
        }

        $url = "https://api.openai.com/v1/models";

        $headers = [
            "Content-Type: application/json",
            "Authorization: Bearer $apiKey",
            "OpenAI-Organization: $organizationId",
            "OpenAI-Project: $projectId"
        ];

        $response = null;
        try {
            $ch = curl_init();

            $options = [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ];
            curl_setopt_array($ch, $options);
            $result = curl_exec($ch);
            if ($result === false) {
                throw new \Exception('cURL Error: ' . curl_error($ch));
            }
            $decodedResult = json_decode($result);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('JSON decode error: ' . json_last_error_msg());
            } elseif (isset($decodedResult->error)) {
                throw new \Exception("ChatGPT's server error: " . $decodedResult->error->message);
            }

            $response = $this->getModelsByAuthResponse($decodedResult);
        } catch (\Exception $e) {
            AuditLogger::error('ai_list_models', 'OpenAI モデル取得に失敗しました。', [
                'provider' => 'openai',
                'reason' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
        }

        return $response;
    }

    /**
     * @return Field|null $result プロンプト｜失敗するとnull
    */
    public function getConfig()
    {
        $config = Config::loadDefaultField();
        $config->overload(Config::loadBlogConfig(BID));
        return $config;
    }

    /**
     * 旧 OpenAI 専用キー（ai_api_key 等）を新しい名前空間キー（ai_openai_* 等）へ
     * 読み取り時にフォールバックする。DB は書き換えず、保存時に新キーで上書きされる。
     *
     * @param Field $config
     * @return void
     */
    public function applyLegacyFallback(Field $config): void
    {
        $map = [
            'ai_openai_api_key' => 'ai_api_key',
            'ai_openai_organization_id' => 'ai_organization_id',
            'ai_openai_project_id' => 'ai_project_id',
            'ai_openai_model' => 'ai_model',
        ];
        foreach ($map as $new => $legacy) {
            if (!$config->get($new) && $config->get($legacy)) {
                $config->set($new, $config->get($legacy));
            }
        }
    }

    /**
     * .env で設定できるクレデンシャルの環境変数名（provider → field → 変数名）。
     * .env に値があれば DB 保存値より優先する（管理画面にキーを出力せず漏洩を防ぐ）。
     *
     * @var array<string, array<string, string>>
     */
    private const ENV_KEYS = [
        'openai' => [
            'apiKey' => 'ACMS_AI_OPENAI_API_KEY',
            'organizationId' => 'ACMS_AI_OPENAI_ORGANIZATION_ID',
            'projectId' => 'ACMS_AI_OPENAI_PROJECT_ID',
        ],
        'anthropic' => ['apiKey' => 'ACMS_AI_ANTHROPIC_API_KEY'],
        'gemini' => ['apiKey' => 'ACMS_AI_GEMINI_API_KEY'],
        'compat' => ['apiKey' => 'ACMS_AI_SAKURA_API_KEY'],
    ];

    /**
     * 現在選択中のプロバイダの認証情報を返す。
     * provider / apiKey / model / organizationId / projectId / baseUrl を含む。
     * .env に該当キーがあれば DB 値より優先する。
     *
     * キー: provider / apiKey / model / organizationId / projectId / baseUrl
     *
     * @param Field $config
     * @return array<string, string>
     */
    public function getActiveCredentials(Field $config): array
    {
        $this->applyLegacyFallback($config);
        $provider = $config->get('ai_provider') ?: 'openai';

        $base = [
            'provider' => $provider,
            'apiKey' => '',
            'model' => '',
            'organizationId' => '',
            'projectId' => '',
            'baseUrl' => '',
        ];

        switch ($provider) {
            case 'anthropic':
                $base['apiKey'] = (string) $config->get('ai_anthropic_api_key');
                $base['model'] = (string) $config->get('ai_anthropic_model');
                break;
            case 'gemini':
                $base['apiKey'] = (string) $config->get('ai_gemini_api_key');
                $base['model'] = (string) $config->get('ai_gemini_model');
                break;
            case 'compat':
                $base['apiKey'] = (string) $config->get('ai_compat_api_key');
                $base['model'] = (string) $config->get('ai_compat_model');
                $base['baseUrl'] = (string) ($config->get('ai_compat_base_url') ?: self::SAKURA_AI_ENGINE_BASE_URL);
                break;
            case 'openai':
            default:
                $base['provider'] = 'openai';
                $base['apiKey'] = (string) $config->get('ai_openai_api_key');
                $base['model'] = (string) $config->get('ai_openai_model');
                $base['organizationId'] = (string) $config->get('ai_openai_organization_id');
                $base['projectId'] = (string) $config->get('ai_openai_project_id');
                break;
        }

        // .env に設定があれば DB 値より優先する（漏洩対策。キー類は管理画面に出さない運用）。
        foreach (self::ENV_KEYS[$base['provider']] ?? [] as $field => $envName) {
            $envValue = env($envName);
            if ($envValue !== '') {
                $base[$field] = $envValue;
            }
        }

        return $base;
    }

    /**
     * 指定プロバイダの API キーが .env で設定されているか。
     * 管理画面の入力欄出し分け（Hook→main.html）やモデル取得（ListModels）で利用する。
     *
     * @param string $provider
     * @param string $field provider 内のフィールド名（apiKey / organizationId / projectId）
     * @return bool
     */
    public function isFromEnv(string $provider, string $field = 'apiKey'): bool
    {
        $envName = self::ENV_KEYS[$provider][$field] ?? '';
        return $envName !== '' && env($envName) !== '';
    }

    /**
     * 指定プロバイダ・フィールドの .env 値を返す（無ければ空文字）。
     *
     * @param string $provider
     * @param string $field
     * @return string
     */
    public function getEnvValue(string $provider, string $field = 'apiKey'): string
    {
        $envName = self::ENV_KEYS[$provider][$field] ?? '';
        return $envName === '' ? '' : env($envName);
    }

    /**
     * 現在選択中のプロバイダで AI 機能が利用可能か（API キーとモデルが揃っているか）。
     * テンプレ注入の可否判定（ServiceProvider）等で利用する。
     *
     * @param Field|null $config 省略時は getConfig() で取得する
     * @return bool
     */
    public function isAuthorized(?Field $config = null): bool
    {
        try {
            $config = $config ?: $this->getConfig();
            $cred = $this->getActiveCredentials($config);
            return !empty($cred['apiKey']) && !empty($cred['model']);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * @param object $result
     * @return array $models
     */
    private function getModelsByAuthResponse(object $result)
    {
        $models = [];
        foreach ($result->data as $datum) {
            if ($this->availableModel($datum->id)) {
                $models[] = $datum->id;
            }
        }
        return $models;
    }

    /**
     * @param string $model モデル名
     * @return string|null $available 利用可能ならモデル名を返し、利用できないならnullを返す。
     */
    public function availableModel(string $model)
    {
        if (!$model) {
            return null;
        }
        $availableModels = ['gpt-5.4', 'gpt-5.4-pro', 'gpt-5.4-mini', 'gpt-5.4-nano'];
        $available = null;
        if (in_array($model, $availableModels, true)) {
            $available = $model;
        }
        return $available;
    }

    /**
     * @return array $result タグの配列
     */
    public static function getTagNameAll()
    {
        $result = [];
        try {
            $DB = DB::singleton(dsn());
            $SQL = SQL::newSelect('tag');
            $SQL->addSelect('tag_name');
            $q = $SQL->get(dsn());
            $tagNameArr = $DB->query($q, 'all');
            foreach ($tagNameArr as $row) {
                $result[] = $row["tag_name"];
            }
        } catch (Exception $e) {
            AuditLogger::error('ai_tag', '既存タグ一覧の取得に失敗しました。', [
                'reason' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            return $result;
        }

        $result = array_values(array_unique($result, SORT_REGULAR));
        return $result;
    }
}
