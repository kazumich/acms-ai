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

        try {
            $ServiceAI = new ServiceAI();
            $config = $ServiceAI->getConfig();
            $cred = $ServiceAI->getActiveCredentials($config);
            $this->configField = Tpl::buildField($config, $Tpl);

            if ($cred['apiKey'] && $cred['model']) {
                $this->authorized = true;
            }
        } catch (\Exception $e) {
        }

        $obj = array_merge(
            [
                'authorized' => $this->authorized ? 'true' : 'false',
            ],
            $this->configField
        );

        return $Tpl->render($obj);
    }
}
