<?php

namespace Acms\Plugins\AI;

use ACMS_App;
use Storage;
use Acms\Services\Common\HookFactory;
use Acms\Services\Common\InjectTemplate;

class ServiceProvider extends ACMS_App
{
    /**
     * @var string
     */
    public $version = '1.2.6';

    /**
     * @var string
     */
    public $name = 'AI';

    /**
     * @var string
     */
    public $author = 'com.appleple';

    /**
     * @var bool
     */
    public $module = false;

    /**
     * @var false|string
     */
    public $menu = 'ai_index';

    /**
     * @var string
     */
    public $desc = 'OpenAI / Claude / Gemini / さくらのAI Engine（OpenAI互換）を利用したAI機能が使えます。';

    /**
     * サービスの初期処理
     */
    public function init()
    {
        // Hook追加
        $hook = HookFactory::singleton();
        $hook->attach('AIHook', new Hook());

        // テンプレート追加
        $inject = InjectTemplate::singleton();
        $inject->add('admin-module-select', PLUGIN_DIR . 'AI/template/module/select.html');
        $inject->add('admin-module-config-Sample', PLUGIN_DIR . 'AI/template/config.html');
        // エントリー編集の AI 機能 UI。これは BEGIN_MODULE 経由で {title_enabled} 等を
        // 解決する必要があり、かつインライン JS を持たないため module 化したまま。
        $inject->add('admin-entry-field', PLUGIN_DIR . 'AI/template/admin/entry/edit.html');

        // loader / メディア inject は素の <script> として注入する（BEGIN_MODULE で包むと
        // Template::render() が JS 内の波括弧を壊すため）。authorized 判定は PHP 側で行う。
        $authorized = false;
        $visionAuthorized = false;
        try {
            $serviceAI = new Services\AI();
            $config = $serviceAI->getConfig();
            $authorized = $serviceAI->isAuthorized($config);
            $visionAuthorized = $serviceAI->isAuthorized($config, 'vision');
        } catch (\Exception $e) {
        }

        // 全管理画面共通ローダー。<acms-ai-assistant-button> がある画面だけ本体を遅延ロードする。
        if ($authorized) {
            $inject->add('admin-main', PLUGIN_DIR . 'AI/template/admin/loader.html');
        }

        // メディア管理画面では、画像から各フィールドを生成する操作列を注入する。
        if (ADMIN === 'media_index' && $visionAuthorized) {
            $inject->add('admin-main', PLUGIN_DIR . 'AI/template/admin/media/inject.html');
        }

        if (ADMIN === 'app_' . $this->menu) {
            $inject->add('admin-main', PLUGIN_DIR . 'AI/template/admin/main.html');
        }
    }

    /**
     * インストールする前の環境チェック処理
     *
     * @return bool
     */
    public function checkRequirements()
    {
        return true;
    }

    /**
     * インストールするときの処理。
     * 既定プロンプト等を config.system.yaml へ追記する（#BEGIN_AIConfig〜#END_AIConfig）。
     *
     * @return void
     */
    public function install()
    {
        $config = Storage::get(CONFIG_FILE);
        $pluginConfig = Storage::get(PLUGIN_LIB_DIR . $this->name . '/config.system.yaml');
        if (!$pluginConfig) {
            return;
        }
        if (preg_match('/(#BEGIN_AIConfig)[\s\S]*(#END_AIConfig)/', $config)) {
            // 既存ブロックを置換（再インストール時の二重記述を防ぐ）
            Storage::put(
                CONFIG_FILE,
                preg_replace('/(#BEGIN_AIConfig)[\s\S]*(#END_AIConfig)/', $pluginConfig, $config)
            );
        } else {
            Storage::put(CONFIG_FILE, $config . "\n" . $pluginConfig);
        }
    }

    /**
     * アンインストールするときの処理。
     * config.system.yaml に追記した設定ブロックを取り除く。
     *
     * @return void
     */
    public function uninstall()
    {
        $config = Storage::get(CONFIG_FILE);
        if ($config && preg_match('/(#BEGIN_AIConfig)[\s\S]*(#END_AIConfig)/', $config)) {
            Storage::put(
                CONFIG_FILE,
                preg_replace('/\n?(#BEGIN_AIConfig)[\s\S]*(#END_AIConfig)\n?/', "\n", $config)
            );
        }
    }

    /**
     * アップデートするときの処理
     *
     * @return bool
     */
    public function update()
    {
        return true;
    }

    /**
     * 有効化するときの処理
     *
     * @return bool
     */
    public function activate()
    {
        return true;
    }

    /**
     * 無効化するときの処理
     *
     * @return bool
     */
    public function deactivate()
    {
        return true;
    }
}
