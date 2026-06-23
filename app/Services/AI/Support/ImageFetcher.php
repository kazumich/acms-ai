<?php

namespace Acms\Plugins\AI\Services\AI\Support;

/**
 * 同一サイト上のメディア画像 URL を取得し、base64 と MIME タイプを返す。
 *
 * メディアライブラリの画像は公開 URL で配信されているため HTTP GET で取得する。
 * vision API が扱える形式（jpeg / png / gif / webp）に限定する。
 *
 * MediaAISupport/Support/ImageFetcher.php と同等の実装。
 */
class ImageFetcher
{
    /** vision API が受け付ける MIME タイプ */
    private const ALLOWED = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** 取得を許可する最大バイト数（API 側の上限・メモリ保護） */
    private const MAX_BYTES = 8 * 1024 * 1024;

    /**
     * @param string $url 画像の絶対 URL（同一サイト想定）
     * @return array{0: string, 1: string} [base64データ, MIMEタイプ]
     * @throws \RuntimeException 取得失敗・非対応形式・サイズ超過時
     */
    public static function fetch(string $url): array
    {
        [$status, $body] = HttpClient::get($url, [], 30);
        if ($status >= 400 || $body === '') {
            throw new \RuntimeException('画像の取得に失敗しました (HTTP ' . $status . ')');
        }
        if (strlen($body) > self::MAX_BYTES) {
            throw new \RuntimeException('画像サイズが大きすぎます（8MB 以下にしてください）');
        }

        $mime = self::detectMime($body);
        if (!in_array($mime, self::ALLOWED, true)) {
            throw new \RuntimeException('対応していない画像形式です: ' . $mime);
        }

        return [base64_encode($body), $mime];
    }

    /**
     * バイト列から MIME タイプを判定する。
     */
    private static function detectMime(string $body): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_buffer($finfo, $body);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            }
        }
        // フォールバック: getimagesizefromstring
        $info = @getimagesizefromstring($body);
        if (is_array($info) && isset($info['mime'])) {
            return (string) $info['mime'];
        }
        return 'application/octet-stream';
    }
}
