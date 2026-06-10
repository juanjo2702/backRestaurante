<?php

namespace Database\Seeders;

use App\Models\CashRegisterSession;
use App\Models\Ingrediente;
use App\Models\Mesa;
use App\Models\PaymentTransaction;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Reserva;
use App\Models\Review;
use App\Models\User;
use App\Services\DemoInventoryHistorySeederService;
use App\Services\DemoLoyaltyHistorySeederService;
use Illuminate\Database\Seeder;

class OperationalDemoSeeder extends Seeder
{
    public function run(): void
    {
        $waiter = User::where('email', 'mesero1@gusto.bo')->first();
        $cashier = User::where('email', 'caja@gusto.bo')->first();
        $tables = Mesa::orderBy('numero')->take(8)->get();
        $products = Producto::with('ingredientes')->get();
        $ingredients = Ingrediente::all();

        if (!$waiter || !$cashier || $tables->isEmpty() || $products->isEmpty() || $ingredients->isEmpty()) {
            return;
        }

        /** @var DemoInventoryHistorySeederService $inventorySeeder */
        $inventorySeeder = app(DemoInventoryHistorySeederService::class);
        /** @var DemoLoyaltyHistorySeederService $loyaltySeeder */
        $loyaltySeeder = app(DemoLoyaltyHistorySeederService::class);

        $this->resetDemoData($inventorySeeder, $loyaltySeeder);
        $this->seedCashSessions($cashier);
        $this->seedInventoryTimeline($ingredients, $cashier, $inventorySeeder);

        $seededOrders = collect();
        foreach (range(1, 180) as $index) {
            $createdAt = now()->subDays(rand(0, 59))->setTime(rand(12, 22), rand(0, 59));
            $updatedAt = $createdAt->copy()->addMinutes(rand(12, 95));
            $table = $index % 4 === 0 ? null : $tables->random();
            $selected = $products->random(rand(1, 3));
            $orderType = $table ? 'mesa' : 'llevar';
            $statusRoll = rand(1, 100);
            $finalStatus = $statusRoll <= 65 ? 'pagado' : ($statusRoll <= 80 ? 'cancelado' : ($statusRoll <= 92 ? 'servido' : 'listo'));
            $paymentMethod = $finalStatus === 'pagado' ? collect(['cash', 'card', 'qr'])->random() : null;
            $paymentDate = $finalStatus === 'pagado' ? $updatedAt->copy()->addMinutes(rand(5, 20)) : null;

            $pedido = Pedido::create([
                'mesa_id' => $table?->id,
                'usuario_id' => $waiter->id,
                'nombre_cliente' => $table ? 'Mesa Demo ' . $index : 'Cliente Demo ' . $index,
                'telefono_cliente' => '+591 7' . str_pad((string) (2000000 + $index * 17), 7, '0', STR_PAD_LEFT),
                'tipo_pedido' => $orderType,
                'estado' => $finalStatus,
                'metodo_pago' => $paymentMethod,
                'fecha_pago' => $paymentDate,
                'created_at' => $createdAt,
                'updated_at' => $paymentDate ?? $updatedAt,
            ]);

            $total = 0;
            foreach ($selected as $product) {
                $quantity = rand(1, 2);
                $subtotal = $quantity * (float) $product->precio;
                $pedido->detalles()->create([
                    'producto_id' => $product->id,
                    'cantidad' => $quantity,
                    'notas' => null,
                    'precio_unitario' => $product->precio,
                    'subtotal' => $subtotal,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);
                $total += $subtotal;
            }
            $pedido->update(['total' => $total]);

            if (in_array($finalStatus, ['servido', 'pagado'], true)) {
                $inventorySeeder->consumeForOrder($pedido->fresh('detalles.producto.ingredientes'), $updatedAt, $waiter);
            }

            if ($finalStatus === 'cancelado' && rand(0, 1) === 1) {
                $inventorySeeder->consumeForOrder($pedido->fresh('detalles.producto.ingredientes'), $updatedAt->copy()->subMinutes(8), $waiter);
                $inventorySeeder->reverseForOrder($pedido->fresh('detalles.producto.ingredientes'), $updatedAt, $waiter);
            }

            if ($finalStatus === 'pagado') {
                PaymentTransaction::create([
                    'pedido_id' => $pedido->id,
                    'mesa_id' => $table?->id,
                    'initiated_by' => $cashier->id,
                    'confirmed_by' => $cashier->id,
                    'amount' => $total,
                    'method' => $paymentMethod,
                    'status' => 'confirmed',
                    'reference' => 'DEMO-' . $pedido->id,
                    'client_paid_at' => $paymentDate,
                    'confirmed_at' => $paymentDate,
                    'created_at' => $paymentDate,
                    'updated_at' => $paymentDate,
                ]);
            }

            $seededOrders->push($pedido->fresh());
        }

        $loyaltySeeder->awardForOrders($seededOrders, $waiter);
        $loyaltySeeder->seedRedeems($cashier);
        $this->seedReservations();
        $this->seedReviews();
    }

    private function resetDemoData(DemoInventoryHistorySeederService $inventorySeeder, DemoLoyaltyHistorySeederService $loyaltySeeder): void
    {
        $loyaltySeeder->resetDemoData();
        $inventorySeeder->resetDemoData();

        PaymentTransaction::where('reference', 'like', 'DEMO-%')->delete();

        Pedido::where('nombre_cliente', 'like', '%Demo%')->delete();
        Reserva::where('nombre_cliente', 'like', '%Demo%')->delete();
    }

    private function seedCashSessions(User $cashier): void
    {
        CashRegisterSession::updateOrCreate(
            ['user_id' => $cashier->id, 'status' => 'open'],
            [
                'opening_amount' => 500,
                'opened_at' => now()->startOfDay()->addHours(11),
                'notes' => 'Apertura demo',
            ]
        );

        CashRegisterSession::updateOrCreate(
            ['user_id' => $cashier->id, 'opened_at' => now()->subDay()->startOfDay()->addHours(11)],
            [
                'status' => 'closed',
                'opening_amount' => 480,
                'closing_amount' => 1520,
                'opened_at' => now()->subDay()->startOfDay()->addHours(11),
                'closed_at' => now()->subDay()->startOfDay()->addHours(23),
                'notes' => 'Cierre demo del dia anterior',
            ]
        );
    }

    private function seedInventoryTimeline($ingredients, User $cashier, DemoInventoryHistorySeederService $inventorySeeder): void
    {
        foreach ($ingredients as $index => $ingredient) {
            $inventorySeeder->restock(
                ingrediente: $ingredient,
                quantity: 500 + ($index % 5) * 50,
                occurredAt: now()->subDays(61 - ($index % 6))->setTime(7 + ($index % 3), 15),
                actor: $cashier,
            );
        }

        foreach ($ingredients->take(6) as $index => $ingredient) {
            $inventorySeeder->adjustment(
                ingrediente: $ingredient,
                quantityDelta: -1 * (0.25 + ($index * 0.1)),
                occurredAt: now()->subDays(30 - $index)->setTime(9, 30),
                actor: $cashier,
            );
        }
    }

    private function seedReservations(): void
    {
        $names = ['Fernanda Lopez', 'Diego Vargas', 'Juan Carlos Mamani', 'Maria Quispe', 'Alejandro Flores', 'Carla Siles', 'Rodrigo Rocha', 'Patricia Balderrama', 'Gustavo Claros', 'Sofia Camacho', 'Martin Villarroel', 'Gabriela Justiniano', 'Carlos Mendoza', 'Daniela Suarez', 'Mauricio Osinaga'];
        
        $tables = Mesa::orderBy('numero')->get();
        if ($tables->isEmpty()) {
            return;
        }

        foreach ($names as $idx => $name) {
            $daysInFuture = rand(0, 30);
            $hour = rand(12, 21);
            $minute = collect([0, 30])->random();
            $dateTime = now()->addDays($daysInFuture)->setTime($hour, $minute, 0);

            $table = $tables->random();

            Reserva::create([
                'mesa_id' => $table->id,
                'nombre_cliente' => $name . ' Demo',
                'cantidad_personas' => rand(2, 8),
                'telefono' => '+591 7' . str_pad((string) rand(1000000, 9999999), 7, '0', STR_PAD_LEFT),
                'hora_reserva' => $dateTime,
                'estado' => collect(['pendiente', 'confirmada', 'cancelada'])->random(),
                'garantia_estado' => 'approved',
                'garantia_monto' => 50,
                'garantia_referencia' => 'TX-' . rand(10000, 99999),
            ]);
        }
    }

    private function seedReviews(): void
    {
        $reviews = [
            ['customer_name' => 'Paola Soria', 'rating' => 5, 'comment' => 'El pique macho estaba potente y llego rapido.'],
            ['customer_name' => 'Jorge Montano', 'rating' => 4, 'comment' => 'Muy buena atencion y QR funcionando sin problemas.'],
            ['customer_name' => 'Lucia Herrera', 'rating' => 5, 'comment' => 'La sopa de mani y el api estuvieron excelentes.'],
        ];

        foreach ($reviews as $review) {
            Review::updateOrCreate(
                ['customer_name' => $review['customer_name'], 'comment' => $review['comment']],
                ['rating' => $review['rating']]
            );
        }
    }
}
