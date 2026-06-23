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
     * コードフェンスを除去し、最初の { から最後の } までを取り出す。
     */
    private static function isolateJson(string $raw): string
    {
        $text = trim($raw);

        // ```json ... ``` / ``` ... ``` のコードフェンスを除去
        $text = preg_replace('/^```[a-zA-Z]*\s*/', '', $text);
        $text = preg_replace('/\s*```$/', '', $text ?? '');
        $text = $text ?? '';

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            return substr($text, $start, $end - $start + 1);
        }

        return $text;
    }
}
