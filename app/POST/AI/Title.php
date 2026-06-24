<?php

namespace Acms\Plugins\AI\POST\AI;

use ACMS_POST;
use Acms\Plugins\AI\POST\AIPostTrait;
use Acms\Plugins\AI\Services\AI as ServicesAI;

/**
 * ACMS_POST_AI_Title
 */
class Title extends ACMS_POST
{
    use AIPostTrait;

    public function post(): mixed
    {
        $this->initAiConfig();

        $article = $this->Post->get('article');

        $serviceAI = new ServicesAI();
        $config = $serviceAI->getConfig();

        // ai_title_valid が無効なら、フロントで隠していても直接POSTは受け付けない（バックエンドゲート）。
        if (empty($config->get('ai_title_valid'))) {
            return $this->errorResponse('タイトル生成は管理画面で有効化されていません。', [], 403);
        }

        // プロンプトは保存値を使い、空なら既定文。
        $customPrompt = (string) $config->get('ai_title_prompt');
        if (trim($customPrompt) === '') {
            $customPrompt = "- Please give 5 suggestions.\n- Please answer in Japanese.";
        }

        $promptMessages = [
            [
                'role' => 'user',
                'content' => "Think about the title for this article.\n\ncondition:\n{$customPrompt}\n\n"
                    . "article: \"\"\"\n{$article}\n\"\"\""
            ]
        ];

        return $this->executeAiRequest(
            "You are a system that returns title suggestions as a JSON array. "
            . "Each element must have a \"content\" key with the title as value.",
            'title_suggestions',
            $promptMessages
        );
    }
}
