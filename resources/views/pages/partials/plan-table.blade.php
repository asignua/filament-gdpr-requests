@php
    use Asignua\FilamentGdprRequests\Support\Lang;

    // Rows come from the live preview while it exists, and from the stored report afterwards.
    $rows = [];
    foreach ($groups as $group) {
        $rows[] = ['label' => $group->label(), 'table' => $group->table(), 'action' => $group->action, 'matched' => $group->matched, 'trashed' => $group->trashed, 'group' => $group, 'step' => $steps->get($group->key())];
    }
    if ($rows === []) {
        foreach ($steps as $step) {
            $rows[] = ['label' => $step->label, 'table' => null, 'action' => $step->strategy === '' ? 'none' : $step->strategy, 'matched' => $step->matched, 'trashed' => $step->trashed, 'group' => null, 'step' => $step];
        }
    }
@endphp

<style>
    .gdpr-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
    .gdpr-table th { padding: .5rem 1rem .5rem 0; text-align: start; font-size: .75rem; text-transform: uppercase; letter-spacing: .03em; color: rgb(107 114 128); border-bottom: 1px solid rgb(229 231 235); white-space: nowrap; }
    .gdpr-table td { padding: .75rem 1rem .75rem 0; vertical-align: top; border-bottom: 1px solid rgb(243 244 246); }
    .gdpr-table .num { text-align: end; font-variant-numeric: tabular-nums; }
    .gdpr-table .sub { font-family: ui-monospace, monospace; font-size: .75rem; color: rgb(107 114 128); }
    .gdpr-table .note { font-size: .75rem; line-height: 1.4; }
    .gdpr-table .err { color: rgb(220 38 38); font-size: .75rem; margin-top: .25rem; }
    .dark .gdpr-table th { color: rgb(156 163 175); border-color: rgba(255,255,255,.1); }
    .dark .gdpr-table td { border-color: rgba(255,255,255,.06); }
    .dark .gdpr-table .sub { color: rgb(156 163 175); }
    .dark .gdpr-table .err { color: rgb(248 113 113); }
    .gdpr-scroll { overflow-x: auto; }
    .gdpr-table .sub, .gdpr-table .note { overflow-wrap: anywhere; }
    /* On a phone every row becomes a card: no column scrolls out of sight, each value says what it is. */
    @media (max-width: 640px) {
        .gdpr-table, .gdpr-table tbody, .gdpr-table tr, .gdpr-table td { display: block; width: 100%; }
        .gdpr-table thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); }
        .gdpr-table tr { padding: .5rem 0; border-bottom: 1px solid rgb(229 231 235); }
        .gdpr-table td { padding: .375rem 0; border-bottom: 0; }
        .gdpr-table td::before { content: attr(data-label); display: block; font-size: .75rem; letter-spacing: .03em; text-transform: uppercase; color: rgb(107 114 128); }
        .gdpr-table .num { text-align: start; }
        .dark .gdpr-table tr { border-color: rgba(255,255,255,.1); }
        .dark .gdpr-table td::before { color: rgb(156 163 175); }
    }
</style>

<div class="gdpr-scroll">
    {{-- The roles keep it a table for a screen reader when the phone layout turns its rows into blocks. --}}
    <table class="gdpr-table" role="table">
        <thead role="rowgroup">
            <tr role="row">
                <th role="columnheader">{{ Lang::t('plan.entry') }}</th>
                <th role="columnheader">{{ Lang::t('plan.action') }}</th>
                <th role="columnheader" class="num">{{ Lang::t('plan.rows') }}</th>
                <th role="columnheader" class="num">{{ Lang::t('plan.trashed') }}</th>
                <th role="columnheader">{{ Lang::t('plan.details') }}</th>
                <th role="columnheader">{{ Lang::t('view.status') }}</th>
            </tr>
        </thead>
        <tbody role="rowgroup">
            @foreach ($rows as $row)
                @php($group = $row['group'])
                @php($step = $row['step'])
                <tr role="row">
                    <td role="cell" data-label="{{ Lang::t('plan.entry') }}">
                        <div style="font-weight:500">{{ $row['label'] }}</div>
                        @if ($row['table'])
                            <div class="sub">{{ $row['table'] }}</div>
                        @endif
                    </td>
                    <td role="cell" data-label="{{ Lang::t('plan.action') }}">
                        <x-filament::badge :color="match ($row['action']) { 'delete' => 'danger', 'anonymize' => 'warning', 'retain' => 'info', default => 'gray' }">
                            {{ Lang::t('action.'.$row['action']) }}
                        </x-filament::badge>
                    </td>
                    <td role="cell" class="num" data-label="{{ Lang::t('plan.rows') }}">{{ $row['matched'] }}</td>
                    <td role="cell" class="num" data-label="{{ Lang::t('plan.trashed') }}">{{ $row['trashed'] }}</td>
                    <td role="cell" class="note" data-label="{{ Lang::t('plan.details') }}">
                        @if ($group && $group->action === 'anonymize')
                            <div>{{ Lang::t('plan.would_change', ['count' => $group->changing]) }}</div>
                            @foreach ($group->rules as $rule)
                                <div class="sub">{{ $rule }}</div>
                            @endforeach
                        @elseif ($row['action'] === 'retain')
                            {{-- After the run the live preview is gone: the step keeps what it was retained on, and until when. --}}
                            @php($kept = $step?->details() ?? [])
                            <div>{{ Lang::t('plan.basis', ['basis' => $group?->basis ?? ($kept['basis'] ?? '—')]) }}</div>
                            <div>{{ Lang::t('plan.until', ['date' => $group?->retainedUntil ?? ($kept['until'] ?? '—')]) }}</div>
                        @elseif ($row['action'] === 'delete')
                            <div>{{ Lang::t('plan.delete_note') }}</div>
                        @elseif ($row['action'] === 'none')
                            <div>{{ Lang::t('plan.no_rule') }}</div>
                        @endif
                    </td>
                    <td role="cell" data-label="{{ Lang::t('view.status') }}">
                        @if ($step)
                            <x-filament::badge :color="$step->status->color()">{{ $step->status->label() }}</x-filament::badge>
                            <div class="note" style="margin-top:.25rem">{{ Lang::t('report.counts', ['matched' => $step->matched, 'changed' => $step->changed, 'trashed' => $step->trashed]) }}</div>
                            @if ($step->error)
                                <div class="err">{{ $step->errorMessage() }}</div>
                            @endif
                        @else
                            <span class="sub">—</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
