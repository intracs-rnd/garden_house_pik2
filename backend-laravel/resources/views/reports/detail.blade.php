@extends('reports.layout')

@section('title', 'Laporan Detail Transaksi Akses')

@php
    $s = $report['summary'];
@endphp

@section('content')
    {{-- Ringkasan --}}
    <table class="summary-grid">
        <tr>
            <td><div class="summary-card"><div class="value">{{ number_format($s['total']) }}</div><div class="label">Total Tap</div></div></td>
            <td><div class="summary-card"><div class="value">{{ number_format($s['tab_in']) }}</div><div class="label">Tab In</div></div></td>
            <td><div class="summary-card"><div class="value">{{ number_format($s['tab_out']) }}</div><div class="label">Tab Out</div></div></td>
            <td><div class="summary-card"><div class="value">{{ number_format($s['granted']) }} / {{ number_format($s['denied']) }}</div><div class="label">Diterima / Ditolak</div></div></td>
        </tr>
    </table>

    <h2 class="section-title">Rincian Transaksi ({{ number_format(count($report['rows'])) }} baris)</h2>
    <table class="data">
        <thead>
            <tr>
                <th style="width:32px;" class="text-center">No</th>
                <th>Waktu</th>
                <th>Nomor Kartu</th>
                <th>Pemilik</th>
                <th>Arah</th>
                <th>Hasil</th>
                <th>Alasan</th>
                <th>Gate</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report['rows'] as $row)
                <tr>
                    <td class="text-center">{{ $row['no'] }}</td>
                    <td>{{ $row['tapped_at_label'] }}</td>
                    <td>{{ $row['card_number'] }}</td>
                    <td>{{ $row['owner'] }}</td>
                    <td>{{ $row['direction_label'] }}</td>
                    <td>
                        <span class="badge {{ $row['access_granted'] ? 'badge-success' : 'badge-danger' }}">
                            {{ $row['result_label'] }}
                        </span>
                    </td>
                    <td>{{ $row['reason_label'] }}</td>
                    <td>{{ $row['gate'] }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center">Tidak ada transaksi pada periode ini.</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection
