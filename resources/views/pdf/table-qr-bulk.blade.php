<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Mesas QR</title>
    <style>
        @page {
            margin: 24px;
        }
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #111827;
        }
        .page {
            page-break-after: always;
            border: 2px solid #f59e0b;
            border-radius: 20px;
            padding: 24px;
            text-align: center;
        }
        .page:last-child {
            page-break-after: auto;
        }
        h1 {
            margin: 0 0 12px;
            font-size: 34px;
        }
        .subtitle {
            font-size: 12px;
            text-transform: uppercase;
            color: #92400e;
            letter-spacing: 2px;
        }
        .url {
            margin-top: 14px;
            font-size: 12px;
            color: #4b5563;
            word-break: break-word;
        }
        .status {
            margin-top: 10px;
            font-size: 12px;
            color: #374151;
        }
        .qr svg {
            width: 260px;
            height: 260px;
        }
    </style>
</head>
<body>
@foreach ($pages as $page)
    <div class="page">
        <div class="subtitle">Gusto Bolivia</div>
        <h1>Mesa {{ $page['mesa']->numero }}</h1>
        <div class="qr">{!! $page['qr_svg'] !!}</div>
        <div class="status">{{ $page['mesa']->is_qr_enabled ? 'QR habilitado' : 'QR deshabilitado' }}</div>
        <div class="url">{{ $page['public_url'] }}</div>
    </div>
@endforeach
</body>
</html>
