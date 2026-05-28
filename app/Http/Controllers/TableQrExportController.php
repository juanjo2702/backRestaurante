<?php

namespace App\Http\Controllers;

use App\Models\Mesa;
use App\Services\TableQrExportService;
use Illuminate\Http\Request;

class TableQrExportController extends Controller
{
    public function svg(Mesa $mesa, TableQrExportService $tableQrExportService)
    {
        if (!$mesa->is_qr_enabled) {
            return response()->json(['message' => 'El QR de esta mesa esta deshabilitado'], 409);
        }

        return response($tableQrExportService->svgForMesa($mesa), 200, [
            'Content-Type' => 'image/svg+xml',
            'Content-Disposition' => 'attachment; filename="mesa-' . $mesa->numero . '.svg"',
        ]);
    }

    public function pdf(Mesa $mesa, TableQrExportService $tableQrExportService)
    {
        if (!$mesa->is_qr_enabled) {
            return response()->json(['message' => 'El QR de esta mesa esta deshabilitado'], 409);
        }

        return response($tableQrExportService->pdfForMesa($mesa), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="mesa-' . $mesa->numero . '.pdf"',
        ]);
    }

    public function bulkExport(Request $request, TableQrExportService $tableQrExportService)
    {
        $validated = $request->validate([
            'format' => 'required|in:zip_svg,pdf',
            'table_ids' => 'sometimes|array',
            'table_ids.*' => 'integer|exists:mesas,id',
            'enabled_only' => 'sometimes|boolean',
        ]);

        $query = Mesa::query()->orderBy('numero');

        if (!empty($validated['table_ids'])) {
            $query->whereIn('id', $validated['table_ids']);
        }

        if ($validated['enabled_only'] ?? false) {
            $query->where('is_qr_enabled', true);
        }

        $mesas = $query->get();

        if ($mesas->isEmpty()) {
            return response()->json(['message' => 'No hay mesas para exportar'], 422);
        }

        if ($validated['format'] === 'zip_svg') {
            return $tableQrExportService->svgZipStream($mesas);
        }

        return response($tableQrExportService->paginatedPdf($mesas), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="mesas-qr.pdf"',
        ]);
    }
}
