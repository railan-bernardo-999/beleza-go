<?php

namespace App\Logging;

use Illuminate\Support\Facades\Log;

class SecurityLogger
{
    /**
     * Customização do logger (para o config/logging.php)
     */
    public function __invoke($logger)
    {
        // Configuração opcional de formato
        foreach ($logger->getHandlers() as $handler) {
            $handler->setFormatter(new \Monolog\Formatter\LineFormatter(
                "[%datetime%] %channel%.%level_name%: %message% %context%\n",
                "Y-m-d H:i:s"
            ));
        }
    }

    /**
     * Log de tentativa de registro
     */
    public static function registerAttempt($request, $email = null)
    {
        self::log('register_attempt', [
            'ip' => $request->ip(),
            'email' => $email ?? $request->email,
            'user_agent' => $request->userAgent()
        ]);
    }

    /**
     * Log de registro bem sucedido
     */
    public static function registerSuccess($user, $request)
    {
        self::log('register_success', [
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent()
        ]);
    }

    /**
     * Log de falha no registro
     */
    public static function registerFailed($request, $errors = [])
    {
        self::log('register_failed', [
            'ip' => $request->ip(),
            'email' => $request->email,
            'user_agent' => $request->userAgent(),
            'errors' => $errors
        ]);
    }

    /**
     * Log de tentativa de login
     */
    public static function loginAttempt($request)
    {
        self::log('login_attempt', [
            'ip' => $request->ip(),
            'email' => $request->email,
            'user_agent' => $request->userAgent()
        ]);
    }

    /**
     * Log de login bem sucedido
     */
    public static function loginSuccess($user, $request)
    {
        self::log('login_success', [
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent()
        ]);
    }

    /**
     * Log de falha no login
     */
    public static function loginFailed($request, $user = null)
    {
        self::log('login_failed', [
            'ip' => $request->ip(),
            'email' => $request->email,
            'user_id' => $user ? $user->id : null,
            'user_agent' => $request->userAgent()
        ]);
    }

    /**
     * Log de logout
     */
    public static function logout($user, $request)
    {
        self::log('logout', [
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => $request->ip()
        ]);
    }

    /**
     * Log de bloqueio de conta
     */
    public static function accountLocked($user, $request)
    {
        self::log('account_locked', [
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => $request->ip(),
            'failed_attempts' => $user->failed_login_attempts
        ]);
    }

    /**
     * Log de verificação de email
     */
    public static function emailVerified($user, $request)
    {
        self::log('email_verified', [
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => $request->ip()
        ]);
    }

    /**
     * Log de IP bloqueado
     */
    public static function ipBlocked($request, $reason)
    {
        self::log('ip_blocked', [
            'ip' => $request->ip(),
            'reason' => $reason,
            'user_agent' => $request->userAgent()
        ]);
    }

    /**
     * Log de atividade suspeita
     */
    public static function suspiciousActivity($request, $activity, $details = [])
    {
        self::log('suspicious_activity', array_merge([
            'ip' => $request->ip(),
            'activity' => $activity,
            'user_agent' => $request->userAgent()
        ], $details));
    }

    /**
     * Log de tentativa de email temporário
     */
    public static function temporaryEmailAttempt($request, $email)
    {
        self::log('temporary_email_attempt', [
            'ip' => $request->ip(),
            'email' => $email,
            'user_agent' => $request->userAgent()
        ]);
    }

    /**
     * Método base para log
     */
    private static function log($event, $data)
    {
        Log::channel('security')->info($event, array_merge($data, [
            'timestamp' => now()->toIso8601String(),
            'environment' => app()->environment()
        ]));
    }
}
