<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Communitie;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CommunitieController extends Controller
{
    use ApiResponse;
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
    public function store(Request $request)
    {
        if (Gate::denies('create', Communitie::class)) {
            abort(403, 'Você não pode criar comunidade');
        }
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:communities,slug',
            'cover_image_url' => [
                'nullable',
                File::image() // valida se é imagem real via MIME
                    ->types(['jpg', 'jpeg', 'png', 'webp'])
                    ->max('5mb') // 5MB limite
                    ->dimensions(Rule::dimensions()->maxWidth(3000)->maxHeight(3000))
            ]
            ,
            'description' => 'nullable|string|max:255',
            'is_private' => 'boolean',
        ], [
            'name.required' => 'O nome da comunidade é obrigatório.',
            'slug.required' => 'O slug da comunidade é obrigatório.',
            'slug.unique' => 'O slug da comunidade já está em uso.',
            'cover_image_url.image' => 'A imagem de capa deve ser uma imagem válida.',
            'cover_image_url.mimes' => 'A imagem de capa deve ser um dos seguintes tipos: jpg, png, webp.',
            'cover_image_url.max' => 'A imagem de capa não pode exceder 2MB.',
            'description.string' => 'A descrição deve ser uma string.',
            'description.max' => 'A descrição não pode exceder 255 caracteres.',
            'is_private.boolean' => 'O campo is_private deve ser um booleano.',
        ]);

        // SANITIZAÇÃO
        $validatedData['name'] = strip_tags($validatedData['name']);
        $validatedData['description'] = strip_tags($validatedData['description']);
        $validatedData['slug'] = Str::slug($validatedData['slug']);
        $validatedData['owner_id'] = auth()->id();

        if ($request->hasFile('cover_image_url')) {
            $path = $request->file('cover_image_url')->store('communities/covers', 'public');
            $validatedData['cover_image_url'] = Storage::url($path);
        }

        unset($validatedData['cover_image_url']); // remove o arquivo do array

        try {
            $community = Communitie::create($validatedData);
        } catch (\Illuminate\Database\QueryException $e) {
            // Se deu erro, apaga a imagem que subiu
            if (isset($path))
                Storage::disk('public')->delete($path);
            return $this->error('Slug já está em uso', 409);
        }

        if (!$community) {
            return $this->error('Erro ao criar a comunidade', 500);
        }

        return $this->success($community, 'Comunidade criada com sucesso', 201);

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
}
