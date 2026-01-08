<?php

namespace App\Models;

use App\Models\Image;
use Illuminate\Database\Eloquent\Model;

class Upload extends Model
{
    protected $fillable = [
        'original_name',
        'checksum',
        'total_chunks',
        'received_chunks',
        'status',
    ];

    protected $casts = [
        'received_chunks' => 'array',
    ];

    public function images()
    {
        return $this->hasMany(Image::class);
    }

    public function getReceivedChunksCountAttribute()
    {
        return count($this->received_chunks ?? []);
    }
}
