<?php

namespace App\Http\Controllers;

use App\Models\DetallePedido;
use App\Models\Pedido;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $period = $request->query('period', 'week');
        $now = Carbon::now();
        $startDate = $this->startDateForPeriod($period, $now);
        $rangeEnd = $this->rangeEndForPeriod($period, $startDate, $now);

        $currentStats = $this->getStatsForPeriod($startDate, $now);
        $duration = $now->diffInSeconds($startDate);
        $previousEnd = $startDate->copy()->subSecond();
        $previousStart = $previousEnd->copy()->subSeconds($duration);
        $previousStats = $this->getStatsForPeriod($previousStart, $previousEnd);

        $changes = [];
        foreach (['revenue', 'orders', 'avg_ticket', 'clients'] as $metric) {
            $current = $currentStats[$metric];
            $previous = $previousStats[$metric];
            $changes[$metric] = $previous != 0
                ? round((($current - $previous) / $previous) * 100, 1)
                : ($current != 0 ? 100.0 : 0.0);
        }

        $salesData = Pedido::query()
            ->selectRaw('DATE(pedidos.created_at) as report_date')
            ->selectRaw('SUM(pedidos.total) as sales')
            ->selectRaw('COUNT(*) as orders')
            ->whereBetween('pedidos.created_at', [$startDate, $now])
            ->groupBy('report_date')
            ->orderBy('report_date')
            ->get()
            ->keyBy('report_date');

        $chartData = $this->buildChartData($salesData, $startDate, $rangeEnd);

        $topProducts = DetallePedido::query()
            ->join('pedidos', 'pedidos.id', '=', 'detalles_pedido.pedido_id')
            ->join('productos', 'productos.id', '=', 'detalles_pedido.producto_id')
            ->whereBetween('pedidos.created_at', [$startDate, $now])
            ->selectRaw('productos.nombre as name')
            ->selectRaw('SUM(detalles_pedido.cantidad) as sales')
            ->selectRaw('SUM(detalles_pedido.subtotal) as revenue')
            ->groupBy('productos.id', 'productos.nombre')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get();

        $byType = Pedido::query()
            ->selectRaw('tipo_pedido, COUNT(*) as count')
            ->whereBetween('created_at', [$startDate, $now])
            ->groupBy('tipo_pedido')
            ->get();

        $typeStats = [
            'mesa' => (int) ($byType->firstWhere('tipo_pedido', 'mesa')->count ?? 0),
            'llevar' => (int) ($byType->firstWhere('tipo_pedido', 'llevar')->count ?? 0),
        ];

        $peakHours = Pedido::query()
            ->selectRaw($this->hourExpression().' as hour')
            ->selectRaw('COUNT(*) as count')
            ->whereBetween('pedidos.created_at', [$startDate, $now])
            ->groupBy('hour')
            ->get();

        return response()->json([
            'stats' => [
                'revenue' => (float) $currentStats['revenue'],
                'orders' => (int) $currentStats['orders'],
                'avg_ticket' => round((float) $currentStats['avg_ticket'], 2),
                'clients' => (int) $currentStats['clients'],
            ],
            'changes' => $changes,
            'chart' => $chartData,
            'top_products' => $topProducts,
            'types' => $typeStats,
            'peaks' => [
                'lunch' => (int) $peakHours->whereBetween('hour', [11, 15])->sum('count'),
                'dinner' => (int) $peakHours->whereBetween('hour', [18, 23])->sum('count'),
                'snack' => (int) $peakHours->whereBetween('hour', [15, 17])->sum('count'),
            ],
        ]);
    }

    private function getStatsForPeriod(Carbon $startDate, Carbon $endDate): array
    {
        $ordersQuery = Pedido::query()->whereBetween('created_at', [$startDate, $endDate]);

        $totalRevenue = (float) (clone $ordersQuery)->sum('total');
        $totalOrders = (int) (clone $ordersQuery)->count();
        $avgTicket = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;

        $distinctSessions = (int) (clone $ordersQuery)
            ->whereNotNull('table_session_id')
            ->distinct('table_session_id')
            ->count('table_session_id');

        $distinctNamedClients = (int) (clone $ordersQuery)
            ->whereNull('table_session_id')
            ->whereNotNull('nombre_cliente')
            ->distinct('nombre_cliente')
            ->count('nombre_cliente');

        $anonymousOrders = (int) (clone $ordersQuery)
            ->whereNull('table_session_id')
            ->whereNull('nombre_cliente')
            ->count();

        return [
            'revenue' => $totalRevenue,
            'orders' => $totalOrders,
            'avg_ticket' => $avgTicket,
            'clients' => $distinctSessions + $distinctNamedClients + $anonymousOrders,
        ];
    }

    private function buildChartData(Collection $salesData, Carbon $startDate, Carbon $rangeEnd): array
    {
        $daysEs = [
            'Mon' => 'Lun',
            'Tue' => 'Mar',
            'Wed' => 'Mie',
            'Thu' => 'Jue',
            'Fri' => 'Vie',
            'Sat' => 'Sab',
            'Sun' => 'Dom',
        ];

        $chartData = [];
        $cursor = $startDate->copy();

        while ($cursor <= $rangeEnd) {
            $dateKey = $cursor->format('Y-m-d');
            $record = $salesData->get($dateKey);

            $chartData[] = [
                'day' => $daysEs[$cursor->format('D')] ?? $cursor->format('D'),
                'full_date' => $dateKey,
                'sales' => $record ? (float) $record->sales : 0,
                'orders' => $record ? (int) $record->orders : 0,
            ];

            $cursor->addDay();
        }

        return $chartData;
    }

    private function startDateForPeriod(string $period, Carbon $now): Carbon
    {
        return match ($period) {
            'day' => $now->copy()->startOfDay(),
            'month' => $now->copy()->startOfMonth(),
            default => $now->copy()->startOfWeek(),
        };
    }

    private function rangeEndForPeriod(string $period, Carbon $startDate, Carbon $now): Carbon
    {
        $rangeEnd = match ($period) {
            'day' => $startDate->copy()->endOfDay(),
            'month' => $startDate->copy()->endOfMonth(),
            default => $startDate->copy()->endOfWeek(),
        };

        return $rangeEnd->greaterThan($now) ? $now : $rangeEnd;
    }

    private function hourExpression(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%H', pedidos.created_at) AS INTEGER)"
            : 'HOUR(pedidos.created_at)';
    }
}
