<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Mesa {{ $mesa->numero }}</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #111827;
            margin: 0;
            padding: 32px;
        }
        .card {
            border: 3px solid #f59e0b;
            border-radius: 24px;
            padding: 24px;
            text-align: center;
        }
        .brand {
            color: #92400e;
            font-size: 14px;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-bottom: 12px;
        }
        h1 {
            margin: 0 0 16px;
            font-size: 38px;
        }
        .meta {
            font-size: 14px;
            margin-top: 16px;
            color: #4b5563;
            word-break: break-word;
        }
        .badge {
            display: inline-block;
            margin-top: 12px;
            padding: 6px 12px;
            border-radius: 999px;
            background: {{ $mesa->is_qr_enabled ? '#dcfce7' : '#fee2e2' }};
            color: {{ $mesa->is_qr_enabled ? '#166534' : '#991b1b' }};
            font-size: 12px;
        }
        .qr svg {
            width: 280px;
            height: 280px;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="brand">Gusto Bolivia</div>
        <h1>Mesa {{ $mesa->numero }}</h1>
        <div class="qr">{!! $qrSvg !!}</div>
        <div class="badge">{{ $mesa->is_qr_enabled ? 'QR habilitado' : 'QR deshabilitado' }}</div>
        <div class="meta">{{ $publicUrl }}</div>
    </div>
</body>
</html>
