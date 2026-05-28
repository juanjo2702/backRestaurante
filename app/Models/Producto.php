<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Producto extends Model
{
    use HasFactory;

    protected $table = 'productos';
    protected $fillable = ['nombre', 'categoria_id', 'precio', 'imagen_url', 'descripcion', 'disponible'];

    protected function imagenUrl(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): ?string {
                if (!$value) return null;
                if (str_starts_with($value, 'http')) return $value;
                return asset('storage/' . ltrim($value, '/'));
            },
        );
    }

    public function categoria()
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    public function ingredientes()
    {
        return $this->belongsToMany(Ingrediente::class, 'producto_ingredientes', 'producto_id', 'ingrediente_id')
            ->withPivot('cantidad_necesaria')
            ->withTimestamps();
    }
}
