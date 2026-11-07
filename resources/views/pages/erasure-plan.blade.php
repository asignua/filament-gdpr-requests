@php
    use Asignua\FilamentGdprRequests\Enums\RequestStatus;
    use Asignua\FilamentGdprRequests\Support\Lang;

    $plan = $this->getPlan();
    $record = $this->record;
    $running = $record->status === RequestStatus::Erasing;
    $steps = $record->steps()->get()->keyBy('group_key');
    $person = $record->subject_name ?? Lang::t('plan.subject_unnamed');
    $address = $record->subject_email ?? $plan?->subjectEmail;
@endphp

<x-filament-panels::page>
    <div @if ($running) wire:poll.3s @endif class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">{{ Lang::t('plan.heading') }}</x-slot>
            <x-slot name="description">{{ Lang::t('plan.irreversible') }}</x-slot>

            @if ($record->hasSubject())
                <div style="margin-bottom:.75rem">
                    <p style="font-size:1rem;font-weight:600">{{ Lang::t('plan.subject', ['name' => $person, 'model' => class_basename((string) $record->subject_model), 'id' => (string) $record->subject_id]) }}</p>
                    @if ($address)
                        <p class="text-sm" style="overflow-wrap:anywhere">{{ $address }}</p>
                    @endif
                </div>
            @endif

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

        @if ($plan === null && $steps->isEmpty())
            <x-filament::section>
                <p class="text-sm">{{ Lang::t('plan.unavailable') }}</p>
            </x-filament::section>
        @elseif ($plan !== null)
            @if ($plan->isBlocked())
                <x-filament::section icon="heroicon-o-no-symbol" icon-color="danger">
                    <x-slot name="heading">{{ Lang::t('plan.blocked') }}</x-slot>
                    <ul class="list-disc space-y-1 ps-5 text-sm text-danger-600 dark:text-danger-400">
                        @foreach ($plan->allBlockers() as $blocker)
                            <li>{{ $blocker->group ? '['.($plan->group($blocker->group)?->label() ?? class_basename($blocker->group)).'] ' : '' }}{{ $blocker->message() }}</li>
                        @endforeach
                    </ul>
                </x-filament::section>
            @endif

            <x-filament::section>
                <x-slot name="heading">{{ Lang::t('plan.groups') }}</x-slot>
                @include('filament-gdpr-requests::pages.partials.plan-table', ['groups' => $plan->groups, 'steps' => $steps])
            </x-filament::section>
        @endif

        @if ($plan === null && $steps->isNotEmpty())
            <x-filament::section>
                <x-slot name="heading">{{ Lang::t('view.steps') }}</x-slot>
                @include('filament-gdpr-requests::pages.partials.plan-table', ['groups' => [], 'steps' => $steps])
            </x-filament::section>
        @endif

        @if ($record->isStaleRun())
            <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="warning">
                <x-slot name="heading">{{ Lang::t('plan.stale') }}</x-slot>
                <p class="text-sm">{{ Lang::t('plan.stale_help', ['minutes' => $record::staleRunMinutes()]) }}</p>
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
