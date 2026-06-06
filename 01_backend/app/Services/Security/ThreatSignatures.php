<?php

namespace App\Services\Security;

/**
 * AMIAL-SENTINEL-001 — توقيعات التهديد.
 *
 * مكتبة أنماط (regex) لاكتشاف محاولات الاختراق الشائعة في مدخلات الطلب.
 * كل توقيع: [code, weight, pattern]. الـ weight يساهم في نقاط الخطورة.
 *
 * ملاحظة: هذه طبقة كشف تكميلية (defense-in-depth) — لا تُغني عن
 * Prepared Statements (Eloquent يوفّرها) ولا عن التحقق من المدخلات.
 */
final class ThreatSignatures
{
    /**
     * أنماط في قيم/مفاتيح المدخلات (query + body).
     *
     * @return array<int, array{code:string, weight:int, pattern:string}>
     */
    public static function inputPatterns(): array
    {
        return [
            // SQL Injection
            ['code' => 'SQLI_UNION',      'weight' => 45, 'pattern' => '/\bunion\b[\s\S]{0,30}\bselect\b/i'],
            ['code' => 'SQLI_BOOLEAN',    'weight' => 35, 'pattern' => '/\bor\b\s+\d+\s*=\s*\d+/i'],
            ['code' => 'SQLI_COMMENT',    'weight' => 25, 'pattern' => '/(--\s|#|\/\*).*(\bor\b|\band\b|\bselect\b)/i'],
            ['code' => 'SQLI_STACKED',    'weight' => 40, 'pattern' => '/;\s*(drop|delete|update|insert|alter|truncate)\b/i'],
            ['code' => 'SQLI_FUNC',       'weight' => 30, 'pattern' => '/\b(sleep|benchmark|pg_sleep|waitfor\s+delay|load_file|information_schema)\b/i'],

            // XSS
            ['code' => 'XSS_SCRIPT',      'weight' => 40, 'pattern' => '/<\s*script\b/i'],
            ['code' => 'XSS_EVENT',       'weight' => 25, 'pattern' => '/\bon(error|load|click|mouseover)\s*=/i'],
            ['code' => 'XSS_JS_URI',      'weight' => 25, 'pattern' => '/javascript\s*:/i'],

            // Path Traversal / LFI
            ['code' => 'TRAVERSAL',       'weight' => 40, 'pattern' => '/(\.\.\/|\.\.\\\\){2,}/'],
            ['code' => 'LFI_WRAPPER',     'weight' => 45, 'pattern' => '/\b(php|file|expect|data|phar):\/\//i'],
            ['code' => 'SENSITIVE_FILE',  'weight' => 35, 'pattern' => '/(\/etc\/passwd|\/proc\/self|win\.ini|boot\.ini)/i'],

            // Command Injection
            ['code' => 'CMD_INJECT',      'weight' => 45, 'pattern' => '/[;|`]\s*(cat|ls|whoami|id|uname|wget|curl|nc|bash|sh)\b/i'],

            // Code / template injection
            ['code' => 'CODE_EVAL',       'weight' => 40, 'pattern' => '/\b(eval|base64_decode|system|exec|passthru|shell_exec)\s*\(/i'],
            ['code' => 'SSTI',            'weight' => 30, 'pattern' => '/\{\{\s*[\w\.]+\s*[\*\+]\s*\d+\s*\}\}/'],

            // NoSQL / object injection hints
            ['code' => 'NOSQL_OP',        'weight' => 25, 'pattern' => '/\$(ne|gt|lt|where|regex)\b/i'],
        ];
    }

    /**
     * مسارات "طُعم" (honeypot/bait) — لا يطلبها مستخدم شرعي للـ API أبداً.
     * أي طلب لها = فاحص/بوت/مهاجم بنسبة عالية.
     *
     * @return array<int, string>
     */
    public static function baitPaths(): array
    {
        return [
            '.env', '.git', '.aws', 'wp-login.php', 'wp-admin', 'xmlrpc.php',
            'phpmyadmin', 'pma', 'adminer.php', 'shell.php', 'config.php.bak',
            'backup.sql', 'db.sql', 'dump.sql', '.htaccess', 'web.config',
            'vendor/phpunit', 'eval-stdin.php', 'console', 'actuator/env',
            'solr/admin', 'struts', 'cgi-bin', 'phpinfo.php',
        ];
    }

    /**
     * بصمات أدوات الفحص الآلي في User-Agent.
     *
     * @return array<int, string>
     */
    public static function scannerUserAgents(): array
    {
        return [
            'sqlmap', 'nikto', 'nmap', 'masscan', 'nessus', 'acunetix',
            'wpscan', 'dirbuster', 'gobuster', 'fimap', 'havij', 'zgrab',
            'curl/', 'python-requests', 'go-http-client', 'libwww-perl',
        ];
    }
}
