@extends('reports.layout')

@section('title', 'Laporan Rekap Transaksi Akses')

@php
    $bucketTitle = [
        'harian'  => 'per Jam',
        'bulanan' => 'per Tanggal',
        'tahunan' => 'per Bulan',
    ][$report['period']] ?? 'per Periode';

    $bucketHead = [
        'harian'  => 'Jam',
        'bulanan' => 'Tanggal',
        'tahunan' => 'Bulan',
    ][$report['period']] ?? 'Periode';

    $s = $report['summary'];
@endphp

@section('content')
    {{-- Ringkasan --}}
    <table class="summary-grid">
        <tr>
            <td><div class="summary-card"><div class="value">{{ number_format($s['total']) }}</div><div class="label">Total Tap</div></div></td>
            <td><div class="summary-card"><div class="value">{{ number_format($s['tab_in']) }}</div><div class="label">Tab In</div></div></td>
            <td><div class="summary-card"><div class="value">{{ number_format($s['tab_out']) }}</div><div class="label">Tab Out</div></div></td>
            <td><div class="summary-card"><div class="value">{{ $s['grant_rate'] }}%</div><div class="label">Tingkat Diterima</div></div></td>
        </tr>
        <tr>
            <td><div class="summary-card"><div class="value">{{ number_format($s['granted']) }}</div><div class="label">Akses Diterima</div></div></td>
            <td><div class="summary-card"><div class="value">{{ number_format($s['denied']) }}</div><div class="label">Akses Ditolak</div></div></td>
            <td><div class="summary-card"><div class="value">{{ number_format($s['unique_users']) }}</div><div class="label">Pemilik Unik</div></div></td>
        </tr>
    </table>

    {{-- Rekap per waktu --}}
    <h2 class="section-title">Rekap {{ $bucketTitle }}</h2>
    <table class="data">
        <thead>
            <tr>
                <th>{{ $bucketHead }}</th>
                <th class="text-right">Total</th>
                <th class="text-right">Tab In</th>
                <th class="text-right">Tab Out</th>
                <th class="text-right">Diterima</th>
                <th class="text-right">Ditolak</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($report['timeline'] as $bucket)
                <tr>
                    <td>{{ $bucket['label'] }}</td>
                    <td class="text-right">{{ number_format($bucket['total']) }}</td>
                    <td class="text-right">{{ number_format($bucket['in']) }}</td>
                    <td class="text-right">{{ number_format($bucket['out']) }}</td>
                    <td class="text-right">{{ number_format($bucket['granted']) }}</td>
                    <td class="text-right">{{ number_format($bucket['denied']) }}</td>
                </tr>
            @endforeach
            <tr style="font-weight:bold; background:#eef2ff;">
                <td>Total</td>
                <td class="text-right">{{ number_format($s['total']) }}</td>
                <td class="text-right">{{ number_format($s['tab_in']) }}</td>
                <td class="text-right">{{ number_format($s['tab_out']) }}</td>
                <td class="text-right">{{ number_format($s['granted']) }}</td>
                <td class="text-right">{{ number_format($s['denied']) }}</td>
            </tr>
        </tbody>
    </table>

    {{-- Rekap per alasan --}}
    <h2 class="section-title">Rekap per Alasan Keputusan</h2>
    <table class="data">
        <thead>
            <tr>
                <th>Alasan</th>
                <th class="text-right">Total</th>
                <th class="text-right">Diterima</th>
                <th class="text-right">Ditolak</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report['by_reason'] as $reason)
                <tr>
                    <td>{{ $reason['label'] }}</td>
                    <td class="text-right">{{ number_format($reason['total']) }}</td>
                    <td class="text-right">{{ number_format($reason['granted']) }}</td>
                    <td class="text-right">{{ number_format($reason['denied']) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center">Tidak ada data.</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- Rekap per gate --}}
    <h2 class="section-title">Rekap per Gate</h2>
    <table class="data">
        <thead>
            <tr>
                <th>Gate</th>
                <th class="text-right">Total</th>
                <th class="text-right">Tab In</th>
                <th class="text-right">Tab Out</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report['by_gate'] as $gate)
                <tr>
                    <td>{{ $gate['gate'] }}</td>
                    <td class="text-right">{{ number_format($gate['total']) }}</td>
                    <td class="text-right">{{ number_format($gate['in']) }}</td>
                    <td class="text-right">{{ number_format($gate['out']) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center">Tidak ada data.</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection
