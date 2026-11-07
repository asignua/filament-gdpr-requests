@php
    use Asignua\FilamentGdprRequests\Enums\RequestStatus;
    use Asignua\FilamentGdprRequests\Support\Lang;

    $plan = $this->getPlan();
    $record = $this->record;
    $running = $record->status === RequestStatus::Erasing;
    $steps = $record->steps()->get()->keyBy('group_key');
@endphp

<x-filament-panels::page>
    <div @if ($running) wire:poll.3s @endif class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">{{ Lang::t('plan.heading') }}</x-slot>
            <x-slot name="description">{{ Lang::t('plan.irreversible') }}</x-slot>

            <div class="flex flex-wrap items-center gap-3 text-sm">
                <x-filament::badge :color="$record->status->color()">{{ $record->status->label() }}</x-filament::badge>
                <span>{{ Lang::t('plan.four_eyes_'.(config('filament-gdpr-requests.four_eyes', true) ? 'on' : 'off')) }}</span>
                @if ($record->erasure_confirmed_at)
                    <span>{{ Lang::t('plan.confirmed_at', ['date' => $record->erasure_confirmed_at->format('Y-m-d H:i')]) }}</span>
                @endif
            </div>
        </x-filament::section>

        @if (! $record->isVerified())
            <x-filament::section>
                <p class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ Lang::t('actions.needs_verification') }}</p>
            </x-filament::section>
        @endif

        @if ($plan === null)
            <x-filament::section>
                <p class="text-sm">{{ Lang::t('plan.unavailable') }}</p>
            </x-filament::section>
        @else
            @if ($plan->isBlocked())
                <x-filament::section icon="heroicon-o-no-symbol" icon-color="danger">
                    <x-slot name="heading">{{ Lang::t('plan.blocked') }}</x-slot>
                    <ul class="list-disc space-y-1 ps-5 text-sm text-danger-600 dark:text-danger-400">
                        @foreach ($plan->allBlockers() as $blocker)
                            <li>{{ $blocker->group ? '['.$blocker->group.'] ' : '' }}{{ $blocker->message() }}</li>
                        @endforeach
                    </ul>
                </x-filament::section>
            @endif

            <x-filament::section>
                <x-slot name="heading">{{ Lang::t('plan.groups') }}</x-slot>
                <div class="overflow-x-auto">
                    <table class="w-full text-start text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-white/10 dark:text-gray-400">
                                <th class="py-2 pe-4 text-start">{{ Lang::t('plan.entry') }}</th>
                                <th class="py-2 pe-4 text-start">{{ Lang::t('plan.action') }}</th>
                                <th class="py-2 pe-4 text-end">{{ Lang::t('plan.rows') }}</th>
                                <th class="py-2 pe-4 text-end">{{ Lang::t('plan.trashed') }}</th>
                                <th class="py-2 pe-4 text-start">{{ Lang::t('plan.details') }}</th>
                                <th class="py-2 text-start">{{ Lang::t('view.status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($plan->groups as $group)
                                @php($step = $steps->get($group->key()))
                                <tr class="border-b border-gray-100 align-top dark:border-white/5">
                                    <td class="py-2 pe-4">
                                        <div class="font-medium">{{ $group->label() }}</div>
                                        <div class="font-mono text-xs text-gray-500 dark:text-gray-400">{{ $group->table() }}</div>
                                    </td>
                                    <td class="py-2 pe-4">
                                        <x-filament::badge :color="match ($group->action) { 'delete' => 'danger', 'anonymize' => 'warning', 'retain' => 'info', default => 'gray' }">
                                            {{ Lang::t('action.'.$group->action) }}
                                        </x-filament::badge>
                                    </td>
                                    <td class="py-2 pe-4 text-end tabular-nums">{{ $group->matched }}</td>
                                    <td class="py-2 pe-4 text-end tabular-nums">{{ $group->trashed }}</td>
                                    <td class="py-2 pe-4 text-xs">
                                        @if ($group->action === 'anonymize')
                                            <div>{{ Lang::t('plan.would_change', ['count' => $group->changing]) }}</div>
                                            @foreach ($group->rules as $rule)
                                                <div class="font-mono text-gray-500 dark:text-gray-400">{{ $rule }}</div>
                                            @endforeach
                                        @elseif ($group->action === 'retain')
                                            <div>{{ Lang::t('plan.basis', ['basis' => $group->basis]) }}</div>
                                            <div>{{ Lang::t('plan.until', ['date' => $group->retainedUntil ?? '—']) }}</div>
                                        @elseif ($group->action === 'delete')
                                            <div>{{ Lang::t('plan.delete_note') }}</div>
                                        @else
                                            <div>{{ Lang::t('plan.no_rule') }}</div>
                                        @endif
                                    </td>
                                    <td class="py-2">
                                        @if ($step)
                                            <x-filament::badge :color="$step->status->color()">{{ $step->status->label() }}</x-filament::badge>
                                            @if ($step->status->value === 'done' || $step->status->value === 'retained')
                                                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ Lang::t('report.counts', ['matched' => $step->matched, 'changed' => $step->changed, 'trashed' => $step->trashed]) }}</div>
                                            @endif
                                            @if ($step->error)
                                                <div class="mt-1 text-xs text-danger-600 dark:text-danger-400">{{ $step->error }}</div>
                                            @endif
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif

        @if ($record->status === RequestStatus::Partial)
            <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger">
                <x-slot name="heading">{{ Lang::t('plan.partial') }}</x-slot>
                <p class="text-sm">{{ Lang::t('plan.partial_help') }}</p>
            </x-filament::section>
        @endif

        @if ($record->status === RequestStatus::Fulfilled)
            <x-filament::section icon="heroicon-o-check-circle" icon-color="success">
                <x-slot name="heading">{{ Lang::t('plan.done') }}</x-slot>
                <p class="text-sm">{{ Lang::t('plan.done_help') }}</p>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
