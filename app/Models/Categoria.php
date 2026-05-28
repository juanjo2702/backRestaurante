<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Categoria extends Model
{
    use HasFactory;

    protected $table = 'categorias';
    protected $fillable = ['nombre', 'tipo', 'activo', 'icono'];

    public function productos()
    {
        return $this->hasMany(Producto::class, 'categoria_id');
    }

    public function ingredientes()
    {
        return $this->hasMany(Ingrediente::class, 'categoria_id');
    }
}
