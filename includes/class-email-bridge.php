<?php
/**
 * Bridge handler for dsgo.email.send().
 *
 * Wraps wp_mail() with recipient resolution, body sanitization, rate limiting,
 * and an audit log. The bridge never lets an app pick an arbitrary recipient
 * address — only the symbolic `admin` and `current_user` types declared in
 * the manifest's `email.recipients` allow-list are honored, and the parent
 * resolves them server-side.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class EmailBridge {

    public const RATE_LIMIT_PER_HOUR = 100;
    public const SUBJECT_MAX_LEN     = 200;
    public const BODY_MAX_BYTES      = 65536;

    /** @var \Closure|null Test seam: forces wp_mail() to behave deterministically. */
    private static ?\Closure $sender_override = null;

    public static function set_sender_for_tests(?\Closure $sender): void {
        self::$sender_override = $sender;
    }

    /**
     * @param array{to?:mixed,subject?:mixed,body?:mixed,isHtml?:mixed,replyTo?:mixed} $params
     * @return array{ok:bool,data?:array,code?:string,message?:string}
     */
    public static function send(Manifest $manifest, int $visitor_user_id, array $params): array {
        // 1. Permission gate — the REST route checks Permission::Email; here we
        //    additionally check that the requested recipient type is declared.
        if (!in_array(Permission::Email, $manifest->permissions_read, true)) {
            return ['ok' => false, 'code' => 'permission_denied',
                    'message' => 'app lacks "email" permission'];
        }

        // 2. Validate `to` and resolve to a real address.
        $to_raw = $params['to'] ?? null;
        if (!is_string($to_raw) || EmailRecipient::tryFrom($to_raw) === null) {
            return ['ok' => false, 'code' => 'invalid_params',
                    'message' => '"to" must be one of "admin", "current_user"'];
        }
        $to_type = EmailRecipient::from($to_raw);
        if (!in_array($to_type, $manifest->email_recipients, true)) {
            return ['ok' => false, 'code' => 'permission_denied',
                    'message' => sprintf('recipient type "%s" not declared in manifest.email.recipients', $to_type->value)];
        }

        $resolved = self::resolve_recipient($to_type, $visitor_user_id);
        if ($resolved === null) {
            return ['ok' => false, 'code' => 'not_authenticated',
                    'message' => '"to":"current_user" requires a logged-in visitor'];
        }
        if (!is_email($resolved)) {
            // Site admin email is misconfigured, or current user has no email
            // on file. Either way, we can't deliver — surface as internal_error
            // so the app sees a transient rather than a permission issue.
            return ['ok' => false, 'code' => 'internal_error',
                    'message' => 'resolved recipient is not a valid email address'];
        }

        // 3. Validate subject + body.
        $subject = $params['subject'] ?? null;
        if (!is_string($subject)) {
            return ['ok' => false, 'code' => 'invalid_params', 'message' => '"subject" must be a string'];
        }
        $subject = trim(wp_strip_all_tags($subject));
        if ($subject === '') {
            return ['ok' => false, 'code' => 'invalid_params', 'message' => '"subject" must not be empty'];
        }
        if (mb_strlen($subject) > self::SUBJECT_MAX_LEN) {
            return ['ok' => false, 'code' => 'invalid_params',
                    'message' => sprintf('"subject" must be at most %d characters', self::SUBJECT_MAX_LEN)];
        }

        $body = $params['body'] ?? null;
        if (!is_string($body)) {
            return ['ok' => false, 'code' => 'invalid_params', 'message' => '"body" must be a string'];
        }
        $is_html = !empty($params['isHtml']);
        $body_clean = $is_html ? wp_kses_post($body) : wp_strip_all_tags($body);
        if (strlen($body_clean) > self::BODY_MAX_BYTES) {
            return ['ok' => false, 'code' => 'invalid_params',
                    'message' => sprintf('"body" exceeds %d bytes after sanitization', self::BODY_MAX_BYTES)];
        }
        if (trim($body_clean) === '') {
            return ['ok' => false, 'code' => 'invalid_params', 'message' => '"body" must not be empty after sanitization'];
        }

        $reply_to = null;
        if (isset($params['replyTo'])) {
            if (!is_string($params['replyTo']) || !is_email($params['replyTo'])) {
                return ['ok' => false, 'code' => 'invalid_params',
                        'message' => '"replyTo" must be a valid email address'];
            }
            $reply_to = $params['replyTo'];
        }

        // 4. Rate limit check (per app, per site, per hour).
        if (self::is_rate_limited($manifest->id)) {
            return ['ok' => false, 'code' => 'rate_limited',
                    'message' => sprintf('app exceeded %d sends/hour', self::RATE_LIMIT_PER_HOUR)];
        }

        // 5. Compose final subject (with optional [App: Name] prefix).
        $final_subject = self::with_subject_prefix($manifest, $subject);

        // 6. Build headers. From comes from wp_mail_from filter (untouched).
        $headers = [];
        if ($is_html) {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
        }
        if ($reply_to !== null) {
            $headers[] = 'Reply-To: ' . $reply_to;
        }

        // 7. Send via wp_mail() — SMTP plugins hook this.
        $sent = self::dispatch_mail($resolved, $final_subject, $body_clean, $headers);

        // 8. Audit log + rate counter (always, regardless of outcome).
        self::record_send($manifest->id, $to_type, $resolved, $subject, $sent);

        if (!$sent) {
            return ['ok' => false, 'code' => 'internal_error',
                    'message' => 'wp_mail() failed (transport returned false)'];
        }
        return ['ok' => true, 'data' => ['sent' => true]];
    }

    private static function resolve_recipient(EmailRecipient $type, int $visitor_user_id): ?string {
        if ($type === EmailRecipient::Admin) {
            $admin = (string) get_option('admin_email', '');
            return $admin !== '' ? $admin : null;
        }
        if ($visitor_user_id <= 0) {
            return null;
        }
        $user = get_userdata($visitor_user_id);
        if (!$user || empty($user->user_email)) {
            return null;
        }
        return (string) $user->user_email;
    }

    private static function with_subject_prefix(Manifest $manifest, string $subject): string {
        $prefix_disabled = (bool) get_option('dsgo_apps_email_disable_prefix_' . $manifest->id, false);
        if ($prefix_disabled) {
            return $subject;
        }
        return sprintf('[App: %s] %s', $manifest->name, $subject);
    }

    private static function dispatch_mail(string $to, string $subject, string $body, array $headers): bool {
        if (self::$sender_override !== null) {
            return (bool) (self::$sender_override)($to, $subject, $body, $headers);
        }
        return (bool) wp_mail($to, $subject, $body, $headers);
    }

    private static function rate_counter_key(string $app_id): string {
        // Per-hour bucket so the limit naturally rolls over without a cron job.
        return sprintf('dsgo_email_rate_%s_%s', $app_id, gmdate('YmdH'));
    }

    private static function is_rate_limited(string $app_id): bool {
        $key   = self::rate_counter_key($app_id);
        $count = (int) get_transient($key);
        $cap   = (int) apply_filters('dsgo_apps_email_rate_limit_per_hour', self::RATE_LIMIT_PER_HOUR, $app_id);
        if ($count >= $cap) {
            return true;
        }
        set_transient($key, $count + 1, HOUR_IN_SECONDS + 60);
        return false;
    }

    private static function record_send(string $app_id, EmailRecipient $to_type, string $resolved, string $subject, bool $ok): void {
        $entry = [
            'app_id'         => $app_id,
            'recipient_type' => $to_type->value,
            'recipient_hash' => hash('sha256', $resolved),
            'subject'        => $subject,
            'sent'           => $ok,
            'timestamp'      => time(),
        ];
        // Trim the audit log to the most recent 200 entries per app to avoid
        // unbounded growth — site admins doing forensics can also pull from
        // SMTP plugin logs (Post SMTP/WP Mail SMTP keep their own).
        $log_key = 'dsgo_apps_email_log_' . $app_id;
        $log     = get_option($log_key, []);
        if (!is_array($log)) $log = [];
        $log[] = $entry;
        if (count($log) > 200) {
            $log = array_slice($log, -200);
        }
        update_option($log_key, $log, false);
        do_action('dsgo_apps_email_sent', $entry);
    }
}
