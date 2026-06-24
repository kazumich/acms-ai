<?php

namespace Acms\Plugins\AI\Services\AI\Support;

/**
 * 構造化出力（json_schema）に非対応なプロバイダ向けに、
 * 自由文レスポンスから {"items":[{"content":"..."}]} を取り出すヘルパー。
 *
 * コードフェンスや前後の説明文が混ざっていても寛容にパースする。
 */
class StructuredJson
{
    /**
     * タイトル/タグ生成で使う共通の出力指示。各プロバイダの system 指示へ付加する。
     */
    public const OUTPUT_INSTRUCTION =
        "\n\n## Output format (strict)\n" .
        "Respond with ONLY a JSON object of the exact form " .
        "{\"items\":[{\"content\":\"...\"},{\"content\":\"...\"}]} and nothing else. " .
        "Do not add explanations. Do not wrap the JSON in code fences.";

    /**
     * 自由文から items 配列を抽出する。
     *
     * @param string $raw モデルの応答テキスト
     * @return array<array{content: string}>
     * @throws \RuntimeException パースに失敗した場合
     */
    public static function extractItems(string $raw): array
    {
        $json = self::isolateJson($raw);
        $decoded = json_decode($json, true);

        if (!is_array($decoded) || !isset($decoded['items']) || !is_array($decoded['items'])) {
            throw new \RuntimeException('有効な形式のデータを取得できませんでした。: ' . mb_substr($raw, 0, 300));
        }

        $items = [];
        foreach ($decoded['items'] as $item) {
            if (is_array($item) && isset($item['content']) && is_string($item['content'])) {
                $items[] = ['content' => $item['content']];
            } elseif (is_string($item)) {
                $items[] = ['content' => $item];
            }
        }

        return $items;
    }

    /**
     * 応答テキストから JSON 部分だけを切り出す。
     * コードフェンスを除去し、最初の { から「括弧の対応が取れた最初の完全な
     * JSON オブジェクト」までを取り出す。
     *
     * モデルが JSON を重複出力する（例: {"items":[...]} を2回返す）ことがあり、
     * 単純に「最初の { 〜 最後の }」で切り出すと複数オブジェクトが連結されて
     * json_decode が失敗する。文字列・エスケープを考慮して走査し、最初に
     * 閉じたオブジェクトだけを返すことで、重複や末尾ゴミがあっても解析できる。
     */
    private static function isolateJson(string $raw): string
    {
        $text = trim($raw);

        // ```json ... ``` / ``` ... ``` のコードフェンスを除去
        $text = preg_replace('/^```[a-zA-Z]*\s*/', '', $text);
        $text = preg_replace('/\s*```$/', '', $text ?? '');
        $text = $text ?? '';

        $start = strpos($text, '{');
        if ($start === false) {
            return $text;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($text);
        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    // 最初の完全なオブジェクトを返す（以降の重複・余分は捨てる）
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        // 閉じ切らなかった場合は従来どおり最後の } までで救済する
        $end = strrpos($text, '}');
        if ($end !== false && $end > $start) {
            return substr($text, $start, $end - $start + 1);
        }

        return $text;
    }
}
