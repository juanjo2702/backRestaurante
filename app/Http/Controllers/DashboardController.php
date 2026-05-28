<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Pedido;
use App\Models\Review;
use App\Models\User;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        $today = Carbon::today();

        // Customer stats
        $todayOrders = Pedido::whereDate('created_at', $today)->get();
        $totalOrders = $todayOrders->count();

        // Get distinct customers (by nombre_cliente or usuario_id)
        $distinctCustomers = $todayOrders->unique(function ($order) {
            return $order->nombre_cliente ?: $order->usuario_id;
        })->count();

        // Calculate returning vs new customers (simplified)
        $returningCount = 0; // placeholder
        $newCount = $distinctCustomers - $returningCount;
        $firstTimePercentage = $distinctCustomers > 0 ? round(($newCount / $distinctCustomers) * 100) : 0;
        $returningPercentage = $distinctCustomers > 0 ? round(($returningCount / $distinctCustomers) * 100) : 0;

        $customerStats = [
            'sessions' => $totalOrders, // Using orders as sessions proxy
            'customerRate' => $distinctCustomers > 0 ? round(($distinctCustomers / $totalOrders) * 100, 2) : 0,
            'firstTime' => $firstTimePercentage,
            'returning' => $returningPercentage,
        ];

        // Recent reviews (limit 5)
        $recentReviews = Review::orderBy('created_at', 'desc')->limit(5)->get()
            ->map(function ($review) {
                return [
                    'name' => $review->customer_name,
                    'date' => $review->created_at->diffForHumans(),
                    'rating' => $review->rating,
                    'comment' => $review->comment,
                ];
            });

        // Staff data (active users with roles)
        // Agrupar pedidos del día por usuario para evitar N+1
        $todayOrdersByUser = Pedido::whereDate('created_at', $today)
            ->whereNotNull('usuario_id')
            ->get()
            ->groupBy('usuario_id');
        
        $staff = User::with('rol')
            ->where('estado', 'activo')
            ->whereIn('rol_id', [2, 3, 4]) // mesero, cocina, cajero
            ->get()
            ->map(function ($user) use ($today, $todayOrdersByUser) {
                // Pedidos del día asignados a este usuario (desde agrupación)
                $userOrders = $todayOrdersByUser->get($user->id, collect());
                
                $orderCount = $userOrders->count();
                $totalSales = $userOrders->sum('total');
                
                // Tiempo promedio de preparación (placeholder)
                $avgPrepTime = $orderCount > 0 ? rand(8, 20) : 0;
                
                // Tips simulados (podría calcularse de propinas reales si existieran)
                $tips = $orderCount * rand(5, 15);
                
                // Check-in time (usar última conexión o simular)
                $lastLogin = $user->last_login_at ?? $user->created_at;
                $checkIn = Carbon::parse($lastLogin)->format('H:i');

                return [
                    'id' => $user->id,
                    'name' => $user->nombre,
                    'role' => $user->rol->nombre,
                    'checkIn' => $checkIn,
                    'tips' => $tips,
                    'status' => 'working',
                    'stats' => [
                        'orders_today' => $orderCount,
                        'sales_today' => $totalSales,
                        'avg_prep_time' => $avgPrepTime,
                    ],
                ];
            });

        return response()->json([
            'customerStats' => $customerStats,
            'recentReviews' => $recentReviews,
            'staff' => $staff,
        ]);
    }
}
