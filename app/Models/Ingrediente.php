<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ingrediente extends Model
{
    use HasFactory;

    protected $table = 'ingredientes';
    protected $fillable = ['nombre', 'categoria_id', 'unidad_medida', 'stock_actual', 'stock_minimo', 'costo_unitario', 'fecha_vencimiento', 'icono'];

    public function categoria()
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    public function productos()
    {
        return $this->belongsToMany(Producto::class, 'producto_ingredientes', 'ingrediente_id', 'producto_id')
            ->withPivot('cantidad_necesaria');
    }

    public function movements()
    {
        return $this->hasMany(InventoryMovement::class, 'ingrediente_id');
    }
}
