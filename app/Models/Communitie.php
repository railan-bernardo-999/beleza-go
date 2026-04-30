<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'slug', 'cover_image_url', 'description', 'invite_code', 'owner_id', 'is_private', 'status'])]
class Communitie extends Model
{
    //
}
