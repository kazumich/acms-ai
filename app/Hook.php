<?php

namespace Acms\Plugins\AI;

use Acms\Plugins\AI\Services\AI as ServicesAI;

class Hook
{
    /**
     * JSが更新された場合に、以前のバージョンで作られたキャッシュを使用しないようにキャッシュバスティングを行う
     * scriptタグでJSを読み込む際に、acmsのグローバル変数を経由する
     *
     * @param \Field &$globalVars
     */
    public function extendsGlobalVars(&$globalVars)
    {
        $globalVars->set(
            'AI_JS',
            cacheBusting(
                '/' . DIR_OFFSET . 'extension/plugins/AI/bundle/acms-ai.js',
                SCRIPT_DIR . '/extension/plugins/AI/bundle/acms-ai.js'
            )
        );

        $globalVars->set(
            'AI_CSS',
            cacheBusting(
                '/' . DIR_OFFSET . 'extension/plugins/AI/bundle/acms-ai.css',
                SCRIPT_DIR . '/extension/plugins/AI/bundle/acms-ai.css'
            )
        );

        // メディア管理画面の親スイッチと各フィールド有効フラグ。media/inject.html が
        // %{AI_VISION_VALID} / %{AI_VISION_VALID_*}（'1'/'0'）で参照する。BEGIN_MODULE を
        // 使わず波括弧を壊さないよう、テンプレ変数ではなくグローバル変数として渡す。
        if (defined('ADMIN') && ADMIN === 'media_index') {
            try {
                $config = (new ServicesAI())->getConfig();
                $visionEnabled = !empty($config->get('ai_vision_valid'));
                $globalVars->set('AI_VISION_VALID', $visionEnabled ? '1' : '0');
                foreach (['alt', 'caption', 'memo', 'filename', 'tags'] as $key) {
                    $globalVars->set(
                        'AI_VISION_VALID_' . strtoupper($key),
                        ($visionEnabled && !empty($config->get('ai_vision_valid_' . $key))) ? '1' : '0'
                    );
                }
            } catch (\Exception $e) {
            }
        }

        // AI 設定画面では、各プロバイダの API キー等が .env で設定済みかを
        // %{AI_*_FROM_ENV}（'1'/'0'）で渡す。main.html が入力欄を出すか
        // 「.env 設定済み」ラベルを出すかの出し分けに使う（キーをブラウザに送らない）。
        if (defined('ADMIN') && ADMIN === 'app_ai_index') {
            try {
                $service = new ServicesAI();
                $envFlags = [
                    'AI_OPENAI_KEY_FROM_ENV' => ['openai', 'apiKey'],
                    'AI_OPENAI_ORG_FROM_ENV' => ['openai', 'organizationId'],
                    'AI_OPENAI_PROJECT_FROM_ENV' => ['openai', 'projectId'],
                    'AI_ANTHROPIC_KEY_FROM_ENV' => ['anthropic', 'apiKey'],
                    'AI_GEMINI_KEY_FROM_ENV' => ['gemini', 'apiKey'],
                    'AI_SAKURA_KEY_FROM_ENV' => ['compat', 'apiKey'],
                ];
                foreach ($envFlags as $varName => $args) {
                    $globalVars->set($varName, $service->isFromEnv($args[0], $args[1]) ? '1' : '0');
                }
            } catch (\Exception $e) {
            }
        }
    }
}
