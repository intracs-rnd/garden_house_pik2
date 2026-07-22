<?php

namespace App\Services;

use App\Support\SimpleXlsxWriter;

/**
 * Turns a report payload (produced by {@see ReportService}) into an .xlsx
 * workbook binary, using the dependency-free {@see SimpleXlsxWriter}.
 */
class ReportExcelExporter
{
    /**
     * Build the recap workbook.
     *
     * The distinctive "Rekap per Waktu" sheet is placed first so the file
     * visibly opens on the recap table (not the shared summary), with the
     * "Ringkasan" context sheet kept last.
     */
    public function recap(array $report): string
    {
        $xlsx = new SimpleXlsxWriter();

        $xlsx->addSheet('Rekap per Waktu', $this->timelineSheet($report), [
            'headerRow' => true,
            // Last row is the bold "Total" row.
            'boldRows'  => [count($report['timeline'] ?? []) + 1 => true],
        ]);
        $xlsx->addSheet('Per Alasan', $this->reasonSheet($report), ['headerRow' => true]);
        $xlsx->addSheet('Per Gate', $this->gateSheet($report), ['headerRow' => true]);
        $xlsx->addSheet('Ringkasan', $this->summarySheet($report), ['headerRow' => true]);

        return $xlsx->output();
    }

    /**
     * Build the detail workbook.
     *
     * The per-tap "Detail Transaksi" sheet is placed first so the file opens
     * directly on the transaction list, with "Ringkasan" kept last.
     */
    public function detail(array $report): string
    {
        $xlsx = new SimpleXlsxWriter();

        $xlsx->addSheet('Detail Transaksi', $this->detailSheet($report), ['headerRow' => true]);
        $xlsx->addSheet('Ringkasan', $this->summarySheet($report), ['headerRow' => true]);

        return $xlsx->output();
    }

    /**
     * Build the gate control workbook (single sheet).
     */
    public function gateControl(array $report): string
    {
        $xlsx = new SimpleXlsxWriter();

        // Summary sheet
        $s = $report['summary'] ?? [];
        $summaryRows = [
            ['Metrik', 'Nilai'],
            ['Periode', $report['period_label'] ?? '-'],
            ['Rentang', $report['range_label'] ?? '-'],
            ['Filter Gate', $report['gate_filter'] ?: 'Semua gate'],
            ['Dicetak', $report['generated_at'] ?? '-'],
            [],
            ['Total Event', (int) ($s['total'] ?? 0)],
            ['Buka Gate', (int) ($s['open'] ?? 0)],
            ['Tutup Gate', (int) ($s['close'] ?? 0)],
        ];
        $xlsx->addSheet('Ringkasan', $summaryRows, ['headerRow' => true]);

        // Detail sheet
        $detailRows = [['No', 'Waktu', 'Gate', 'Aksi', 'No. Plat', 'Operator', 'Hasil']];
        foreach ($report['rows'] ?? [] as $row) {
            $detailRows[] = [
                (int)    $row['no'],
                (string) $row['event_ts'],
                (string) $row['gate_id'],
                (string) ($row['action_label'] ?? $row['action']),
                (string) $row['nomor_plat'],
                (string) $row['user_name'],
                (string) $row['result'],
            ];
        }
        $xlsx->addSheet('Kontrol Gate', $detailRows, ['headerRow' => true]);

        return $xlsx->output();
    }

    /*
    |--------------------------------------------------------------------------
    | Sheet builders
    |--------------------------------------------------------------------------
    */

    private function summarySheet(array $report): array
    {
        $s = $report['summary'] ?? [];

        $rows = [
            ['Metrik', 'Nilai'],
            ['Jenis Laporan', $report['type'] === 'rekap' ? 'Rekapitulasi' : 'Detail Transaksi'],
            ['Periode', $report['period_label'] ?? '-'],
            ['Rentang', $report['range']['label'] ?? '-'],
            ['Filter', ! empty($report['filters']) ? implode(' | ', $report['filters']) : 'Tidak ada'],
            ['Dicetak', $report['generated_at'] ?? '-'],
            [],
            ['Total Tap', (int) ($s['total'] ?? 0)],
            ['Tab In', (int) ($s['tab_in'] ?? 0)],
            ['Tab Out', (int) ($s['tab_out'] ?? 0)],
            ['Akses Diterima', (int) ($s['granted'] ?? 0)],
            ['Akses Ditolak', (int) ($s['denied'] ?? 0)],
            ['Tingkat Diterima (%)', (float) ($s['grant_rate'] ?? 0)],
            ['Pemilik Unik', (int) ($s['unique_users'] ?? 0)],
        ];

        return $rows;
    }

    private function timelineSheet(array $report): array
    {
        $head = $this->timelineHeader($report['period'] ?? 'bulanan');
        $rows = [[$head, 'Total', 'Tab In', 'Tab Out', 'Diterima', 'Ditolak']];

        foreach ($report['timeline'] ?? [] as $bucket) {
            $rows[] = [
                (string) $bucket['label'],
                (int) $bucket['total'],
                (int) $bucket['in'],
                (int) $bucket['out'],
                (int) $bucket['granted'],
                (int) $bucket['denied'],
            ];
        }

        $s = $report['summary'] ?? [];
        $rows[] = [
            'Total',
            (int) ($s['total'] ?? 0),
            (int) ($s['tab_in'] ?? 0),
            (int) ($s['tab_out'] ?? 0),
            (int) ($s['granted'] ?? 0),
            (int) ($s['denied'] ?? 0),
        ];

        return $rows;
    }

    private function reasonSheet(array $report): array
    {
        $rows = [['Alasan', 'Total', 'Diterima', 'Ditolak']];

        foreach ($report['by_reason'] ?? [] as $reason) {
            $rows[] = [
                (string) $reason['label'],
                (int) $reason['total'],
                (int) $reason['granted'],
                (int) $reason['denied'],
            ];
        }

        return $rows;
    }

    private function gateSheet(array $report): array
    {
        $rows = [['Gate', 'Total', 'Tab In', 'Tab Out']];

        foreach ($report['by_gate'] ?? [] as $gate) {
            $rows[] = [
                (string) $gate['gate'],
                (int) $gate['total'],
                (int) $gate['in'],
                (int) $gate['out'],
            ];
        }

        return $rows;
    }

    private function detailSheet(array $report): array
    {
        $rows = [['No', 'Waktu', 'Nomor Kartu', 'Pemilik', 'Arah', 'Hasil', 'Alasan', 'Gate']];

        foreach ($report['rows'] ?? [] as $row) {
            $rows[] = [
                (int) $row['no'],
                (string) $row['tapped_at_label'],
                (string) $row['card_number'],
                (string) $row['owner'],
                (string) $row['direction_label'],
                (string) $row['result_label'],
                (string) $row['reason_label'],
                (string) $row['gate'],
            ];
        }

        return $rows;
    }

    private function timelineHeader(string $period): string
    {
        $map = [
            'harian'  => 'Jam',
            'bulanan' => 'Tanggal',
            'tahunan' => 'Bulan',
        ];

        return $map[$period] ?? 'Periode';
    }
}
