<?php

namespace App\Models;

use App\Models\Upload;
use Illuminate\Database\Eloquent\Model;

class Image extends Model
{
    protected $fillable = [
        'upload_id',
        'path',
        'width',
        'height',
        'variant',
        'checksum',
    ];

    public function upload()
    {
        return $this->belongsTo(Upload::class);
    }
}
