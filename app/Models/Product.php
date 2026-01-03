<?php

namespace App\Models;

use App\Models\Image;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'sku',
        'name',
        'price',
        'primary_image_id',
    ];

    public function primaryImage()
    {
        return $this->belongsTo(Image::class, 'primary_image_id');
    }
}
