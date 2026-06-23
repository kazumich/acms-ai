<?php

namespace Acms\Plugins\AI\POST;

use Common;
use Acms\Plugins\AI\Services\AI as ServicesAI;
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

    protected function initAiConfig(): void
    {
        try {
            $ServiceAI = new ServicesAI();
            $config = $ServiceAI->getConfig();
            $cred = $ServiceAI->getActiveCredentials($config);
            if ($cred['apiKey'] && $cred['model']) {
                $this->apiKey = $cred['apiKey'];
                $this->model = $cred['model'];
            }
        } catch (\Exception $e) {
            \AcmsLogger::error($e->getMessage());
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
    private function errorResponse(string $message, array $logContext = []): mixed
    {
        $response = ['message' => $message, 'errorCode' => 500];
        \AcmsLogger::notice($message, empty($logContext) ? $response : $logContext);
        return Common::responseJson($response);
    }

    /**
     * @param array<array{role: string, content: string}> $promptMessages
     */
    protected function executeAiRequest(string $instructions, string $schemaName, array $promptMessages): mixed
    {
        if (!$this->apiKey || !$this->model) {
            return $this->errorResponse('APIキーまたはモデルの設定がありません。');
        }

        $provider = ProviderFactory::create();
        if (!$provider instanceof TextGeneratorInterface) {
            return $this->errorResponse('選択中のAIプロバイダはテキスト生成に対応していません。');
        }

        $messages = array_merge($this->prependMessages(), $promptMessages);

        try {
            $items = $provider->generateStructuredList($instructions, $messages, $schemaName);
        } catch (\Throwable $e) {
            \AcmsLogger::error($e->getMessage());
            return $this->errorResponse('データを取得できませんでした。');
        }

        return Common::responseJson($items);
    }
}
