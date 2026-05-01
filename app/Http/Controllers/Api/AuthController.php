<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\VerifyEmail;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

class AuthController extends Controller
{
    use ApiResponse;

    /**
     * Taxa de tentativas de registro por IP (por hora)
     */
    private const REGISTER_ATTEMPTS = 5;
    private const REGISTER_DECAY_MINUTES = 60;

    /**
     * Taxa de tentativas de login por IP/email
     */
    private const LOGIN_ATTEMPTS = 5;
    private const LOGIN_DECAY_MINUTES = 15;

    /**
     * Registrar novo usuário
     */
    public function register(Request $request)
    {

        // Rate limiting por IP
        $rateLimitCheck = $this->checkRegisterRateLimit($request);
        if ($rateLimitCheck) {
            return $rateLimitCheck;
        }

        // Verificar IP bloqueado
        if ($this->isIpBlocked($request)) {
            Log::channel('security')->warning('Tentativa de registro com IP bloqueado', [
                'ip' => $request->ip()
            ]);
            return $this->error('Acesso temporariamente bloqueado', 429);
        }

        // Log de tentativa
        $this->logSecurityEvent('register_attempt', [
            'ip' => $request->ip(),
            'email' => $request->email,
            'user_agent' => $request->userAgent()
        ]);

        // Lock para prevenir race condition em emails duplicados
        $lock = Cache::lock('email-register:' . $request->email, 10);

        try {
            if (!$lock->get()) {
                return $this->error('Processando registro, tente novamente', 429, 'Servidor ocupado');
            }

            // Validação rigorosa
            $validated = $this->validateRegistration($request);

            // Verificar email malicioso (domínios temporários)
            if ($this->isTemporaryEmail($request->email)) {
                $this->logSecurityEvent('temporary_email_attempt', [
                    'ip' => $request->ip(),
                    'email' => $request->email
                ]);
                return $this->error('Email inválido', 422, 'Use um email permanente');
            }

            DB::beginTransaction();

            // Criar usuário com campos de segurança
            $user = User::create([
                'name' => $this->sanitizeString($request->name),
                'email' => $this->sanitizeEmail($request->email),
                'username' => $this->sanitizeString($request->username),
                'phone' => $request->phone ? $this->sanitizeString($request->phone) : null,
                'birthday' => $this->sanitizeString($request->birthday),
                'country' => $this->sanitizeString($request->country),
                'state' => $this->sanitizeString($request->state),
                'city' => $this->sanitizeString($request->city),
                'password' => $this->hashPassword($request->password),
                'email_verified_at' => null,
                'verification_token' => Str::random(64),
                'remember_token' => Str::random(60),
                'ip_registered' => $request->ip(),
                'user_agent_registered' => $this->truncateUserAgent($request->userAgent()),
                'last_login_at' => null,
                'last_login_ip' => null,
                'account_status' => 'pending',
                'failed_login_attempts' => 0,
                'locked_until' => null
            ]);



            DB::commit();

            // Remover lock
            $lock->release();

            // Enviar email de verificação
            $this->sendVerificationEmail($user);

            // Limpar tentativas de rate limiting
            RateLimiter::clear('register-attempts:' . $request->ip());

            // Disparar evento de registro
            event(new \App\Events\UserRegistered($user));

            // Gerar token com escopo limitado inicialmente
            $token = $user->createToken('api-token', ['basic-access'])->plainTextToken;

            // Log de sucesso
            $this->logSecurityEvent('register_success', [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip' => $request->ip()
            ]);

            return $this->success([
                'user' => $this->formatUserData($user),
                'token' => $token,
                'token_type' => 'Bearer',
                'requires_verification' => true,
                'message' => 'Verifique seu email para ativar sua conta'
            ], 'Registro realizado com sucesso! Verifique seu email.', 201);

        } catch (ValidationException $e) {
            DB::rollBack();
            $lock->release();

            // Registrar falha de validação
            $this->logSecurityEvent('register_validation_failed', [
                'ip' => $request->ip(),
                'email' => $request->email,
                'errors' => array_keys($e->errors())
            ]);

            return $this->error('Erro de validação', 422, $e->errors());

        } catch (\Exception $e) {
            DB::rollBack();
            $lock->release();

            // Log crítico para debugging
            Log::channel('security')->critical('Erro crítico no registro', [
                'ip' => $request->ip(),
                'email' => $request->email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->error('Erro interno ao processar registro', 500, 'Erro interno');
        }
    }

    /**
     * Login de usuário
     */
    public function login(Request $request)
    {
        // Rate limiting por IP e email
        $rateLimitCheck = $this->checkLoginRateLimit($request);
        if ($rateLimitCheck) {
            return $rateLimitCheck;
        }
        // Verificar IP bloqueado
        if ($this->isIpBlocked($request)) {
            return $this->error('Acesso temporariamente bloqueado', 429, 'Muitas tentativas');
        }

        // Validação básica
        try {
            $request->validate([
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', 'max:255'],
            ], [
                'email.required' => 'O email é obrigatório.',
                'email.email' => 'Email inválido.',
                'password.required' => 'A senha é obrigatória.',
            ]);
        } catch (ValidationException $e) {
            return $this->error('Erro de validação', 422, $e->errors());
        }

        // Buscar usuário com segurança
        $user = User::where('email', $this->sanitizeEmail($request->email))
            ->where('account_status', '!=', 'deleted')
            ->first();

        // Verificar se conta está bloqueada
        if ($user && $this->isAccountLocked($user)) {
            $lockedUntil = $user->locked_until ? $user->locked_until->diffForHumans() : 'alguns minutos';
            return $this->error('Conta temporariamente bloqueada', "Muitas tentativas. Tente novamente em {$lockedUntil}", 429);
        }

        // Verificar status da conta
        if ($user && $user->account_status === 'banned') {
            $this->logSecurityEvent('banned_account_login_attempt', [
                'user_id' => $user->id,
                'ip' => $request->ip()
            ]);
            return $this->error('Conta bloqueada', 403, 'Esta conta foi bloqueada');
        }

        // Verificar credenciais
        if (!$user || !Hash::check($request->password, $user->password)) {
            // Registrar falha de login
            $this->recordFailedLogin($user, $request);
            return $this->error('Credenciais incorretas', 401, 'As credenciais informadas estão incorretas');
        }

        // Verificar se email foi verificado
        if ($user->account_status === 'pending') {
            return $this->error('Email não verificado', 403, 'Por favor, verifique seu email antes de fazer login');
        }

        // Login bem sucedido - resetar contadores
        $this->resetLoginAttempts($user);

        // Atualizar dados de última sessão
        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
            'remember_token' => Str::random(60)
        ]);

        // 11. Revogar tokens antigos (opcional - segurança)
        // $user->tokens()->where('created_at', '<', now()->subDays(30))->delete();

        // Gerar novo token com permissões
        $token = $user->createToken('auth-token', ['full-access'])->plainTextToken;

        // Log de sucesso
        $this->logSecurityEvent('login_success', [
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => $request->ip()
        ]);

        // Limpar rate limiting
        RateLimiter::clear($this->getLoginKey($request));

        return $this->success([
            'user' => $this->formatUserData($user),
            'token' => $token,
            'token_type' => 'Bearer'
        ], 'Login realizado com sucesso');
    }

