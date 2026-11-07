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
</style>

<div class="gdpr-scroll">
    <table class="gdpr-table">
        <thead>
            <tr>
                <th>{{ Lang::t('plan.entry') }}</th>
                <th>{{ Lang::t('plan.action') }}</th>
                <th class="num">{{ Lang::t('plan.rows') }}</th>
                <th class="num">{{ Lang::t('plan.trashed') }}</th>
                <th>{{ Lang::t('plan.details') }}</th>
                <th>{{ Lang::t('view.status') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                @php($group = $row['group'])
                @php($step = $row['step'])
                <tr>
                    <td>
                        <div style="font-weight:500">{{ $row['label'] }}</div>
                        @if ($row['table'])
                            <div class="sub">{{ $row['table'] }}</div>
                        @endif
                    </td>
                    <td>
                        <x-filament::badge :color="match ($row['action']) { 'delete' => 'danger', 'anonymize' => 'warning', 'retain' => 'info', default => 'gray' }">
                            {{ Lang::t('action.'.$row['action']) }}
                        </x-filament::badge>
                    </td>
                    <td class="num">{{ $row['matched'] }}</td>
                    <td class="num">{{ $row['trashed'] }}</td>
                    <td class="note">
                        @if ($group && $group->action === 'anonymize')
                            <div>{{ Lang::t('plan.would_change', ['count' => $group->changing]) }}</div>
                            @foreach ($group->rules as $rule)
                                <div class="sub">{{ $rule }}</div>
                            @endforeach
                        @elseif ($group && $group->action === 'retain')
                            <div>{{ Lang::t('plan.basis', ['basis' => $group->basis]) }}</div>
                            <div>{{ Lang::t('plan.until', ['date' => $group->retainedUntil ?? '—']) }}</div>
                        @elseif ($row['action'] === 'delete')
                            <div>{{ Lang::t('plan.delete_note') }}</div>
                        @elseif ($row['action'] === 'none')
                            <div>{{ Lang::t('plan.no_rule') }}</div>
                        @endif
                    </td>
                    <td>
                        @if ($step)
                            <x-filament::badge :color="$step->status->color()">{{ $step->status->label() }}</x-filament::badge>
                            <div class="note" style="margin-top:.25rem">{{ Lang::t('report.counts', ['matched' => $step->matched, 'changed' => $step->changed, 'trashed' => $step->trashed]) }}</div>
                            @if ($step->error)
                                <div class="err">{{ $step->error }}</div>
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
