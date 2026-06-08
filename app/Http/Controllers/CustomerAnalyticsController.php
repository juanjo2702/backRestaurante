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

        $concatExpression = DB::connection()->getDriverName() === 'sqlite'
            ? "'Mesa ' || mesas.numero"
            : "CONCAT('Mesa ', mesas.numero)";

        $customerNameExpression = "COALESCE(pedidos.nombre_cliente, $concatExpression, 'Cliente Ocasional')";

        $topCustomers = Pedido::query()
            ->leftJoin('mesas', 'pedidos.mesa_id', '=', 'mesas.id')
            ->where('pedidos.created_at', '>=', $startDate)
            ->whereIn('pedidos.estado', ['pagado', 'servido'])
            ->selectRaw("$customerNameExpression as customer_name")
            ->selectRaw('COUNT(*) as order_count')
            ->selectRaw('SUM(pedidos.total) as total_spent')
            ->selectRaw('MAX(pedidos.created_at) as last_order_at')
            ->groupBy(DB::raw($customerNameExpression))
            ->orderByDesc('total_spent')
            ->limit($limit)
            ->get();

        return response()->json($topCustomers);
    }

    public function customerRetention()
    {
        $paidOrServedOrders = Pedido::query()
            ->leftJoin('mesas', 'pedidos.mesa_id', '=', 'mesas.id')
            ->whereIn('pedidos.estado', ['pagado', 'servido']);

        $concatExpression = DB::connection()->getDriverName() === 'sqlite'
            ? "'Mesa ' || mesas.numero"
            : "CONCAT('Mesa ', mesas.numero)";

        $fallbackExpression = DB::connection()->getDriverName() === 'sqlite'
            ? "'takeaway_' || pedidos.id"
            : "CONCAT('takeaway_', pedidos.id)";

        $customerKeyExpression = "COALESCE(pedidos.nombre_cliente, $concatExpression, $fallbackExpression)";

        $repeatCustomers = (clone $paidOrServedOrders)
            ->selectRaw("$customerKeyExpression as customer_key")
            ->groupBy(DB::raw($customerKeyExpression))
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        $totalCustomers = (int) ((clone $paidOrServedOrders)
            ->selectRaw("COUNT(DISTINCT $customerKeyExpression) as total")
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
