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

    protected $casts = [
        'price' => 'decimal:2',
    ];

    /**
     * Get the primary image for this product
     */
    public function primaryImage()
    {
        return $this->belongsTo(Image::class, 'primary_image_id');
    }

    /**
     * Scope to find by SKU
     */
    public function scopeBySku($query, string $sku)
    {
        return $query->where('sku', $sku);
    }

    /**
     * Scope to find multiple by SKUs
     */
    public function scopeBySkus($query, array $skus)
    {
        return $query->whereIn('sku', $skus);
    }
}
