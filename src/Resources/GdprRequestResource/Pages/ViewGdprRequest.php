<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Resources\GdprRequestResource\Pages;

use Asignua\FilamentGdprRequests\DataMap\PersonalDataRegistry;
use Asignua\FilamentGdprRequests\DataMap\SubjectResolver;
use Asignua\FilamentGdprRequests\Enums\RequestStatus;
use Asignua\FilamentGdprRequests\Models\GdprRequest;
use Asignua\FilamentGdprRequests\Resources\Concerns\HandlesServiceErrors;
use Asignua\FilamentGdprRequests\Resources\GdprRequestResource;
use Asignua\FilamentGdprRequests\Services\ExportService;
use Asignua\FilamentGdprRequests\Services\RequestService;
use Asignua\FilamentGdprRequests\Support\Actor;
use Asignua\FilamentGdprRequests\Support\Deadline;
use Asignua\FilamentGdprRequests\Support\Gates;
use Asignua\FilamentGdprRequests\Support\Lang;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;

/**
 * @property GdprRequest $record
 */
class ViewGdprRequest extends ViewRecord
{
    use HandlesServiceErrors;

    protected static string $resource = GdprRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->linkSubjectAction(),
            $this->acceptRecordEmailAction(),
            $this->verifyAction(),
            $this->exportAction(),
            $this->downloadAction(),
            Action::make('erasure')->label(Lang::t('actions.erasure'))->icon('heroicon-o-trash')->color('danger')
                ->visible(fn (): bool => $this->record->type->allowsErasure() && $this->record->status->isOpen() && Gates::allows(Gates::ERASE, auth()->user()))
                ->url(fn (): string => GdprRequestResource::getUrl('erasure', ['record' => $this->record])),
            ActionGroup::make([
                $this->assignAction(),
                $this->extendAction(),
                $this->noteAction(),
                $this->completeAction(),
                $this->refuseAction(),
                $this->closeAction(),
            ])->label(Lang::t('actions.more'))->icon('heroicon-m-ellipsis-vertical')->button(),
        ];
    }

    private function mayManage(): bool
    {
        return Gates::allows(Gates::MANAGE, auth()->user());
    }

    private function refreshRecord(): void
    {
        $this->record->refresh();
        $this->refreshFormData(['status']);
    }

    private function linkSubjectAction(): Action
    {
        $roots = collect(app(PersonalDataRegistry::class)->subjectRoots())->mapWithKeys(static fn ($entry): array => [$entry->model => $entry->getLabel()])->all();

        return Action::make('link')->label(Lang::t('actions.link'))->icon('heroicon-o-link')->color('gray')
            ->visible(fn (): bool => $this->mayManage() && in_array($this->record->status, [RequestStatus::New, RequestStatus::InProgress], true))
            ->modalDescription(Lang::t('actions.link_help'))
            ->schema([
                Select::make('model')->label(Lang::t('form.subject_model'))->options($roots)->required()->native(false),
                TextInput::make('id')->label(Lang::t('form.subject_id'))->maxLength(64),
                TextInput::make('email')->label(Lang::t('form.email'))->email()->helperText(Lang::t('actions.link_email_help'))
                    ->default(fn (): ?string => $this->record->subject_email),
            ])
            ->action(function (array $data): void {
                $service = app(RequestService::class);

                $this->attempt(function () use ($data, $service): void {
                    $id = $data['id'] ?? null;

                    if (($id === null || $id === '') && !empty($data['email'])) {
                        $id = $service->findSubjectByEmail((string) $data['model'], (string) $data['email']);
                    }

                    if ($id === null || $id === '') {
                        throw \Asignua\FilamentGdprRequests\Exceptions\GdprException::translated('subject_not_found');
                    }

                    $service->linkSubject($this->record, (string) $data['model'], $id, Actor::require());
                }, 'notifications.linked');
                $this->refreshRecord();
            });
    }

    private function emailDiffers(): bool
    {
        if (!$this->mayManage() || !in_array($this->record->status, [RequestStatus::New, RequestStatus::InProgress, RequestStatus::ErasureConfirmed], true)) {
            return false;
        }

        return app(SubjectResolver::class)->resolve($this->record)?->hasBlocker('subject_email_mismatch') ?? false;
    }

    private function acceptRecordEmailAction(): Action
    {
        return Action::make('acceptRecordEmail')->label(Lang::t('actions.accept_record_email'))->icon('heroicon-o-envelope')->color('warning')
            ->visible(fn (): bool => $this->emailDiffers())
            ->requiresConfirmation()
            ->modalDescription(Lang::t('actions.accept_record_email_help'))
            ->action(function (): void {
                $this->attempt(fn () => app(RequestService::class)->acceptRecordEmail($this->record, Actor::require()), 'notifications.record_email_accepted');
                $this->refreshRecord();
            });
    }

    private function verifyAction(): Action
    {
        return Action::make('verify')->label(Lang::t('actions.verify'))->icon('heroicon-o-identification')->color('warning')
            ->visible(fn (): bool => $this->mayManage() && $this->record->status->isOpen() && !$this->record->isVerified())
            ->modalHeading(Lang::t('actions.verify'))
            ->modalDescription(Lang::t('actions.verify_help'))
            ->schema([Textarea::make('note')->label(Lang::t('actions.verify_note'))->helperText(Lang::t('actions.verify_note_help'))->required()->rows(3)])
            ->action(function (array $data): void {
                $this->attempt(fn () => app(RequestService::class)->verifyIdentity($this->record, Actor::require(), (string) $data['note']), 'notifications.verified');
                $this->refreshRecord();
            });
    }

    private function exportAction(): Action
    {
        return Action::make('export')->label(Lang::t('actions.export'))->icon('heroicon-o-archive-box-arrow-down')
            ->visible(fn (): bool => $this->record->type->allowsExport() && $this->record->status->isOpen() && Gates::allows(Gates::EXPORT, auth()->user()))
            ->disabled(fn (): bool => !$this->record->isVerified())
            ->tooltip(fn (): ?string => $this->record->isVerified() ? null : Lang::t('actions.needs_verification'))
            ->requiresConfirmation()
            ->modalDescription(Lang::t('actions.export_help'))
            ->action(function (): void {
                $this->attempt(fn () => app(ExportService::class)->request($this->record, Actor::require()), 'notifications.export_requested');
                $this->refreshRecord();
            });
    }

    private function downloadAction(): Action
    {
        return Action::make('download')->label(Lang::t('actions.download'))->icon('heroicon-o-arrow-down-tray')->color('gray')
            ->visible(fn (): bool => Gates::allows(Gates::EXPORT, auth()->user())
                && $this->record->status->isOpen()
                && $this->record->exports()->where('status', 'ready')->whereNull('removed_at')->where('expires_at', '>', now())->exists())
            ->action(function () {
                $export = $this->record->exports()->where('status', 'ready')->whereNull('removed_at')->where('expires_at', '>', now())->first();
                $url = null;

                if ($export !== null) {
                    $this->attempt(function () use ($export, &$url): void {
                        $url = app(ExportService::class)->link($export, Actor::require(), Filament::getCurrentPanel()?->getId() ?? 'admin');
                    });
                }

                return $url === null ? null : redirect()->to($url);
            });
    }

    private function assignAction(): Action
    {
        return Action::make('assign')->label(Lang::t('actions.assign'))->icon('heroicon-o-user')
            ->visible(fn (): bool => $this->mayManage() && $this->record->status->isOpen())
            ->schema([Select::make('assignee')->label(Lang::t('form.assignee'))->options(fn (): array => GdprRequestResource::userOptions())->searchable()->native(false)
                ->default(fn (): ?string => $this->record->assignee_id)])
            ->action(function (array $data): void {
                $this->attempt(fn () => app(RequestService::class)->assign($this->record, $data['assignee'] ?: null, Actor::require()), 'notifications.assigned');
                $this->refreshRecord();
            });
    }

    private function extendAction(): Action
    {
        return Action::make('extend')->label(Lang::t('actions.extend'))->icon('heroicon-o-calendar-days')
            ->visible(fn (): bool => $this->mayManage() && $this->record->canExtend())
            ->modalDescription(fn (): string => Lang::t('actions.extend_help', ['max' => Deadline::maxExtraMonths()]))
            ->schema([
                Select::make('months')->label(Lang::t('actions.extend_months'))->native(false)->required()->default(Deadline::maxExtraMonths())
                    ->options(collect(range(1, max(1, Deadline::maxExtraMonths())))->mapWithKeys(static fn (int $n): array => [$n => (string) $n])->all()),
                Textarea::make('reason')->label(Lang::t('actions.reason'))->required()->rows(3),
            ])
            ->action(function (array $data): void {
                $this->attempt(fn () => app(RequestService::class)->extend($this->record, (int) $data['months'], (string) $data['reason'], Actor::require()), 'notifications.extended');
                $this->refreshRecord();
            });
    }

    private function noteAction(): Action
    {
        return Action::make('note')->label(Lang::t('actions.note'))->icon('heroicon-o-pencil-square')
            ->visible(fn (): bool => $this->mayManage())
            ->schema([Textarea::make('note')->label(Lang::t('actions.note'))->required()->rows(3)])
            ->action(function (array $data): void {
                $this->attempt(fn () => app(RequestService::class)->addNote($this->record, (string) $data['note'], Actor::require()), 'notifications.noted');
                $this->refreshRecord();
            });
    }

    private function completeAction(): Action
    {
        return Action::make('complete')->label(Lang::t('actions.complete'))->icon('heroicon-o-check-circle')->color('success')
            ->visible(fn (): bool => $this->mayManage() && !$this->record->type->allowsErasure() && $this->record->status === RequestStatus::InProgress)
            ->requiresConfirmation()
            ->action(function (): void {
                $this->attempt(fn () => app(RequestService::class)->complete($this->record, Actor::require()), 'notifications.completed');
                $this->refreshRecord();
            });
    }

    private function refuseAction(): Action
    {
        return Action::make('refuse')->label(Lang::t('actions.refuse'))->icon('heroicon-o-x-circle')->color('danger')
            ->visible(fn (): bool => $this->mayManage() && in_array($this->record->status, [RequestStatus::New, RequestStatus::InProgress, RequestStatus::ErasureConfirmed], true))
            ->modalDescription(Lang::t('actions.refuse_help'))
            ->schema([Textarea::make('reason')->label(Lang::t('actions.reason'))->required()->rows(3)])
            ->action(function (array $data): void {
                $this->attempt(fn () => app(RequestService::class)->refuse($this->record, (string) $data['reason'], Actor::require()), 'notifications.refused');
                $this->refreshRecord();
            });
    }

    private function closeAction(): Action
    {
        return Action::make('close')->label(Lang::t('actions.close'))->icon('heroicon-o-lock-closed')
            ->visible(fn (): bool => $this->mayManage() && in_array($this->record->status, [RequestStatus::New, RequestStatus::InProgress, RequestStatus::Fulfilled, RequestStatus::Refused], true))
            ->requiresConfirmation()
            ->modalDescription(Lang::t('actions.close_help'))
            ->action(function (): void {
                $this->attempt(fn () => app(RequestService::class)->close($this->record, Actor::require()), 'notifications.closed');
                $this->refreshRecord();
            });
    }
}
