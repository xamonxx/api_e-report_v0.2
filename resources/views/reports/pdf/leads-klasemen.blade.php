<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Klasemen Akun Leads</title>
    <style>
        @page {
            margin: 10px 12px;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            color: #111827;
            font-size: 8px;
            line-height: 1.2;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        th, td {
            border: 1px solid #000;
            padding: 3px 4px;
            vertical-align: middle;
        }

        .sheet td,
        .sheet th {
            text-align: center;
        }

        .pc {
            width: 28px;
            background: #efefef;
            font-size: 14px;
            font-weight: 800;
        }

        .title {
            background: #f7f7f7;
            font-size: 20px;
            font-weight: 800;
            letter-spacing: .2px;
            height: 28px;
        }

        .subtitle {
            background: #f7f7f7;
            font-size: 16px;
            font-weight: 800;
            height: 28px;
        }

        .empty-line td {
            border-color: #d9d9d9;
            height: 16px;
            background: #fff;
        }

        .meta td {
            height: 21px;
            text-align: left;
            border-color: #d9d9d9;
            font-weight: 800;
        }

        .meta .rd {
            width: 28px;
            text-align: center;
        }

        .markers td {
            color: #ff0000;
            font-size: 7px;
            height: 11px;
            border-color: #d9d9d9;
            padding: 0;
        }

        .head th {
            height: 46px;
            font-weight: 800;
            font-size: 8.5px;
            text-transform: uppercase;
        }

        .subhead th {
            height: 22px;
            font-weight: 800;
            font-size: 8.5px;
        }

        .gray { background: #d9d9d9; }
        .yellow { background: #ffd966; }
        .blue { background: #bdd7ee; }
        .peach { background: #f8cbad; }
        .orange { background: #f4b183; }
        .light-green { background: #e2f0d9; }
        .green { background: #c6e0b4; }
        .dark-green { background: #a9d18e; }

        .separator td {
            background: #d9d9d9;
            height: 10px;
            padding: 0;
        }

        .body-row td {
            height: 17px;
            font-size: 8.5px;
        }

        .no {
            width: 28px;
            font-weight: 800;
        }

        .account {
            width: 220px;
            text-align: left !important;
            font-weight: 800;
            white-space: nowrap;
            overflow: hidden;
        }

        .number {
            font-size: 9px;
        }

        .zero {
            color: #b7b7b7;
        }

        .total td {
            height: 50px;
            font-size: 15px;
            font-weight: 800;
        }

        .total-label {
            font-size: 15px;
            text-align: center !important;
        }

        .page-break {
            page-break-before: always;
        }

        .surveyor-sheet th,
        .surveyor-sheet td {
            font-size: 6.2px;
            padding: 2px 2px;
        }

        .surveyor-sheet .title {
            font-size: 14px;
            height: 23px;
        }

        .surveyor-sheet .subtitle {
            font-size: 11px;
            height: 21px;
        }

        .surveyor-sheet .head th {
            height: 34px;
            font-size: 6.4px;
        }

        .surveyor-sheet .subhead th {
            height: 17px;
            font-size: 6.4px;
        }

        .surveyor-sheet .body-row td {
            height: 12px;
            font-size: 6.2px;
        }

        .surveyor-sheet .account {
            width: 120px;
            font-size: 6.2px;
        }

        .surveyor-sheet .total td {
            height: 24px;
            font-size: 11px;
        }
    </style>
</head>
<body>
@php
    $metricKeys = [
        'interaction',
        'remaining_consultation',
        'survey',
        'hold',
        'cancel',
        'deal_survey_current',
        'deal_survey_previous',
        'project_deal_current',
        'project_deal_next',
    ];
    $totalClasses = ['gray', 'yellow', 'blue', 'peach', 'orange', 'light-green', 'green', 'dark-green', 'yellow'];
    $surveyorGroups = [
        ['key' => 'survey', 'label' => 'SURVEY', 'class' => 'blue'],
        ['key' => 'hold', 'label' => 'HOLD', 'class' => 'peach'],
        ['key' => 'cancel', 'label' => 'CANCEL', 'class' => 'orange'],
        ['key' => 'deal_survey_current', 'label' => 'DEAL, SURVEY<br>PERIODE INI', 'class' => 'light-green'],
        ['key' => 'deal_survey_previous', 'label' => 'DEAL, SURVEY<br>PERIODE LALU', 'class' => 'green'],
        ['key' => 'deal_omset_current', 'label' => 'DEAL, OMSET<br>PERIODE INI', 'class' => 'dark-green'],
        ['key' => 'deal_omset_next', 'label' => 'DEAL, OMSET<br>PERIODE DEPAN', 'class' => 'yellow'],
    ];
@endphp

<table class="sheet">
    <colgroup>
        <col style="width: 28px;">
        <col style="width: 220px;">
        <col style="width: 74px;">
        <col style="width: 74px;">
        <col style="width: 74px;">
        <col style="width: 74px;">
        <col style="width: 74px;">
        <col style="width: 92px;">
        <col style="width: 92px;">
        <col style="width: 92px;">
        <col style="width: 92px;">
    </colgroup>
    <tr>
        <td class="pc" rowspan="2">PC</td>
        <td class="title" colspan="10">KLASEMEN AKUN</td>
    </tr>
    <tr>
        <td class="subtitle" colspan="10">PUTRA CORPORATION - PERIODE {{ $periodTitle }}</td>
    </tr>
    <tr class="empty-line">
        <td colspan="11"></td>
    </tr>
    <tr class="meta">
        <td class="rd">RD</td>
        <td colspan="2">{{ $generatedDateLabel }}</td>
        <td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
    </tr>
    <tr class="markers">
        @foreach([0, 1, 3, 6, 9, 12, 15, 18, 21, 24, 27] as $marker)
            <td>{{ $marker }}</td>
        @endforeach
    </tr>
    <tr class="head">
        <th class="gray" rowspan="2">NO</th>
        <th class="gray" rowspan="2">NAMA AKUN</th>
        <th class="gray">INTERAKSI</th>
        <th class="yellow">SISA KONSUL</th>
        <th class="blue">SURVEY</th>
        <th class="peach">HOLD</th>
        <th class="orange">CANCEL</th>
        <th class="light-green">DEAL, SURVEY<br>PERIODE INI</th>
        <th class="green">DEAL, SURVEY<br>PERIODE LALU</th>
        <th class="dark-green">PROJEK DEAL<br>PERIODE INI</th>
        <th class="yellow">PROJEK DEAL<br>PERIODE DEPAN</th>
    </tr>
    <tr class="subhead">
        <th class="gray">TOTAL</th>
        <th class="yellow">TOTAL</th>
        <th class="blue">TOTAL</th>
        <th class="peach">TOTAL</th>
        <th class="orange">TOTAL</th>
        <th class="light-green">TOTAL</th>
        <th class="green">TOTAL</th>
        <th class="dark-green">TOTAL</th>
        <th class="yellow">TOTAL</th>
    </tr>
    <tr class="separator"><td colspan="11"></td></tr>
    @foreach($klasemenRows as $index => $row)
        <tr class="body-row">
            <td class="no">{{ $index + 1 }}</td>
            <td class="account">{{ $row['account_name'] }}</td>
            @foreach($metricKeys as $key)
                @php $value = (int) ($row[$key] ?? 0); @endphp
                <td class="number {{ $value === 0 ? 'zero' : '' }}">{{ $value }}</td>
            @endforeach
        </tr>
    @endforeach
    <tr class="empty-line"><td colspan="11"></td></tr>
    <tr class="total">
        <td class="gray total-label" colspan="2">TOTAL</td>
        @foreach($metricKeys as $index => $key)
            <td class="{{ $totalClasses[$index] }}">{{ (int) ($klasemenTotals[$key] ?? 0) }}</td>
        @endforeach
    </tr>
</table>

<div class="page-break"></div>

<table class="sheet surveyor-sheet">
    <colgroup>
        <col style="width: 25px;">
        <col style="width: 120px;">
        @for($i = 0; $i < 21; $i++)
            <col style="width: 45px;">
        @endfor
    </colgroup>
    <tr>
        <td class="pc" rowspan="2">PC</td>
        <td class="title" colspan="22">KLASEMEN SURVEYOR</td>
    </tr>
    <tr>
        <td class="subtitle" colspan="22">PUTRA CORPORATION - PERIODE {{ $periodTitle }}</td>
    </tr>
    <tr class="empty-line">
        <td colspan="23"></td>
    </tr>
    <tr class="meta">
        <td class="rd">RD</td>
        <td colspan="2">{{ $generatedDateLabel }}</td>
        @for($i = 0; $i < 20; $i++)
            <td></td>
        @endfor
    </tr>
    <tr class="markers">
        @for($i = 0; $i < 23; $i++)
            <td>{{ $i }}</td>
        @endfor
    </tr>
    <tr class="head">
        <th class="gray" rowspan="2">NO</th>
        <th class="gray" rowspan="2">NAMA AKUN</th>
        @foreach($surveyorGroups as $group)
            <th class="{{ $group['class'] }}" colspan="3">{!! $group['label'] !!}</th>
        @endforeach
    </tr>
    <tr class="subhead">
        @foreach($surveyorGroups as $group)
            <th class="{{ $group['class'] }}">TOTAL</th>
            <th class="{{ $group['class'] }}">DK</th>
            <th class="{{ $group['class'] }}">LK</th>
        @endforeach
    </tr>
    <tr class="separator"><td colspan="23"></td></tr>
    @foreach($surveyorRows as $index => $row)
        <tr class="body-row">
            <td class="no">{{ $index + 1 }}</td>
            <td class="account">{{ $row['surveyor_name'] }}</td>
            @foreach($surveyorGroups as $group)
                @foreach(['total', 'dk', 'lk'] as $segment)
                    @php $value = (int) ($row[$group['key']][$segment] ?? 0); @endphp
                    <td class="number {{ $value === 0 ? 'zero' : '' }}">{{ $value }}</td>
                @endforeach
            @endforeach
        </tr>
    @endforeach
    <tr class="empty-line"><td colspan="23"></td></tr>
    <tr class="total">
        <td class="gray total-label" colspan="2" rowspan="2">TOTAL</td>
        @foreach($surveyorGroups as $group)
            <td class="{{ $group['class'] }}" rowspan="2">{{ (int) ($surveyorTotals[$group['key']]['total'] ?? 0) }}</td>
            <td class="{{ $group['class'] }}">{{ (int) ($surveyorTotals[$group['key']]['dk'] ?? 0) }}</td>
            <td class="{{ $group['class'] }}">{{ (int) ($surveyorTotals[$group['key']]['lk'] ?? 0) }}</td>
        @endforeach
    </tr>
    <tr class="total">
        @foreach($surveyorGroups as $group)
            <td class="{{ $group['class'] }}" colspan="2">{{ (int) ($surveyorTotals[$group['key']]['total'] ?? 0) }}</td>
        @endforeach
    </tr>
</table>
</body>
</html>
