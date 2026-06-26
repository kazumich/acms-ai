<?php

namespace Acms\Plugins\AI\POST\AI;

use ACMS_POST;
use Acms\Plugins\AI\Services\AI as ServicesAI;
use Acms\Plugins\AI\Services\AI\Provider\ProviderFactory;
use Acms\Plugins\AI\Services\AI\Provider\VisionInterface;
use Acms\Plugins\AI\Services\AI\Support\AuditLogger;
use Acms\Plugins\AI\Services\AI\Support\ImageFetcher;

/**
 * メディア画像から複数フィールド（ファイル名・キャプション・代替テキスト・メモ・タグ）を
 * AI でまとめて生成する Ajax エンドポイント。
 * トリガ: ACMS_POST_AI_GenerateMediaFields
 *
 * 要求された項目のみを 1 回の vision 呼び出しで JSON 生成して返す。
 * API キーはサーバ側 config から読むため JS には露出しない。
 * （MediaAISupport の GenerateFields を acms-ai のプロバイダ抽象へ移植したもの）
 */
class GenerateMediaFields extends ACMS_POST
{
    /** 生成可能なフィールド */
    private const ALLOWED = ['file_name', 'caption', 'alt', 'memo', 'tags'];

    /** システムプロンプトの既定文（設定が空のとき使用） */
    private const DEFAULT_SYSTEM_PROMPT = 'あなたは画像のメタ情報を作成するアシスタントです。'
        . '指定されたキーだけを持つ JSON オブジェクトを1つだけ出力してください。'
        . 'コードフェンス・説明文・前後の文章は一切付けず、JSON のみを返します。';

    /** 項目別の指示文の既定（設定が空のとき使用）。JSON キーはコード側で付与する。 */
    private const DEFAULT_PROMPTS = [
        'alt' => '視覚障害のあるユーザー向けの簡潔で具体的な代替テキスト（日本語、120文字以内、「画像」などの前置きや引用符・改行なし）',
        'caption' => '画像の短いキャプション（日本語、100文字以内、1文程度）',
        'memo' => '管理者向けの内部メモ（日本語、200文字以内、被写体・用途・キーワードなど）',
        'file_name' => '内容を表す英小文字スラッグ（半角英数字とハイフンのみ。拡張子は書かない／元の拡張子は自動で保持。60文字以内、例: business-woman-office）',
        'tags' => '画像内容を表す日本語タグの配列（5個程度、各タグは10文字以内の短い語、JSON 配列）',
    ];

    /** 各項目の指示文を保持する config キー */
    private const PROMPT_CONFIG_KEYS = [
        'alt' => 'ai_vision_prompt_alt',
        'caption' => 'ai_vision_prompt_caption',
        'memo' => 'ai_vision_prompt_memo',
        'file_name' => 'ai_vision_prompt_filename',
        'tags' => 'ai_vision_prompt_tags',
    ];

    /** 各項目の有効・無効（管理者設定）を保持する config キー */
    private const VALID_CONFIG_KEYS = [
        'alt' => 'ai_vision_valid_alt',
        'caption' => 'ai_vision_valid_caption',
        'memo' => 'ai_vision_valid_memo',
        'file_name' => 'ai_vision_valid_filename',
        'tags' => 'ai_vision_valid_tags',
    ];

    /** 暴走した応答を防ぐための固定の安全上限（ユーザー向けの文字数指定はプロンプトで行う） */
    private const SAFETY_TEXT_MAX = 1000;
    private const SAFETY_SLUG_MAX = 80;
    private const SAFETY_TAG_MAX = 30;
    private const SAFETY_TAG_COUNT = 20;

