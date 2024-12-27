<?php

namespace Telara\Models;

use Illuminate\Database\Eloquent\Model;

class Telara extends Model
{
    protected $table = 'telara_files';

    protected $fillable = [
        'file_id',
        'path',
        'caption',
    ];
}