    /**
     * Logout do dispositivo atual
     */
    public function logout(Request $request)
    {
        try {
            $user = $request->user();
            $tokenId = $request->user()->currentAccessToken()->id;

            $request->user()->currentAccessToken()->delete();

            $this->logSecurityEvent('logout', [
                'user_id' => $user->id,
                'token_id' => $tokenId,
                'ip' => $request->ip()
            ]);

            return $this->success(null, 'Logout realizado com sucesso');
        } catch (\Exception $e) {
            return $this->error('Erro ao fazer logout', 500, 'Erro interno');
        }
    }

    /**
     * Logout de todos os dispositivos
     */
    public function logoutAll(Request $request)
    {
        try {
            $user = $request->user();
            $tokensCount = $user->tokens()->count();

            $user->tokens()->delete();

            $this->logSecurityEvent('logout_all', [
                'user_id' => $user->id,
                'tokens_count' => $tokensCount,
                'ip' => $request->ip()
            ]);

            return $this->success(null, 'Logout de todos os dispositivos realizado');
        } catch (\Exception $e) {
            return $this->error('Erro ao fazer logout', 500, 'Erro interno');
        }
    }

    /**
     * Obter dados do usuário autenticado
     */
    public function me(Request $request)
    {
        $user = $request->user();
        return $this->success($this->formatUserData($user), 'Dados do usuário');
    }

