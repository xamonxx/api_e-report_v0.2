<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @page {
            size: a4 landscape;
            margin: 18px 22px;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            color: #0f172a;
            font-size: 8.5px;
            line-height: 1.25;
            margin: 0;
            padding: 0;
        }

        .header-box {
            margin-bottom: 12px;
            border-bottom: 2px solid #0e7490;
            padding-bottom: 6px;
        }

        .header-title {
            font-size: 15px;
            font-weight: bold;
            color: #0e7490;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin: 0 0 3px 0;
        }

        .header-subtitle {
            font-size: 10px;
            color: #475569;
            font-weight: 600;
            margin: 0;
        }

        table.log-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        thead {
            display: table-header-group;
        }

        tr {
            page-break-inside: avoid;
        }

        th {
            background-color: #f1f5f9;
            color: #0f172a;
            font-size: 8.5px;
            font-weight: bold;
            text-transform: uppercase;
            border: 1px solid #cbd5e1;
            padding: 5px 4px;
            text-align: center;
            vertical-align: middle;
        }

        td {
            border: 1px solid #cbd5e1;
            padding: 4px 5px;
            vertical-align: middle;
            font-size: 8px;
        }

        .row-alt {
            background-color: #f8fafc;
        }

        .text-center {
            text-align: center;
        }

        .text-left {
            text-align: left;
        }

        .font-semibold {
            font-weight: bold;
        }

        /* Status Badges */
        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-weight: bold;
            font-size: 7.5px;
            letter-spacing: 0.2px;
            text-align: center;
        }

        .badge-green {
            background-color: #dcfce7;
            color: #15803d;
            border: 1px solid #86efac;
        }

        .badge-yellow {
            background-color: #fef9c3;
            color: #a16207;
            border: 1px solid #fde047;
        }

        .badge-blue {
            background-color: #dbeafe;
            color: #1d4ed8;
            border: 1px solid #93c5fd;
        }

        .badge-red {
            background-color: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fca5a5;
        }

        /* Legend */
        .legend-container {
            margin-top: 14px;
            page-break-inside: avoid;
            border-top: 1px solid #e2e8f0;
            padding-top: 8px;
        }

        .legend-title {
            font-size: 8.5px;
            font-weight: bold;
            color: #334155;
            margin-bottom: 5px;
        }

        table.legend-table {
            width: auto;
            border-collapse: collapse;
        }

        table.legend-table td {
            border: none;
            padding: 2px 8px 2px 0;
            font-size: 8px;
            color: #334155;
        }

        .legend-swatch {
            width: 12px;
            height: 12px;
            border-radius: 2px;
            display: inline-block;
            vertical-align: middle;
            margin-right: 4px;
        }

        .swatch-green {
            background-color: #dcfce7;
            border: 1px solid #16a34a;
        }

        .swatch-yellow {
            background-color: #fef9c3;
            border: 1px solid #ca8a04;
        }

        .swatch-blue {
            background-color: #dbeafe;
            border: 1px solid #2563eb;
        }

        .swatch-red {
            background-color: #fee2e2;
            border: 1px solid #dc2626;
        }
    </style>
</head>
<body>
    <div class="header-box">
        <h1 class="header-title">{{ $title }}</h1>
        <p class="header-subtitle">{{ $subtitle }}</p>
    </div>

    <table class="log-table">
        <thead>
            <tr>
                <th style="width: 4%;">NO</th>
                <th style="width: 10%;">TANGGAL</th>
                <th style="width: 10%;">JAM LAPORAN</th>
                <th style="width: 24%; text-align: left;">AKUN</th>
                <th style="width: 8%;">TEAM</th>
                <th style="width: 16%;">STATUS</th>
                <th style="width: 28%; text-align: left;">KETERANGAN</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr class="{{ $row['number'] % 2 === 0 ? 'row-alt' : '' }}">
                    <td class="text-center font-semibold">{{ $row['number'] }}</td>
                    <td class="text-center">{{ $row['date'] }}</td>
                    <td class="text-center">{{ $row['time'] }}</td>
                    <td class="text-left font-semibold">{{ strtoupper($row['account_name']) }}</td>
                    <td class="text-center">{{ strtoupper($row['team']) }}</td>
                    <td class="text-center">
                        @php
                            $badgeClass = match ($row['status_style']) {
                                'statusAdaWa' => 'badge-green',
                                'statusNolWa' => 'badge-yellow',
                                'statusLibur' => 'badge-blue',
                                default => 'badge-red',
                            };
                        @endphp
                        <span class="badge {{ $badgeClass }}">{{ $row['status_label'] }}</span>
                    </td>
                    <td class="text-left">{{ $row['description'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center" style="padding: 15px; color: #64748b;">
                        Tidak ada data log laporan konsul pada periode yang dipilih.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="legend-container">
        <div class="legend-title">KETERANGAN STATUS:</div>
        <table class="legend-table">
            <tr>
                <td><span class="legend-swatch swatch-green"></span> Laporan - ada WA Konsumen baru</td>
                <td><span class="legend-swatch swatch-yellow"></span> Laporan - 0 data WA Konsumen baru</td>
                <td><span class="legend-swatch swatch-blue"></span> Rekapan laporan susulan / Hari Libur</td>
                <td><span class="legend-swatch swatch-red"></span> Tidak laporan</td>
            </tr>
        </table>
    </div>
</body>
</html>
