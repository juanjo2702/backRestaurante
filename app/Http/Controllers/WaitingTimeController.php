<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Pedido;
use Carbon\Carbon;

class WaitingTimeController extends Controller
{
    public function index()
    {
        // Pedidos en estado pendiente o preparando
        $pendingOrders = Pedido::whereIn('estado', ['pendiente', 'preparando'])->count();
        
        // Tiempo promedio de preparación por pedido (en minutos)
        // Podríamos calcularlo históricamente, por ahora 15 minutos fijos
        $avgPrepTimePerOrder = 15; // minutos
        
        // Estimación total
        $estimatedMinutes = $pendingOrders * $avgPrepTimePerOrder;
        
        // Determinar estado
        if ($estimatedMinutes > 30) {
            $status = 'high';
            $message = 'Tiempo de espera alto';
        } elseif ($estimatedMinutes > 15) {
            $status = 'medium';
            $message = 'Tiempo de espera moderado';
        } else {
            $status = 'low';
            $message = 'Tiempo de espera bajo';
        }
        
        return response()->json([
            'minutes' => $estimatedMinutes,
            'status' => $status,
            'message' => $message,
            'pending_orders' => $pendingOrders,
            'avg_prep_time_per_order' => $avgPrepTimePerOrder,
            'updated_at' => Carbon::now()->toIso8601String()
        ]);
    }
}