<?php

namespace Acms\Plugins\AI\GET\AI;

use Tpl;
use Template;
use ACMS_Corrector;
use Acms\Plugins\AI\GET\AI;
use Acms\Plugins\AI\Services\AI as ServiceAI;

class Config extends AI
{
    public function get()
    {
        $Tpl = new Template($this->tpl, new ACMS_Corrector());
        $titleEnabled = false;
        $tagEnabled = false;

        try {
            $ServiceAI = new ServiceAI();
            $config = $ServiceAI->getConfig();
            $cred = $ServiceAI->getActiveCredentials($config);
            $this->configField = Tpl::buildField($config, $Tpl);

            if ($cred['apiKey'] && $cred['model']) {
                $this->authorized = true;
            }

            // タイトル/タグ生成機能の有効・無効（フロントの表示制御に使う）
            $titleEnabled = !empty($config->get('ai_title_valid'));
            $tagEnabled = !empty($config->get('ai_tag_valid'));
        } catch (\Exception $e) {
        }

        $obj = array_merge(
            [
                'authorized' => $this->authorized ? 'true' : 'false',
                'title_enabled' => $titleEnabled ? 'true' : 'false',
                'tag_enabled' => $tagEnabled ? 'true' : 'false',
            ],
            $this->configField
        );

        return $Tpl->render($obj);
    }
}
