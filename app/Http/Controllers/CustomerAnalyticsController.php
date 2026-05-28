<?php

namespace App\Http\Controllers;

use App\Models\Pedido;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerAnalyticsController extends Controller
{
    public function topCustomers(Request $request)
    {
        $period = $request->query('period', 'month');
        $limit = (int) $request->query('limit', 10);
        $startDate = $this->startDateForPeriod($period);

        $topCustomers = Pedido::query()
            ->leftJoin('usuarios', 'pedidos.usuario_id', '=', 'usuarios.id')
            ->where('pedidos.created_at', '>=', $startDate)
            ->whereIn('pedidos.estado', ['pagado', 'servido'])
            ->selectRaw('COALESCE(pedidos.nombre_cliente, usuarios.nombre) as customer_name')
            ->selectRaw('COUNT(*) as order_count')
            ->selectRaw('SUM(pedidos.total) as total_spent')
            ->selectRaw('MAX(pedidos.created_at) as last_order_at')
            ->groupBy(DB::raw('COALESCE(pedidos.nombre_cliente, usuarios.nombre)'))
            ->orderByDesc('total_spent')
            ->limit($limit)
            ->get();

        return response()->json($topCustomers);
    }

    public function customerRetention()
    {
        $paidOrServedOrders = Pedido::query()->whereIn('estado', ['pagado', 'servido']);

        $repeatCustomers = (clone $paidOrServedOrders)
            ->selectRaw('COALESCE(nombre_cliente, CAST(usuario_id AS CHAR)) as customer_key')
            ->groupBy('customer_key')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        $totalCustomers = (int) ((clone $paidOrServedOrders)
            ->selectRaw('COUNT(DISTINCT COALESCE(nombre_cliente, CAST(usuario_id AS CHAR))) as total')
            ->value('total') ?? 0);

        $retentionRate = $totalCustomers > 0
            ? ($repeatCustomers / $totalCustomers) * 100
            : 0;

        return response()->json([
            'total_customers' => (int) $totalCustomers,
            'repeat_customers' => (int) $repeatCustomers,
            'retention_rate' => (float) round($retentionRate, 2),
        ]);
    }

    private function startDateForPeriod(string $period): Carbon
    {
        $now = Carbon::now();

        return match ($period) {
            'day' => $now->copy()->startOfDay(),
            'week' => $now->copy()->startOfWeek(),
            'year' => $now->copy()->startOfYear(),
            default => $now->copy()->startOfMonth(),
        };
    }
}
