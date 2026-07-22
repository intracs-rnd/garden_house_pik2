<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Kontrol Gate</title>
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
        .badge-success  { background: #dcfce7; color: #166534; }
        .badge-secondary { background: #f3f4f6; color: #374151; }

        .summary-grid { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        .summary-grid td { width: 33.33%; padding: 3px; vertical-align: top; }
        .summary-card { border: 1px solid #e5e7eb; border-radius: 6px; padding: 8px 10px; }
        .summary-card .value { font-size: 17px; font-weight: bold; color: #111827; }
        .summary-card .label { font-size: 8px; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; margin-top: 2px; }

        .doc-footer { margin-top: 6px; font-size: 8px; color: #9ca3af; text-align: center; }
    </style>
</head>
<body>
    <div class="doc-header">
        <h1>Laporan Kontrol Gate</h1>
        <div class="subtitle">GH PIK2 &middot; Log Buka / Tutup Gate Manual</div>
    </div>

    <table class="meta-table">
        <tr>
            <td class="label">Jenis Laporan</td>
            <td>Kontrol Gate &mdash; {{ $report['period_label'] }}</td>
        </tr>
        <tr>
            <td class="label">Periode</td>
            <td>{{ $report['range_label'] }}</td>
        </tr>
        @if ($report['gate_filter'])
            <tr>
                <td class="label">Filter Gate</td>
                <td>{{ $report['gate_filter'] }}</td>
            </tr>
        @endif
        <tr>
            <td class="label">Dicetak</td>
            <td>{{ $report['generated_at'] }}</td>
        </tr>
    </table>

    @php $s = $report['summary']; @endphp

    <table class="summary-grid">
        <tr>
            <td><div class="summary-card"><div class="value">{{ number_format($s['total']) }}</div><div class="label">Total Event</div></div></td>
            <td><div class="summary-card"><div class="value">{{ number_format($s['open']) }}</div><div class="label">Buka Gate</div></div></td>

        </tr>
    </table>

    <h2 class="section-title">Rincian Kontrol Gate ({{ number_format(count($report['rows'])) }} baris)</h2>
    <table class="data">
        <thead>
            <tr>
                <th style="width:28px;" class="text-center">No</th>
                <th>Waktu</th>
                <th>Gate</th>
                <th>Aksi</th>
                <th>No. Plat</th>
                <th>Operator</th>
                <th>Hasil</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report['rows'] as $row)
                <tr>
                    <td class="text-center">{{ $row['no'] }}</td>
                    <td>{{ $row['event_ts'] }}</td>
                    <td>{{ $row['gate_id'] }}</td>
                    <td>
                        <span class="badge {{ strtoupper($row['action']) === 'OPEN' ? 'badge-success' : 'badge-secondary' }}">
                            {{ $row['action_label'] }}
                        </span>
                    </td>
                    <td>{{ $row['nomor_plat'] }}</td>
                    <td>{{ $row['user_name'] }}</td>
                    <td>{{ $row['result'] }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center">Tidak ada event kontrol gate pada periode ini.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="doc-footer">
        Dokumen ini dibuat otomatis oleh sistem GH PIK2 pada {{ $report['generated_at'] }}.
    </div>
</body>
</html>
