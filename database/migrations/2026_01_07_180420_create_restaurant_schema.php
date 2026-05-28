<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Usuarios y Roles
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique(); // admin, mesero, cocina, cajero, cliente
            $table->string('descripcion')->nullable();
            $table->timestamps();
        });

        Schema::create('usuarios', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->foreignId('rol_id')->constrained('roles');
            $table->enum('estado', ['activo', 'inactivo'])->default('activo');
            $table->rememberToken();
            $table->timestamps();
        });

        // 2. Mesas y Reservas
        Schema::create('mesas', function (Blueprint $table) {
            $table->id();
            $table->integer('numero')->unique();
            $table->integer('capacidad');
            $table->enum('estado', ['libre', 'ocupada', 'reservada'])->default('libre');
            $table->integer('ubicacion_x')->nullable();
            $table->integer('ubicacion_y')->nullable();
            $table->timestamps();
        });

        Schema::create('reservas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mesa_id')->constrained('mesas');
            $table->string('nombre_cliente');
            $table->integer('cantidad_personas');
            $table->dateTime('hora_reserva');
            $table->string('telefono')->nullable();
            $table->enum('estado', ['pendiente', 'confirmada', 'cancelada', 'completada'])->default('pendiente');
            $table->timestamps();
        });

        // 3. Menú y Productos
        Schema::create('categorias', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->enum('tipo', ['menu', 'inventario']); // Para separar categorías de platos vs ingredientes
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->foreignId('categoria_id')->constrained('categorias');
            $table->decimal('precio', 10, 2);
            $table->string('imagen_url')->nullable();
            $table->text('descripcion')->nullable();
            $table->boolean('disponible')->default(true);
            $table->timestamps();
        });

        // 4. Inventario
        Schema::create('ingredientes', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->foreignId('categoria_id')->constrained('categorias');
            $table->string('unidad_medida'); // kg, lt, unidad
            $table->decimal('stock_actual', 10, 3);
            $table->decimal('stock_minimo', 10, 3)->default(0);
            $table->decimal('costo_unitario', 10, 2)->nullable();
            $table->date('fecha_vencimiento')->nullable();
            $table->timestamps();
        });

        Schema::create('producto_ingredientes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->onDelete('cascade');
            $table->foreignId('ingrediente_id')->constrained('ingredientes')->onDelete('cascade');
            $table->decimal('cantidad_necesaria', 10, 3); // Cantidad a descontar por cada producto
            $table->timestamps();
        });

        // 5. Pedidos y Facturación
        Schema::create('pedidos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mesa_id')->nullable()->constrained('mesas'); // Nullable para "para llevar"
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios'); // Mesero que atendió

            // Datos para pedidos Para Llevar
            $table->string('nombre_cliente')->nullable();
            $table->string('telefono_cliente')->nullable();

            $table->enum('tipo_pedido', ['mesa', 'llevar']);
            $table->enum('estado', ['pendiente', 'preparando', 'listo', 'servido', 'pagado', 'cancelado'])->default('pendiente');

            $table->decimal('total', 10, 2)->default(0);
            $table->string('metodo_pago')->nullable(); // efectivo, tarjeta, qr
            $table->timestamp('fecha_pago')->nullable();
            $table->timestamps();
        });

        Schema::create('detalles_pedido', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos')->onDelete('cascade');
            $table->foreignId('producto_id')->constrained('productos');
            $table->integer('cantidad');
            $table->string('notas')->nullable(); // "Sin cebolla", etc.
            $table->decimal('precio_unitario', 10, 2);
            $table->decimal('subtotal', 10, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('detalles_pedido');
        Schema::dropIfExists('pedidos');
        Schema::dropIfExists('producto_ingredientes');
        Schema::dropIfExists('ingredientes');
        Schema::dropIfExists('productos');
        Schema::dropIfExists('categorias');
        Schema::dropIfExists('reservas');
        Schema::dropIfExists('mesas');
        Schema::dropIfExists('usuarios');
        Schema::dropIfExists('roles');
    }
};
