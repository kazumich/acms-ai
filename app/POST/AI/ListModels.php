<?php

namespace Acms\Plugins\AI\POST\AI;

use ACMS_POST;
use Common;
use Acms\Plugins\AI\Services\AI\Provider\ProviderFactory;

/**
 * ACMS_POST_AI_ListModels
 * 入力されたプロバイダ・APIキーで、利用可能なモデルID一覧を返す。
 * 管理画面の「モデル取得」ボタンから呼ばれる（未保存キーの検証用）。
 */
class ListModels extends ACMS_POST
{
    public function post(): mixed
    {
        $provider = (string) $this->Post->get('provider');
        $apiKey = (string) $this->Post->get('apiKey');

        if ($provider === '' || $apiKey === '') {
            return Common::responseJson(['models' => [], 'error' => 'プロバイダまたはAPIキーが指定されていません。']);
        }

        $extra = [
            'organizationId' => (string) $this->Post->get('organizationId'),
            'projectId' => (string) $this->Post->get('projectId'),
            'baseUrl' => (string) $this->Post->get('baseUrl'),
        ];

        try {
            $instance = ProviderFactory::createWithKey($provider, $apiKey, $extra);
            $models = $instance->listModels();
        } catch (\Throwable $e) {
            \AcmsLogger::error($e->getMessage());
            return Common::responseJson(['models' => [], 'error' => 'モデルの取得に失敗しました。']);
        }

        return Common::responseJson(['models' => array_values($models)]);
    }
}
