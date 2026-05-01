<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Community;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CommunityController extends Controller
{
    use ApiResponse;

    private const MAX_COMMUNITIES_PER_USER = 50;
    private const CREATE_COMMUNITY_ATTEMPTS = 10;
    private const CREATE_COMMUNITY_DECAY_MINUTES = 60;
    private const INVITE_CODE_LENGTH = 8;
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    /**
     * Criar nova comunidade
     */
    public function store(Request $request)
    {
        // Rate limite por usuário/IP
        $rateLimitResponse = $this->checkCreateCommunityRateLimit($request);
        if ($rateLimitResponse) {
            return $rateLimitResponse;
        }

        // Verificar limite de comunidades por usuário
        $user = $request->user();
        $userCommunitiesCount = Community::where('owner_id', $user->id)->count();

        if ($userCommunitiesCount >= self::MAX_COMMUNITIES_PER_USER) {
            return $this->error(
                "Você atingiu o limite máximo de " . self::MAX_COMMUNITIES_PER_USER . " comunidades.",
                429,
                'Limite excedido'
            );
        }

        // Verificar permissão (Gate)
        if (Gate::denies('create', Community::class)) {
            return $this->error('Você não tem permissão para criar comunidades', 403, 'Acesso negado');
        }


        // Validação dos dados
        try {
            // Converter is_private para boolean
            if ($request->has('is_private')) {
                $isPrivate = filter_var($request->is_private, FILTER_VALIDATE_BOOLEAN);
                $request->merge(['is_private' => $isPrivate]);
            }

            $validatedData = $request->validate([
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    'min:3',
                    'regex:/^[\p{L}\p{N}\s\-_]+$/u' // Permite letras, números, espaços, hífen, underscore
                ],
                'slug' => [
                    'required',
                    'string',
                    'max:255',
                    'min:3',
                    'regex:/^[a-z0-9\-]+$/',
                    'unique:communities,slug'
                ],
                'cover_image_url' => [
                    'nullable',
                    'image',
                    'mimes:jpeg,jpg,png,webp',
                    'max:5120', // 5MB
                    'dimensions:max_width=3000,max_height=3000'
                ],
                'description' => [
                    'nullable',
                    'string',
                    'max:500'
                ],
                'is_private' => [
                    'boolean'
                ],
            ], [
                'name.required' => 'O nome da comunidade é obrigatório.',
                'name.min' => 'O nome deve ter pelo menos 3 caracteres.',
                'name.regex' => 'O nome só pode conter letras, números, espaços, hífen e underscore.',
                'slug.required' => 'O slug é obrigatório.',
                'slug.regex' => 'O slug deve conter apenas letras minúsculas, números e hífens.',
                'slug.unique' => 'Este slug já está em uso.',
                'cover_image_url.image' => 'O arquivo deve ser uma imagem.',
                'cover_image_url.max' => 'A imagem não pode exceder 5MB.',
                'cover_image_url.dimensions' => 'A imagem não pode exceder 3000x3000 pixels.',
                'description.max' => 'A descrição não pode exceder 500 caracteres.',
                'is_private.boolean' => 'O campo privado deve ser verdadeiro ou falso.',
            ]);
        } catch (ValidationException $e) {
            return $this->error('Error', 422, $e->errors());
        }

        //  Sanitização
        $validatedData['name'] = $this->sanitizeCommunityName($validatedData['name']);
        $validatedData['slug'] = $this->generateUniqueSlug($validatedData['slug']);
        $validatedData['description'] = $this->sanitizeDescription($validatedData['description'] ?? null);
        $validatedData['owner_id'] = $user->id;
        $validatedData['is_private'] = (bool) ($validatedData['is_private'] ?? false);
        $validatedData['invite_code'] = $this->generateUniqueInviteCode();

        // Processar imagem de capa
        $coverImagePath = null;

        if ($request->hasFile('cover_image_url')) {
            try {
                // Validar novamente o arquivo
                $file = $request->file('cover_image_url');

                if (!$file->isValid()) {
                    return $this->error('Arquivo de imagem inválido', 400, 'Erro no upload');
                }

                // Gerar nome único para o arquivo
                $filename = Str::random(40) . '.' . $file->getClientOriginalExtension();
                $coverImagePath = $file->storeAs('communities/covers', $filename, 'public');

                if (!$coverImagePath) {
                    return $this->error('Erro ao fazer upload da imagem', 500, 'Erro interno');
                }

                $validatedData['cover_image_url'] = Storage::disk('public')->url($coverImagePath);
            } catch (\Exception $e) {
                Log::error('Erro no upload da imagem', [
                    'error' => $e->getMessage(),
                    'user_id' => $user->id
                ]);
                return $this->error('Erro ao processar a imagem', 500, 'Erro interno');
            }
        }

        // Criar comunidade com transaction
        DB::beginTransaction();

        try {
            $community = Community::create($validatedData);

            $community->members()->attach($user->id, [
                'role' => 'admin',
                'joined_at' => now()
            ]);

            DB::commit();

            // Log de criação
            Log::info('Nova comunidade criada', [
                'community_id' => $community->id,
                'community_name' => $community->name,
                'invite_code' => $community->invite_code,
                'owner_id' => $user->id,
                'owner_email' => $user->email,
                'ip' => $request->ip()
            ]);

            // Limpar rate limit
            RateLimiter::clear('create-community:' . $request->user()->id);

            return $this->success([
                'community' => $this->formatCommunityData($community),
                'invite_link' => $this->generateInviteLink($community),
                'message' => 'Comunidade criada com sucesso'
            ], 'Comunidade criada com sucesso', 201);

        } catch (\Illuminate\Database\QueryException $e) {
            DB::rollBack();

            // Limpar imagem se houve erro
            if ($coverImagePath) {
                Storage::disk('public')->delete($coverImagePath);
            }

            // Verificar se foi erro de unique (slug duplicado)
            if ($e->errorInfo[1] == 1062) { // Código MySQL para duplicate entry
                return $this->error('Slug já está em uso. Tente outro slug.', 409, 'Slug duplicado');
            }

            Log::error('Erro ao criar comunidade', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'data' => $validatedData
            ]);

            return $this->error('Erro interno ao criar a comunidade', 500, 'Erro interno');

        } catch (\Exception $e) {
            DB::rollBack();

            // Limpar imagem se houve erro
            if ($coverImagePath) {
                Storage::disk('public')->delete($coverImagePath);
            }

            Log::error('Erro inesperado ao criar comunidade', [
                'error' => $e->getMessage(),
                'user_id' => $user->id
            ]);

            return $this->error('Erro inesperado ao criar a comunidade', 500, 'Erro interno');
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    /**
     * Verificar rate limit para criação de comunidades
     */
    private function checkCreateCommunityRateLimit(Request $request): ?JsonResponse
    {
        $userId = $request->user()?->id ?? 'guest';
        $key = 'create-community:' . $userId . ':' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, self::CREATE_COMMUNITY_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);
            $minutes = ceil($seconds / 60);

            return $this->error(
                "Muitas tentativas de criação. Aguarde {$minutes} minutos antes de tentar novamente.",
                429,
                'Rate limit excedido'
            );
        }

        RateLimiter::hit($key, self::CREATE_COMMUNITY_DECAY_MINUTES * 60);
        return null;
    }

    /**
     * Sanitizar nome da comunidade
     */
    private function sanitizeCommunityName(string $name): string
    {
        // Remover tags HTML
        $name = strip_tags($name);
        // Remover espaços extras
        $name = preg_replace('/\s+/', ' ', $name);
        // Remover caracteres especiais (exceto espaços, hífen, underscore)
        $name = preg_replace('/[^\p{L}\p{N}\s\-_]/u', '', $name);
        // Limitar comprimento
        return trim(substr($name, 0, 255));
    }

    /**
     * Sanitizar descrição
     */
    private function sanitizeDescription(?string $description): ?string
    {
        if (!$description) {
            return null;
        }

        // Remover HTML/script
        $description = strip_tags($description);
        // Converter quebras de linha para espaços
        $description = preg_replace('/\s+/', ' ', $description);
        // Limitar comprimento
        return trim(substr($description, 0, 500));
    }

    /**
     * Gerar slug único
     */
    private function generateUniqueSlug(string $slug): string
    {
        $slug = Str::slug($slug);

        if (empty($slug)) {
            $slug = 'community-' . Str::random(6);
        }

        // Verificar duplicidade
        $originalSlug = $slug;
        $counter = 1;

        while (Community::where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Formatar dados da comunidade para resposta
     */
    private function formatCommunityData(Community $community): array
    {
        return [
            'id' => $community->id,
            'name' => $community->name,
            'slug' => $community->slug,
            'cover_image_url' => $community->cover_image_url,
            'description' => $community->description,
            'is_private' => (bool) $community->is_private,
            'owner_id' => $community->owner_id,
            'members_count' => $community->members()->count() ?? 0,
            'created_at' => $community->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $community->updated_at?->format('Y-m-d H:i:s')
        ];
    }

    /**
     * Gerar código de convite único
     */
    private function generateUniqueInviteCode(): string
    {
        do {
            // Gerar código aleatório (ex: "A7B3F9K2")
            $code = strtoupper(Str::random(self::INVITE_CODE_LENGTH));

            // Opcional: Adicionar prefixo para identificar comunidade
            // $code = 'COM-' . strtoupper(Str::random(self::INVITE_CODE_LENGTH));

            // Verificar se já existe
            $exists = Community::where('invite_code', $code)->exists();
        } while ($exists);

        return $code;
    }

    /**
     * Gerar link de convite para a comunidade
     */
    private function generateInviteLink(Community $community): string
    {
        // Link para o frontend Next.js
        return config('app.frontend_url') . '/invite/' . $community->invite_code;

        // Ou link para API
        // return url('/api/communities/join/' . $community->invite_code);
    }

    /**
     * Entrar em uma comunidade via código de convite
     */
    public function joinByInvite(Request $request, string $inviteCode): JsonResponse
    {
        $user = $request->user();

        // Buscar comunidade pelo código de convite
        $community = Community::where('invite_code', $inviteCode)->first();

        if (!$community) {
            return $this->error('Código de convite inválido', 404, 'Convite inválido');
        }

        // Verificar se já é membro
        if ($community->members()->where('user_id', $user->id)->exists()) {
            return $this->error('Você já é membro desta comunidade', 400, 'Já é membro');
        }

        // Verificar se a comunidade é privada e requer aprovação
        if ($community->is_private) {
            // Adicionar como membro pendente
            $community->members()->attach($user->id, [
                'role' => 'pending',
                'joined_at' => now()
            ]);

            return $this->success([
                'community' => $this->formatCommunityData($community),
                'message' => 'Solicitação enviada. Aguarde aprovação do administrador.'
            ], 'Solicitação enviada', 202);
        }

        // Comunidade pública, adicionar diretamente
        $community->members()->attach($user->id, [
            'role' => 'member',
            'joined_at' => now()
        ]);

        Log::info('Usuário entrou na comunidade via convite', [
            'user_id' => $user->id,
            'community_id' => $community->id,
            'invite_code' => $inviteCode
        ]);

        return $this->success([
            'community' => $this->formatCommunityData($community),
            'message' => 'Você entrou na comunidade com sucesso!'
        ], 'Bem-vindo à comunidade!', 200);
    }

    /**
     * Regenerar código de convite (para admin)
     */
    public function regenerateInviteCode(Request $request, int $communityId): JsonResponse
    {
        $community = Community::findOrFail($communityId);

        // Verificar permissão (apenas admin/owner)
        $user = $request->user();
        if (
            $community->owner_id !== $user->id &&
            $community->members()->where('user_id', $user->id)->where('role', 'admin')->doesntExist()
        ) {
            return $this->error('Você não tem permissão para regenerar o código de convite', 403, 'Acesso negado');
        }

        // Gerar novo código
        $newInviteCode = $this->generateUniqueInviteCode();
        $community->invite_code = $newInviteCode;
        $community->save();

        Log::info('Código de convite regenerado', [
            'community_id' => $community->id,
            'old_code' => $community->getOriginal('invite_code'),
            'new_code' => $newInviteCode,
            'user_id' => $user->id
        ]);

        return $this->success([
            'invite_code' => $newInviteCode,
            'invite_link' => $this->generateInviteLink($community)
        ], 'Código de convite regenerado com sucesso', 200);
    }

}
