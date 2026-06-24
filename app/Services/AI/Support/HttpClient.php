<?php

namespace Acms\Plugins\AI\Services\AI\Support;

/**
 * LLM API 呼び出し用の最小 HTTP クライアント。
 *
 * a-blog cms 標準の Http ファサードは cURL タイムアウトを MAX_EXECUTION_TIME に
 * 固定しており、生成系の長い待ちで切れることがあるため専用クライアントを用意する。
 * CURLINFO_RESPONSE_CODE を使うので HTTP/2 でもステータスを正しく取得できる。
 *
 * MediaAISupport/Support/HttpClient.php と同等の実装。
 */
class HttpClient
{
    /** @var int 既定の応答待ちタイムアウト（秒） */
    public const DEFAULT_TIMEOUT = 120;

    /**
     * JSON ボディを POST し、[ステータスコード, レスポンスボディ] を返す。
     *
     * @param string $url
     * @param array<string> $headers
     * @param string $jsonBody
     * @param int $timeout
     * @return array{0: int, 1: string}
     */
    public static function postJson(
        string $url,
        array $headers,
        string $jsonBody,
        int $timeout = self::DEFAULT_TIMEOUT
    ): array {
        return self::request($url, 'POST', $headers, $jsonBody, $timeout);
    }

    /**
     * GET し、[ステータスコード, レスポンスボディ] を返す。
     *
     * @param string $url
     * @param array<string> $headers
     * @param int $timeout
     * @return array{0: int, 1: string}
     */
    public static function get(string $url, array $headers = [], int $timeout = 30): array
    {
        return self::request($url, 'GET', $headers, null, $timeout);
    }

    /**
     * @param array<string> $headers
     * @param string|null $body
     * @return array{0: int, 1: string}
     * @throws \RuntimeException 接続失敗時
     */
    private static function request(
        string $url,
        string $method,
        array $headers,
        ?string $body,
        int $timeout
    ): array {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException('許可されていないURLスキームです。');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('cURL の初期化に失敗しました');
        }
        $opts = [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);

        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException('通信エラー: ' . $error);
        }
        return [$status, is_string($resp) ? $resp : ''];
    }
}
