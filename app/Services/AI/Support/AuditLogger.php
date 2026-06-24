<?php

namespace Acms\Plugins\AI\Services\AI\Support;

/**
 * AI プラグイン用の監査ログヘルパ。
 *
 * AcmsLogger は a-blog cms の audit_log テーブルへ保存されるため、AI 側では
 * action / provider / reason / status を揃え、秘密値を context に載せない。
 */
class AuditLogger
{
    private const MASK = '***MASKED***';
    private const SECRET_KEY_PATTERN = '/(api[_-]?key|authorization|bearer|token|secret|password|passwd)/i';
    private const POST_BODY_MASK_PATTERN =
        '/(api[_-]?key|authorization|bearer|token|secret|password|passwd|article|messages|input'
        . '|prompt|image[_-]?url|base[_-]?url|alreadygeneratedtags|addprompt)/i';
    private const MAX_STRING_LENGTH = 1000;

    /**
     * @param array<string, mixed> $context
     */
    public static function log(string $level, string $action, string $message, array $context = []): void
    {
        $payload = array_merge(
            [
                'plugin' => 'AI',
                'ai_action' => $action,
            ],
            self::sanitizeContext($context)
        );

        $originalPost = $_POST;
        $_POST = self::sanitizePostBody($originalPost);
        try {
            \AcmsLogger::log($level, '[AI] ' . $message, $payload);
        } finally {
            $_POST = $originalPost;
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function logForStatus(string $action, string $message, int $status, array $context = []): void
    {
        $context['status'] = $status;
        self::log($status >= 500 ? 'error' : 'notice', $action, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function error(string $action, string $message, array $context = []): void
    {
        self::log('error', $action, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function warning(string $action, string $message, array $context = []): void
    {
        self::log('warning', $action, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function notice(string $action, string $message, array $context = []): void
    {
        self::log('notice', $action, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function sanitizeContext(array $context): array
    {
        $safe = [];
        foreach ($context as $key => $value) {
            if (preg_match(self::SECRET_KEY_PATTERN, (string) $key)) {
                $safe[$key] = self::MASK;
                continue;
            }
            if (is_array($value)) {
                $safe[$key] = self::sanitizeContext($value);
                continue;
            }
            if (is_object($value)) {
                $safe[$key] = get_class($value);
                continue;
            }
            if (is_string($value)) {
                $safe[$key] = self::truncate($value);
                continue;
            }
            $safe[$key] = $value;
        }
        return $safe;
    }

    /**
     * @param array<string|int, mixed> $data
     * @return array<string|int, mixed>
     */
    private static function sanitizePostBody(array $data): array
    {
        $safe = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::POST_BODY_MASK_PATTERN, $key)) {
                $safe[$key] = self::MASK;
                continue;
            }
            if (is_array($value)) {
                $safe[$key] = self::sanitizePostBody($value);
                continue;
            }
            if (is_string($value)) {
                $safe[$key] = self::truncate($value);
                continue;
            }
            $safe[$key] = $value;
        }
        return $safe;
    }

    private static function truncate(string $value): string
    {
        if (mb_strlen($value) <= self::MAX_STRING_LENGTH) {
            return $value;
        }
        return mb_substr($value, 0, self::MAX_STRING_LENGTH) . '...';
    }
}
