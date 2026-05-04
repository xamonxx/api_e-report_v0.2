<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Analisis</title>
    <style>
        @page {
            margin: 18px 20px 22px;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            color: #0f172a;
            font-size: 10px;
            line-height: 1.4;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            display: table-header-group;
        }

        tr, td, th {
            page-break-inside: avoid;
        }

        .hero {
            border: 1px solid #cfe0ff;
            border-radius: 18px;
            background: linear-gradient(135deg, #f8fbff 0%, #eef5ff 100%);
            padding: 22px 24px;
        }

        .eyebrow {
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 1.9px;
            font-weight: 800;
            color: #2563eb;
        }

        .title {
            margin-top: 7px;
            font-size: 25px;
            font-weight: 800;
            color: #0f172a;
        }

        .subtitle {
            margin-top: 5px;
            color: #475569;
            font-size: 10px;
        }

        .meta {
            margin-top: 14px;
        }

        .meta td {
            border: 1px solid #dbeafe;
            padding: 8px 10px;
            background: #ffffff;
        }

        .meta .label {
            width: 110px;
            font-weight: 700;
            color: #475569;
            background: #eff6ff;
        }

        .section {
            margin-top: 14px;
        }

        .section-title {
            font-size: 15px;
            font-weight: 800;
            margin-bottom: 7px;
            color: #0f172a;
        }

        .section-note {
            margin-top: -2px;
            margin-bottom: 7px;
            color: #64748b;
            font-size: 8.5px;
        }

        .cards td {
            width: 20%;
            vertical-align: top;
            padding-right: 7px;
        }

        .cards td:last-child {
            padding-right: 0;
        }

        .card {
            border: 1px solid #dbe4e7;
            border-radius: 14px;
            background: #ffffff;
            padding: 10px 11px;
            min-height: 66px;
        }

        .card-label {
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #64748b;
            font-weight: 700;
        }

        .card-value {
            font-size: 20px;
            font-weight: 800;
            margin-top: 7px;
        }

        .card-sub {
            margin-top: 2px;
            color: #64748b;
            font-size: 8px;
        }

        .panel {
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            background: #ffffff;
            padding: 11px 12px;
        }

        .grid-2 td {
            width: 50%;
            vertical-align: top;
            padding-right: 7px;
        }

        .grid-2 td:last-child {
            padding-right: 0;
        }

        .grid-3 td {
            width: 33.333%;
            vertical-align: top;
            padding-right: 7px;
        }

        .grid-3 td:last-child {
            padding-right: 0;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 999px;
            background: #dbeafe;
            color: #1d4ed8;
            font-size: 8px;
            font-weight: 800;
        }

        .insights {
            margin: 8px 0 0;
            padding-left: 18px;
        }

        .insights li {
            margin-bottom: 4px;
        }

        .funnel-bar {
            height: 9px;
            border-radius: 999px;
            background: #e2e8f0;
            overflow: hidden;
            margin-top: 6px;
        }

        .funnel-fill {
            height: 9px;
            border-radius: 999px;
        }

        .metric-row {
            margin-bottom: 8px;
        }

        .metric-head {
            font-size: 8.5px;
            margin-bottom: 3px;
        }

        .metric-name {
            font-weight: 700;
        }

        .metric-count {
            float: right;
            color: #475569;
        }

        .track {
            height: 7px;
            border-radius: 999px;
            background: #e2e8f0;
            overflow: hidden;
        }

        .fill {
            height: 7px;
            border-radius: 999px;
        }

        .report-table {
            table-layout: fixed;
            margin-top: 6px;
        }

        .report-table th,
        .report-table td {
            border: 1px solid #dbe4e7;
            padding: 6px 7px;
            vertical-align: top;
            word-break: break-word;
        }

        .report-table th {
            background: #1d4ed8;
            color: #ffffff;
            font-size: 8px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            text-align: left;
        }

        .report-table tbody tr:nth-child(even) td {
            background: #f8fafc;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .muted {
            color: #64748b;
        }

        .kpi-chip {
            display: inline-block;
            padding: 2px 7px;
            border-radius: 999px;
            background: #ecfeff;
            color: #0f766e;
            font-size: 8px;
            font-weight: 800;
        }

        .page-break {
            page-break-before: always;
        }
    </style>
</head>
<body>
    <div class="hero">
        <div class="eyebrow">Premium Analytics Export</div>
        <div class="title">Laporan Analisa Konsultasi</div>
        <div class="subtitle">Dokumen ini dirancang untuk kebutuhan reporting manajemen: tajam, ringkas, dapat dibaca cepat, namun tetap menyimpan detail operasional yang relevan.</div>

        <table class="meta">
            <tr>
                <td class="label">Periode</td>
                <td>{{ $periodLabel }}</td>
                <td class="label">Pembanding</td>
                <td>{{ $comparisonLabel }}</td>
            </tr>
            <tr>
                <td class="label">Akun</td>
                <td>{{ $selectedAccountName }}</td>
                <td class="label">Generated</td>
                <td>{{ $generatedAt->format('d/m/Y H:i') }}</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div class="section-title">Executive Snapshot</div>
        <table class="cards">
            <tr>
                <td><div class="card"><div class="card-label">Total Lead</div><div class="card-value">{{ number_format($totalLeads) }}</div><div class="card-sub">seluruh konsultasi pada periode aktif</div></div></td>
                <td><div class="card"><div class="card-label">Total Survey</div><div class="card-value">{{ number_format($totalSurveys) }}</div><div class="card-sub">{{ $conversionRate }}% dari total lead</div></div></td>
                <td><div class="card"><div class="card-label">Total Deal</div><div class="card-value">{{ number_format($totalDeals) }}</div><div class="card-sub">{{ $dealRate }}% dari total lead</div></div></td>
                <td><div class="card"><div class="card-label">Growth</div><div class="card-value">{{ $growthPercent }}%</div><div class="card-sub">dibanding {{ $comparisonLabel }}</div></div></td>
                <td><div class="card"><div class="card-label">Avg / Hari Aktif</div><div class="card-value">{{ $summaryStats['avg_per_active_day'] }}</div><div class="card-sub">rata-rata lead per hari aktif</div></div></td>
            </tr>
        </table>
    </div>

    <div class="section">
        <table class="grid-2">
            <tr>
                <td>
                    <div class="panel">
                        <div class="section-title" style="font-size: 13px; margin-bottom: 4px;">Funnel Conversion</div>
                        <div class="section-note">Menggambarkan perpindahan dari lead menuju survey dan deal.</div>

                        <div class="metric-row">
                            <div class="metric-head">
                                <span class="metric-name">Lead to Survey</span>
                                <span class="metric-count">{{ $funnel['survey_rate'] }}%</span>
                            </div>
                            <div class="funnel-bar"><div class="funnel-fill" style="width: {{ min($funnel['survey_rate'], 100) }}%; background: #2563eb;"></div></div>
                        </div>

                        <div class="metric-row">
                            <div class="metric-head">
                                <span class="metric-name">Lead to Deal</span>
                                <span class="metric-count">{{ $funnel['deal_rate'] }}%</span>
                            </div>
                            <div class="funnel-bar"><div class="funnel-fill" style="width: {{ min($funnel['deal_rate'], 100) }}%; background: #0f766e;"></div></div>
                        </div>

                        <div class="metric-row" style="margin-bottom: 0;">
                            <div class="metric-head">
                                <span class="metric-name">Survey to Deal</span>
                                <span class="metric-count">{{ $funnel['deal_from_survey_rate'] }}%</span>
                            </div>
                            <div class="funnel-bar"><div class="funnel-fill" style="width: {{ min($funnel['deal_from_survey_rate'], 100) }}%; background: #f59e0b;"></div></div>
                        </div>
                    </div>
                </td>
                <td>
                    <div class="panel">
                        <span class="badge">Insight Engine</span>
                        <ul class="insights">
                            @foreach($insights as $insight)
                                @php
                                    $insightText = is_array($insight)
                                        ? (string) ($insight['html'] ?? $insight['text'] ?? '')
                                        : (string) $insight;
                                @endphp
                                <li>{{ trim(strip_tags($insightText)) }}</li>
                            @endforeach
                        </ul>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div class="section-title">Data Quality & Governance</div>
        <table class="grid-3">
            <tr>
                <td>
                    <div class="panel">
                        <div class="card-label">Kelengkapan Lokasi</div>
                        <div class="card-value" style="font-size: 18px;">{{ $dataQuality['location_completion_rate'] }}%</div>
                        <div class="card-sub">{{ number_format($dataQuality['location_complete']) }} data dengan provinsi dan kota lengkap</div>
                    </div>
                </td>
                <td>
                    <div class="panel">
                        <div class="card-label">Kelengkapan Catatan</div>
                        <div class="card-value" style="font-size: 18px;">{{ $dataQuality['notes_completion_rate'] }}%</div>
                        <div class="card-sub">{{ number_format($dataQuality['notes_filled']) }} data punya notes follow-up</div>
                    </div>
                </td>
                <td>
                    <div class="panel">
                        <div class="card-label">Kesehatan Data</div>
                        <div class="card-value" style="font-size: 18px;">{{ number_format($dataQuality['duplicate_phone_rows']) }}</div>
                        <div class="card-sub">baris nomor telepon duplikat untuk audit cepat</div>
                    </div>
                </td>
            </tr>
        </table>

        <table class="report-table">
            <thead>
                <tr>
                    <th style="width: 18%;">Metrik</th>
                    <th style="width: 12%;">Jumlah</th>
                    <th style="width: 12%;">Rate</th>
                    <th style="width: 14%;">Cakupan</th>
                    <th style="width: 14%;">Admin Aktif</th>
                    <th style="width: 30%;">Catatan</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Provinsi terisi</td>
                    <td class="text-right">{{ $dataQuality['province_filled'] }}</td>
                    <td class="text-right">{{ $dataQuality['province_completion_rate'] }}%</td>
                    <td rowspan="3" class="text-center">
                        <div style="font-size: 18px; font-weight: 800;">{{ $dataQuality['unique_provinces'] }}</div>
                        <div class="muted">provinsi unik</div>
                        <div style="margin-top: 8px; font-size: 18px; font-weight: 800;">{{ $dataQuality['unique_cities'] }}</div>
                        <div class="muted">kota unik</div>
                    </td>
                    <td rowspan="3" class="text-center">
                        <div style="font-size: 18px; font-weight: 800;">{{ $dataQuality['active_admins'] }}</div>
                        <div class="muted">admin aktif</div>
                        <div style="margin-top: 8px; font-size: 18px; font-weight: 800;">{{ $dataQuality['active_days'] }}</div>
                        <div class="muted">hari aktif</div>
                    </td>
                    <td rowspan="3">Latest update tercatat pada {{ $dataQuality['latest_update'] }}. Metrik ini membantu menilai seberapa segar data untuk kebutuhan monitoring harian.</td>
                </tr>
                <tr>
                    <td>Kota terisi</td>
                    <td class="text-right">{{ $dataQuality['city_filled'] }}</td>
                    <td class="text-right">{{ $dataQuality['city_completion_rate'] }}%</td>
                </tr>
                <tr>
                    <td>Catatan terisi</td>
                    <td class="text-right">{{ $dataQuality['notes_filled'] }}</td>
                    <td class="text-right">{{ $dataQuality['notes_completion_rate'] }}%</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="section">
        <div class="section-title">Performance Trend</div>
        <div class="section-note">Melihat ritme volume, survey, dan deal per bucket periode untuk mendeteksi momentum tertinggi.</div>
        <table class="report-table">
            <thead>
                <tr>
                    <th style="width: 5%;">No</th>
                    <th style="width: 22%;">Periode</th>
                    <th style="width: 11%;">Lead</th>
                    <th style="width: 11%;">Survey</th>
                    <th style="width: 11%;">Deal</th>
                    <th style="width: 13%;">Survey Rate</th>
                    <th style="width: 13%;">Deal Rate</th>
                    <th style="width: 14%;">Highlight</th>
                </tr>
            </thead>
            <tbody>
                @forelse($trendSeries as $index => $item)
                    <tr>
                        <td class="text-center">{{ $index + 1 }}</td>
                        <td>{{ $item['full_label'] }}</td>
                        <td class="text-right">{{ $item['total'] }}</td>
                        <td class="text-right">{{ $item['surveys'] }}</td>
                        <td class="text-right">{{ $item['deals'] }}</td>
                        <td class="text-right">{{ $item['survey_rate'] }}%</td>
                        <td class="text-right">{{ $item['deal_rate'] }}%</td>
                        <td>
                            @if(($item['total'] ?? 0) === ($summaryStats['peak_period_total'] ?? null))
                                <span class="kpi-chip">Peak Volume</span>
                            @else
                                <span class="muted">Normal bucket</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="muted">Belum ada data tren untuk periode ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="section">
        <table class="grid-2">
            <tr>
                <td>
                    <div class="panel">
                        <div class="section-title" style="font-size: 13px; margin-bottom: 5px;">Distribusi Status</div>
                        @php $statusMax = $statusDistribution->max('count') ?: 1; @endphp
                        @foreach($statusDistribution as $item)
                            <div class="metric-row">
                                <div class="metric-head">
                                    <span class="metric-name">{{ $item['name'] }}</span>
                                    <span class="metric-count">{{ $item['count'] }}</span>
                                </div>
                                <div class="track">
                                    <div class="fill" style="width: {{ ($item['count'] / $statusMax) * 100 }}%; background: {{ $item['color'] }};"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </td>
                <td>
                    <div class="panel">
                        <div class="section-title" style="font-size: 13px; margin-bottom: 5px;">Kategori Kebutuhan</div>
                        @php $needsMax = $needsDistribution->max('count') ?: 1; @endphp
                        @forelse($needsDistribution as $item)
                            <div class="metric-row">
                                <div class="metric-head">
                                    <span class="metric-name">{{ $item['name'] }}</span>
                                    <span class="metric-count">{{ $item['count'] }}</span>
                                </div>
                                <div class="track">
                                    <div class="fill" style="width: {{ ($item['count'] / $needsMax) * 100 }}%; background: #2563eb;"></div>
                                </div>
                            </div>
                        @empty
                            <div class="muted">Belum ada data kategori kebutuhan pada periode ini.</div>
                        @endforelse
                    </div>
                </td>
            </tr>
        </table>
    </div>

    @if($provinceDistribution->isNotEmpty() || $cityDistribution->isNotEmpty() || $accountRanking->isNotEmpty())
    <div class="page-break"></div>

    <div class="section">
        <div class="section-title">Regional & Performance Breakdown</div>

        <table class="grid-2">
            <tr>
                <td>
                    <div class="panel">
                        <div class="section-title" style="font-size: 13px; margin-bottom: 4px;">Top Provinsi</div>
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th style="width: 8%;">No</th>
                                    <th style="width: 52%;">Provinsi</th>
                                    <th style="width: 20%;">Jumlah</th>
                                    <th style="width: 20%;">Persentase</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($provinceDistribution as $index => $item)
                                    <tr>
                                        <td class="text-center">{{ $index + 1 }}</td>
                                        <td>{{ $item['name'] }}</td>
                                        <td class="text-right">{{ $item['count'] }}</td>
                                        <td class="text-right">{{ $item['percentage'] }}%</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="muted">Belum ada data provinsi.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </td>
                <td>
                    <div class="panel">
                        <div class="section-title" style="font-size: 13px; margin-bottom: 4px;">Top Kota / Kabupaten</div>
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th style="width: 8%;">No</th>
                                    <th style="width: 52%;">Kota / Kabupaten</th>
                                    <th style="width: 20%;">Jumlah</th>
                                    <th style="width: 20%;">Persentase</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($cityDistribution as $index => $item)
                                    <tr>
                                        <td class="text-center">{{ $index + 1 }}</td>
                                        <td>{{ $item['name'] }}</td>
                                        <td class="text-right">{{ $item['count'] }}</td>
                                        <td class="text-right">{{ $item['percentage'] }}%</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="muted">Belum ada data kota / kabupaten.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </td>
            </tr>
        </table>

        <table class="grid-2" style="margin-top: 7px;">
            <tr>
                <td>
                    <div class="panel">
                        <div class="section-title" style="font-size: 13px; margin-bottom: 4px;">Segmen Jawa Barat</div>
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th style="width: 8%;">No</th>
                                    <th style="width: 54%;">Segmen</th>
                                    <th style="width: 18%;">Jumlah</th>
                                    <th style="width: 20%;">Kontribusi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php $totalWestJava = $westJavaSegmentDistribution->sum('count'); @endphp
                                @forelse($westJavaSegmentDistribution as $index => $item)
                                    <tr>
                                        <td class="text-center">{{ $index + 1 }}</td>
                                        <td>{{ $item['name'] }}</td>
                                        <td class="text-right">{{ $item['count'] }}</td>
                                        <td class="text-right">{{ $totalWestJava > 0 ? round(($item['count'] / $totalWestJava) * 100, 1) : 0 }}%</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="muted">Belum ada data segmen Jawa Barat.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </td>
                <td>
                    <div class="panel">
                        <div class="section-title" style="font-size: 13px; margin-bottom: 4px;">Ranking Akun</div>
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th style="width: 7%;">No</th>
                                    <th style="width: 34%;">Akun</th>
                                    <th style="width: 14%;">Lead</th>
                                    <th style="width: 14%;">Survey</th>
                                    <th style="width: 14%;">Deal</th>
                                    <th style="width: 17%;">Skor</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($accountRanking as $index => $item)
                                    <tr>
                                        <td class="text-center">{{ $index + 1 }}</td>
                                        <td>{{ $item['name'] }}</td>
                                        <td class="text-right">{{ $item['total'] }}</td>
                                        <td class="text-right">{{ $item['surveys'] }}</td>
                                        <td class="text-right">{{ $item['deals'] }}</td>
                                        <td class="text-right">{{ $item['score'] }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="muted">Belum ada data ranking akun.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </td>
            </tr>
        </table>

        <div class="panel" style="margin-top: 7px;">
            <div class="section-title" style="font-size: 13px; margin-bottom: 4px;">Ranking Admin</div>
            <table class="report-table">
                <thead>
                    <tr>
                        <th style="width: 6%;">No</th>
                        <th style="width: 26%;">Admin</th>
                        <th style="width: 36%;">Akun</th>
                        <th style="width: 14%;">Lead</th>
                        <th style="width: 18%;">Porsi</th>
                    </tr>
                </thead>
                <tbody>
                    @php $adminTotal = max($adminRanking->sum('total'), 1); @endphp
                    @forelse($adminRanking as $index => $item)
                        <tr>
                            <td class="text-center">{{ $index + 1 }}</td>
                            <td>{{ $item['name'] }}</td>
                            <td>{{ $item['account'] }}</td>
                            <td class="text-right">{{ $item['total'] }}</td>
                            <td class="text-right">{{ round(($item['total'] / $adminTotal) * 100, 1) }}%</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="muted">Belum ada data ranking admin.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @endif

    <div class="page-break"></div>

    <div class="section">
        <div class="section-title">Appendix: Data Konsultasi Terbaru</div>
        <div class="section-note">Menampilkan 25 baris terbaru untuk menjaga PDF tetap ringan. Seluruh data rinci dan formula audit tersedia penuh di file Excel.</div>
        <table class="report-table">
            <thead>
                <tr>
                    <th style="width: 10%;">ID</th>
                    <th style="width: 14%;">Klien</th>
                    <th style="width: 11%;">Telepon</th>
                    <th style="width: 12%;">Akun</th>
                    <th style="width: 11%;">Status</th>
                    <th style="width: 12%;">Kebutuhan</th>
                    <th style="width: 10%;">Kota</th>
                    <th style="width: 9%;">Tanggal</th>
                    <th style="width: 11%;">Update</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rawRows->take(25) as $row)
                    <tr>
                        <td>{{ $row['consultation_id'] }}</td>
                        <td>{{ $row['client_name'] }}</td>
                        <td>{{ $row['phone'] }}</td>
                        <td>{{ $row['account'] }}</td>
                        <td>{{ $row['status'] }}</td>
                        <td>{{ $row['need'] }}</td>
                        <td>{{ $row['city'] }}</td>
                        <td class="text-center">{{ $row['consultation_date'] }}</td>
                        <td class="text-center">{{ $row['updated_at'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="muted">Belum ada data pada periode ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</body>
</html>
