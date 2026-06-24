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
    private const SECRET_KEY_PATTERN = '/(api[_-]?key|authorization|bearer|token|secret|password|passwd)/i';

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

        \AcmsLogger::log($level, '[AI] ' . $message, $payload);
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
                $safe[$key] = '***MASKED***';
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
            $safe[$key] = $value;
        }
        return $safe;
    }
}
