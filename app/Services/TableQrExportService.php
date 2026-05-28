<?php

namespace App\Services;

use App\Models\Mesa;
use Barryvdh\DomPDF\Facade\Pdf;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipStream\ZipStream;

class TableQrExportService
{
    public function __construct(
        private readonly PublicTableSessionService $publicTableSessionService,
    ) {
    }

    public function svgForMesa(Mesa $mesa): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(320, 8),
            new SvgImageBackEnd()
        );

        $writer = new Writer($renderer);

        return $writer->writeString($this->publicTableSessionService->publicUrlForMesa($mesa));
    }

    public function pdfForMesa(Mesa $mesa): string
    {
        return Pdf::loadView('pdf.table-qr-single', [
            'mesa' => $mesa,
            'publicUrl' => $this->publicTableSessionService->publicUrlForMesa($mesa),
            'qrSvg' => $this->svgForMesa($mesa),
        ])->setPaper('a4')->output();
    }

    public function paginatedPdf(Collection $mesas): string
    {
        $pages = $mesas->map(fn (Mesa $mesa) => [
            'mesa' => $mesa,
            'public_url' => $this->publicTableSessionService->publicUrlForMesa($mesa),
            'qr_svg' => $this->svgForMesa($mesa),
        ]);

        return Pdf::loadView('pdf.table-qr-bulk', [
            'pages' => $pages,
        ])->setPaper('a4')->output();
    }

    public function svgZipStream(Collection $mesas, string $downloadName = 'mesas-qr-svg.zip'): StreamedResponse
    {
        return response()->streamDownload(function () use ($mesas, $downloadName) {
            $zip = new ZipStream(outputName: $downloadName, sendHttpHeaders: false);

            foreach ($mesas as $mesa) {
                $filename = 'mesa-' . $mesa->numero . '-' . Str::slug($mesa->uuid) . '.svg';
                $zip->addFile(fileName: $filename, data: $this->svgForMesa($mesa));
            }

            $zip->finish();
        }, $downloadName, [
            'Content-Type' => 'application/zip',
        ]);
    }
}
