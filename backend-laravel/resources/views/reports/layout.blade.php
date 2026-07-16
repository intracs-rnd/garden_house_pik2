<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>@yield('title', 'Laporan Transaksi Akses Kartu')</title>
    <style>
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { color: #1f2937; font-size: 11px; margin: 0; }

        .doc-header { border-bottom: 2px solid #4f46e5; padding-bottom: 10px; margin-bottom: 14px; }
        .doc-header h1 { margin: 0; font-size: 18px; color: #4f46e5; }
        .doc-header .subtitle { color: #6b7280; font-size: 11px; margin-top: 3px; }

        .meta-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .meta-table td { padding: 2px 0; font-size: 10px; color: #374151; vertical-align: top; }
        .meta-table td.label { width: 120px; color: #6b7280; }

        table.data { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        table.data th, table.data td { border: 1px solid #e5e7eb; padding: 5px 8px; text-align: left; }
        table.data thead th { background: #eef2ff; color: #3730a3; font-size: 9px; text-transform: uppercase; letter-spacing: .03em; }
        table.data tbody td { font-size: 10px; }
        table.data tbody tr:nth-child(even) { background: #fafafa; }

        .section-title { font-size: 13px; color: #111827; margin: 0 0 8px; border-left: 3px solid #4f46e5; padding-left: 8px; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }

        .badge { padding: 1px 6px; border-radius: 8px; font-size: 9px; }
        .badge-success { background: #dcfce7; color: #166534; }
        .badge-danger { background: #fee2e2; color: #991b1b; }

        .summary-grid { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        .summary-grid td { width: 25%; padding: 3px; vertical-align: top; }
        .summary-card { border: 1px solid #e5e7eb; border-radius: 6px; padding: 8px 10px; }
        .summary-card .value { font-size: 17px; font-weight: bold; color: #111827; }
        .summary-card .label { font-size: 8px; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; margin-top: 2px; }

        .doc-footer { margin-top: 6px; font-size: 8px; color: #9ca3af; text-align: center; }
    </style>
</head>
<body>
    <div class="doc-header">
        <h1>@yield('title', 'Laporan Transaksi Akses Kartu')</h1>
        <div class="subtitle">GH PIK2 &middot; Sistem Akses Kartu &amp; Kendaraan</div>
    </div>

    <table class="meta-table">
        <tr>
            <td class="label">Jenis Laporan</td>
            <td>{{ $report['type'] === 'rekap' ? 'Rekapitulasi' : 'Detail Transaksi' }} &mdash; {{ $report['period_label'] }}</td>
        </tr>
        <tr>
            <td class="label">Periode</td>
            <td>{{ $report['range']['label'] }}</td>
        </tr>
        @if (!empty($report['filters']))
            <tr>
                <td class="label">Filter</td>
                <td>{{ implode(' · ', $report['filters']) }}</td>
            </tr>
        @endif
        <tr>
            <td class="label">Dicetak</td>
            <td>{{ $report['generated_at'] }}</td>
        </tr>
    </table>

    @yield('content')

    <div class="doc-footer">
        Dokumen ini dibuat otomatis oleh sistem GH PIK2 pada {{ $report['generated_at'] }}.
    </div>
</body>
</html>