    /**
     * Enviar email de verificação
     */
    private function sendVerificationEmail(User $user): void
    {
        try {
            // Envia o email com link para o frontend Next.js
            Mail::to($user->email)->send(new VerifyEmail($user));

            Log::info('Email de verificação enviado', [
                'user_id' => $user->id,
                'email' => $user->email
            ]);
        } catch (\Exception $e) {
            Log::error('Falha ao enviar email de verificação', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage()
            ]);

            // Não relançar a exceção para não quebrar o registro
            // O usuário pode pedir reenvio depois
        }
    }

    /**
     * Verificar email (após link de verificação)
     */
    public function verifyEmail(Request $request)
    {
        $request->validate([
            'token' => ['required', 'string']
        ]);

        $user = User::where('verification_token', $request->token)
            ->whereNull('email_verified_at')
            ->first();

        if (!$user) {
            return $this->error('Token inválido ou já utilizado', 400, 'Token inválido');
        }

        $user->update([
            'email_verified_at' => now(),
            'verification_token' => null,
            'account_status' => 'active'
        ]);

        $this->logSecurityEvent('email_verified', [
            'user_id' => $user->id,
            'email' => $user->email
        ]);

        return $this->success(null, 'Email verificado com sucesso! Agora você pode fazer login.');
    }

    /**
     * Reenviar link de verificação
     */
    public function resendVerification(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email']
        ]);

        $user = User::where('email', $this->sanitizeEmail($request->email))
            ->whereNull('email_verified_at')
            ->first();

        if (!$user) {
            // Não revelar se o email existe ou não por segurança
            return $this->success(null, 'Se o email existir e não estiver verificado, um novo link foi enviado');
        }

        // Limitar reenvios
        $key = 'resend-verification:' . $user->id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);
            return $this->error("Aguarde {$seconds} segundos antes de reenviar", 429, 'Muitas tentativas');
        }

        RateLimiter::hit($key, 300); // 5 minutos

        $user->verification_token = Str::random(64);
        $user->save();

        event(new \App\Events\UserRegistered($user));

        return $this->success(null, 'Novo link de verificação enviado para seu email');
    }

    // ============ MÉTODOS PRIVADOS DE SEGURANÇA ============

    /**
     * Validar registro
     */
    private function validateRegistration(Request $request): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:100', // Reduzido de 255
                'regex:/^[\pL\s\-]+$/u',
                'min:2'
            ],
            'username' => [
                'required',
                'string',
                'max:50',
                'regex:/^[a-zA-Z0-9\-]+$/u',
                'unique:users,username'
            ],
            'email' => [
                'required',
                'string',
                'email:rfc,dns',
                'max:100', // Reduzido de 255
                'unique:users,email',
                'regex:/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/'
            ],
            'password' => [
                'required',
                'confirmed',
                'string',
                'min:8',
                'max:64',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/'
            ],
            'phone' => [
                'nullable',
                'string',
                'max:20',
                'regex:/^\+?[0-9\s\-()]+$/'
            ],
            'birthday' => [
                'required',
                'date',
                'before:today'
            ],
            'country' => [
                'required',
                'string',
                'max:100'
            ],
            'state' => [
                'required',
                'string',
                'max:100'
            ],
            'city' => [
                'required',
                'string',
                'max:100'
            ]
        ], [
            'name.required' => 'O nome é obrigatório.',
            'name.max' => 'O nome não pode exceder 100 caracteres.',
            'name.regex' => 'O nome deve conter apenas letras, espaços e hífens.',
            'name.min' => 'O nome deve ter pelo menos 2 caracteres.',
            'username.required' => 'O nome de usuário é obrigatório.',
            'username.max' => 'O nome de usuário não pode exceder 50 caracteres.',
            'username.regex' => 'O nome de usuário pode conter apenas letras, números e hífens.',
            'username.unique' => 'Este nome de usuário já está em uso.',
            'email.required' => 'O email é obrigatório.',
            'email.unique' => 'Este email já está registrado.',
            'email.email' => 'Digite um email válido.',
            'email.max' => 'Email muito longo.',
            'password.regex' => 'A senha deve conter pelo menos uma letra maiúscula, uma minúscula, um número e um caractere especial.',
            'password.confirmed' => 'A confirmação da senha não corresponde.',
            'password.min' => 'A senha deve ter no mínimo 8 caracteres.',
            'password.max' => 'A senha não pode exceder 64 caracteres.',
            'phone.regex' => 'Número de telefone inválido.',
            'birthday.date' => 'Data de nascimento inválida.',
            'birthday.required' => 'A data de nascimento é obrigatória.',
            'birthday.before' => 'A data de nascimento deve ser anterior a hoje.',
            'country.required' => 'O país é obrigatório.',
            'state.required' => 'O estado é obrigatório.',
            'city.required' => 'A cidade é obrigatória.'
        ]);
    }

    /**
     * Verificar rate limit de registro
     */
    private function checkRegisterRateLimit(Request $request): ?JsonResponse
    {
        $key = 'register-attempts:' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, self::REGISTER_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);
             $minutes = round($seconds / 60, 1);
            return $this->error("Muitas tentativas. Tente novamente em {$minutes} minutos.", 429);
        }

        RateLimiter::hit($key, self::REGISTER_DECAY_MINUTES * 60);
        return null;
    }

    /**
     * Verificar rate limit de login
     */
    private function checkLoginRateLimit(Request $request): ?JsonResponse
    {
        $key = $this->getLoginKey($request);

        if (RateLimiter::tooManyAttempts($key, self::LOGIN_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);
             $minutes = round($seconds / 60, 1);
            return $this->error("Muitas tentativas. Tente novamente em {$minutes} minutos.", 429);
        }

        RateLimiter::hit($key, self::LOGIN_DECAY_MINUTES * 60);
        return null;
    }

    /**
     * Obter chave de rate limit de login
     */
    private function getLoginKey(Request $request): string
    {
        return 'login-attempts:' . $request->ip() . ':' . $this->sanitizeEmail($request->email);
    }

    /**
     * Verificar se IP está bloqueado
     */
    private function isIpBlocked(Request $request): bool
    {
        $key = 'blocked-ip:' . $request->ip();
        return Cache::has($key);
    }

    /**
     * Verificar se conta está bloqueada
     */
    private function isAccountLocked(User $user): bool
    {
        if (!$user->locked_until) {
            return false;
        }

        if (now()->lt($user->locked_until)) {
            return true;
        }

        // Bloqueio expirou, resetar
        $user->update([
            'locked_until' => null,
            'failed_login_attempts' => 0
        ]);

        return false;
    }

    /**
     * Registrar falha de login
     */
    private function recordFailedLogin(?User $user, Request $request): void
    {
        $key = 'failed-login:' . ($user ? $user->id : $request->ip());
        $attempts = Cache::increment($key);

        if ($user) {
            $user->increment('failed_login_attempts');

            // Bloquear conta após 10 tentativas
            if ($user->failed_login_attempts >= 10) {
                $user->update([
                    'locked_until' => now()->addMinutes(30),
                    'account_status' => 'locked'
                ]);

                $this->logSecurityEvent('account_locked', [
                    'user_id' => $user->id,
                    'ip' => $request->ip()
                ]);
            }
        }

        Cache::put($key, $attempts, 3600);

        // Bloquear IP após muitas tentativas
        if ($attempts >= 20) {
            Cache::put('blocked-ip:' . $request->ip(), true, 3600);
        }

        $this->logSecurityEvent('login_failed', [
            'email' => $request->email,
            'ip' => $request->ip(),
            'user_id' => $user ? $user->id : null,
            'attempts' => $attempts
        ]);
    }

    /**
     * Resetar tentativas de login após sucesso
     */
    private function resetLoginAttempts(User $user): void
    {
        $user->update([
            'failed_login_attempts' => 0,
            'locked_until' => null
        ]);

        Cache::forget('failed-login:' . $user->id);
    }

    /**
     * Verificar email temporário
     */
    private function isTemporaryEmail(string $email): bool
    {
        $domain = substr(strrchr($email, "@"), 1);

        // Lista de domínios temporários conhecidos
        $tempDomains = [
            'tempmail.com',
            '10minutemail.com',
            'guerrillamail.com',
            'mailinator.com',
            'yopmail.com',
            'throwawaymail.com',
            'temp-mail.org',
            'fakeinbox.com',
            'trashmail.com'
        ];

        return in_array($domain, $tempDomains);
    }

    /**
     * Sanitizar string
     */
    private function sanitizeString(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $cleaned = trim($input);
        $cleaned = strip_tags($cleaned);
        $cleaned = htmlspecialchars($cleaned, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Limitar comprimento
        return substr($cleaned, 0, 255);
    }

    /**
     * Sanitizar email
     */
    private function sanitizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $email = trim($email);
        $email = filter_var($email, FILTER_SANITIZE_EMAIL);

        return strtolower($email);
    }

    /**
     * Hash de senha com custo elevado
     */
    private function hashPassword(string $password): string
    {
        return Hash::make($password, [
            'rounds' => 14, // Custo elevado para maior segurança
            'memory' => 1024,
            'time' => 2,
            'threads' => 2
        ]);
    }

    /**
     * Truncar user agent para evitar overflow
     */
    private function truncateUserAgent(string $userAgent): string
    {
        return substr($userAgent, 0, 255);
    }

    /**
     * Formatar dados do usuário para resposta
     */
    private function formatUserData(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'phone' => $user->phone,
            'avatar_base_url' => $user->avatar_base_url,
            'bio' => $user->bio,
            'is_only' => (bool) $user->is_only,
            'birthday' => $user->birthday,
            'country' => $user->country,
            'state' => $user->state,
            'city' => $user->city,
            'email_verified' => !is_null($user->email_verified_at),
            'email_verified_at' => $user->email_verified_at,
            'account_status' => $user->account_status,
            'joined_at' => $user->created_at,
            'last_login' => $user->last_login_at,
            'last_login_ip' => $user->last_login_ip,
            'ip_registered' => $user->ip_registered,
            'failed_login_attempts' => $user->failed_login_attempts,
            'locked_until' => $user->locked_until,
        ];
    }

    /**
     * Log de eventos de segurança
     */
    private function logSecurityEvent(string $event, array $data): void
    {
        Log::channel('security')->info($event, array_merge($data, [
            'timestamp' => now()->toIso8601String(),
            'environment' => app()->environment()
        ]));
    }

}