    public function post()
    {
        // 管理者のみ
        if (!sessionWithAdministration()) {
            $this->respond(403, ['error' => '権限がありません'], ['reason' => 'permission_denied']);
            return $this->Post;
        }

        // CSRF
        if ($this->csrfTokenExists() && !$this->checkCsrfToken()) {
            $this->respond(403, ['error' => '不正なトークンです'], ['reason' => 'invalid_csrf_token']);
            return $this->Post;
        }

        $imageUrl = trim((string) $this->Post->get('image_url', ''));
        if ($imageUrl === '') {
            $this->respond(400, ['error' => '画像 URL が指定されていません'], ['reason' => 'missing_image_url']);
            return $this->Post;
        }
        if (!$this->isSameOrigin($imageUrl)) {
            $this->respond(400, ['error' => 'このサイト上の画像のみ対象にできます'], [
                'reason' => 'image_url_not_same_origin',
                'image_url_host' => (string) parse_url($imageUrl, PHP_URL_HOST),
            ]);
            return $this->Post;
        }

        // 生成対象（カンマ区切り）。許可リストで絞り込む。
        $rawTargets = (string) $this->Post->get('targets', '');
        $targets = array_values(array_filter(
            array_map('trim', explode(',', $rawTargets)),
            function ($t) {
                return in_array($t, self::ALLOWED, true);
            }
        ));
        if (count($targets) === 0) {
            $this->respond(400, ['error' => '生成する項目が選択されていません'], [
                'reason' => 'no_targets',
            ]);
            return $this->Post;
        }

        $config = (new ServicesAI())->getConfig();

        if (empty($config->get('ai_vision_valid'))) {
            $this->respond(403, ['error' => 'メディアAI生成は管理画面で有効化されていません。'], [
                'reason' => 'feature_disabled',
            ]);
            return $this->Post;
        }

        // 管理者が「メディア プロンプト設定」で無効にした項目を除外する
        $targets = array_values(array_filter($targets, function ($t) use ($config) {
            return !empty($config->get(self::VALID_CONFIG_KEYS[$t]));
        }));
        if (count($targets) === 0) {
            $this->respond(400, ['error' => '有効な生成項目がありません（管理画面のメディア プロンプト設定で有効化してください）'], [
                'reason' => 'no_enabled_targets',
                'requested_targets' => $rawTargets,
            ]);
            return $this->Post;
        }

        // 要求項目ごとの指示文（設定値があれば使用、空なら内蔵既定）。JSON キーはコード側で付与。
        $lines = [];
        foreach ($targets as $t) {
            $instruction = trim((string) $config->get(self::PROMPT_CONFIG_KEYS[$t]));
            if ($instruction === '') {
                $instruction = self::DEFAULT_PROMPTS[$t];
            }
            $lines[] = '- "' . $t . '": ' . $instruction;
        }

        $systemPrompt = trim((string) $config->get('ai_vision_system_prompt'));
        if ($systemPrompt === '') {
            $systemPrompt = self::DEFAULT_SYSTEM_PROMPT;
        }
        $userPrompt = "次の画像について、以下のキーを含む JSON オブジェクトを生成してください。\n"
            . implode("\n", $lines);

        try {
            $provider = ProviderFactory::createForVision();
            if (!$provider instanceof VisionInterface) {
                $this->respond(400, ['error' => '選択中のAIプロバイダは画像解析（vision）に対応していません'], [
                    'reason' => 'unsupported_provider',
                ]);
                return $this->Post;
            }
            [$base64, $mediaType] = ImageFetcher::fetch($imageUrl);
            $raw = $provider->describeImage($systemPrompt, $userPrompt, $base64, $mediaType);
        } catch (\Throwable $e) {
            $this->respond(400, ['error' => '画像解析に失敗しました。'], [
                'reason' => $e->getMessage(),
                'exception' => get_class($e),
                'targets' => $targets,
            ]);
            return $this->Post;
        }

        $data = $this->decodeJson($raw);
        if ($data === null) {
            $this->respond(502, ['error' => 'AI 応答の解析に失敗しました。'], [
                'reason' => 'invalid_ai_response',
                'targets' => $targets,
            ]);
            return $this->Post;
        }

        // 要求項目だけを正規化して返す
        $result = [];
        foreach ($targets as $t) {
            // タグは文字列配列として扱う
            if ($t === 'tags') {
                $tags = $this->normalizeTags($data['tags'] ?? null);
                if (count($tags) > 0) {
                    $result['tags'] = $tags;
                }
                continue;
            }
            if (!isset($data[$t]) || !is_string($data[$t])) {
                continue;
            }
            $value = $data[$t];
            switch ($t) {
                case 'file_name':
                    $value = $this->slugify($value);
                    break;
                case 'alt':
                case 'caption':
                    $value = $this->normalizeText($value);
                    break;
                case 'memo':
                    // メモは改行を許容（前後トリムと安全上限のみ）
                    $value = mb_substr(trim($value), 0, self::SAFETY_TEXT_MAX);
                    break;
            }
            if ($value !== '') {
                $result[$t] = $value;
            }
        }

        if (count($result) === 0) {
            $this->respond(502, ['error' => 'AI から有効な値を取得できませんでした'], [
                'reason' => 'empty_ai_result',
                'targets' => $targets,
            ]);
            return $this->Post;
        }

        $this->respond(200, ['fields' => $result]);
        return $this->Post;
    }

