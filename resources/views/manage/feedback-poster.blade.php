<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Feedback poster · {{ $event->title }}</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/5.3.0/css/bootstrap.min.css" integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM" crossorigin="anonymous">
    <style>
        @page { size: A4; margin: 15mm; }
        @media print { .no-print { display: none !important; } body { background: #fff !important; } }
        .poster { max-width: 180mm; min-height: 250mm; }
        #qr img, #qr canvas { margin: 0 auto; width: 90mm !important; height: 90mm !important; }
    </style>
</head>
<body class="bg-body-tertiary">
    <div class="no-print text-center py-3">
        <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">Print / save as PDF</button>
    </div>

    {{-- A4 poster for the venue: the QR code opens the feedback form (sign-in needed). --}}
    <main class="poster mx-auto bg-white border rounded p-5 d-flex flex-column align-items-center justify-content-between text-center">
        <div>
            <div class="text-muted">{{ $event->organisation?->name }}</div>
            <h1 class="display-6 fw-semibold mt-2">{{ $event->title }}</h1>
            <div class="text-muted">{{ $event->starts_at->format('j F Y') }}</div>
        </div>

        <div>
            <h2 class="h3 fw-semibold mb-4">How was it? Tell us!</h2>
            <div id="qr" aria-label="QR code for the feedback form"></div>
            <p class="text-muted mt-4 mb-1">Scan with your phone camera</p>
            <p class="small text-break mb-0">{{ $url }}</p>
        </div>

        <div class="text-muted small">Takes less than a minute · Thank you for coming</div>
    </main>

    {{-- QR code drawn in the browser (qrcodejs 1.0.0); no server package needed. --}}
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script>
        new QRCode(document.getElementById('qr'), { text: @json($url), width: 512, height: 512, correctLevel: QRCode.CorrectLevel.M });
    </script>
</body>
</html>