    /**
     * 応答テキストから JSON オブジェクトを取り出してデコードする（コードフェンス対応）。
     *
     * @return array<string, mixed>|null
     */
    private function decodeJson(string $raw): ?array
    {
        $text = trim($raw);
        // ```json ... ``` のコードフェンスを除去
        $text = preg_replace('/^```[a-zA-Z]*\s*|\s*```$/u', '', $text) ?? $text;
        // 最初の { から最後の } までを抽出
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $text = substr($text, $start, $end - $start + 1);
        }
        $data = json_decode($text, true);
        return is_array($data) ? $data : null;
    }

    /**
     * 代替テキスト・キャプション向けの整形（前後空白・囲み引用符の除去、改行→空白、安全上限）。
     */
    private function normalizeText(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/\s*\R\s*/u', ' ', $text) ?? $text;
        $text = preg_replace('/^["“”\'「『](.*)["“”\'」』]$/u', '$1', $text) ?? $text;
        $text = trim($text);
        return mb_substr($text, 0, self::SAFETY_TEXT_MAX);
    }

    /**
     * ファイル名スラッグへ正規化（半角英小文字・数字・ハイフンのみ、拡張子なし）。
     */
    private function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        // 拡張子っぽい末尾を除去（保険）
        $text = preg_replace('/\.(jpe?g|png|gif|webp)$/i', '', $text) ?? $text;
        // 英数とハイフン以外をハイフンに
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? $text;
        $text = trim($text, '-');
        if ($text === '') {
            return '';
        }
        $text = mb_substr($text, 0, self::SAFETY_SLUG_MAX);
        return trim($text, '-');
    }

    /**
     * タグ配列を正規化する（文字列のみ・前後空白除去・空除去・長さ/件数制限・重複除去）。
     *
     * @param mixed $raw
     * @return string[]
     */
    private function normalizeTags($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $tags = [];
        foreach ($raw as $tag) {
            if (!is_string($tag)) {
                continue;
            }
            $tag = trim($tag);
            // タグに使えない記号（カンマ等）を除去：media_label がカンマ区切りのため
            $tag = str_replace(',', ' ', $tag);
            $tag = trim($tag);
            if ($tag === '') {
                continue;
            }
            $tags[] = mb_substr($tag, 0, self::SAFETY_TAG_MAX);
        }
        $tags = array_values(array_unique($tags));
        return array_slice($tags, 0, self::SAFETY_TAG_COUNT);
    }

    /**
     * URL が現在のサイトと同一オリジンか判定する。
     */
    private function isSameOrigin(string $url): bool
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return false;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }

        $host = $parts['host'] ?? null;
        if ($host === null || $host === '') {
            return strpos($url, '/') === 0 && strpos($url, '//') !== 0;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $currentHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $currentHost = preg_replace('/:\d+$/', '', $currentHost) ?? $currentHost;
        return strcasecmp($host, $currentHost) === 0;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $context
     */
    private function respond(int $code, array $body, array $context = []): void
    {
        http_response_code($code);
        if ($code >= 400) {
            AuditLogger::logForStatus(
                'ai_generate_media_fields',
                (string) ($body['error'] ?? 'メディアAI生成に失敗しました。'),
                $code,
                $context
            );
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_UNICODE);
        die();
    }
}
