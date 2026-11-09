<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Resources\GdprRequestResource\Pages;

use Asignua\FilamentGdprRequests\Enums\RequestStatus;
use Asignua\FilamentGdprRequests\Erasure\ErasurePlan;
use Asignua\FilamentGdprRequests\Exceptions\GdprException;
use Asignua\FilamentGdprRequests\Models\GdprRequest;
use Asignua\FilamentGdprRequests\Resources\Concerns\HandlesServiceErrors;
use Asignua\FilamentGdprRequests\Resources\GdprRequestResource;
use Asignua\FilamentGdprRequests\Services\ErasureService;
use Asignua\FilamentGdprRequests\Support\Actor;
use Asignua\FilamentGdprRequests\Support\Gates;
use Asignua\FilamentGdprRequests\Support\Lang;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Throwable;

/**
 * The erasure preview and the two separate irreversible steps (confirm, run). Opening the page changes nothing.
 */
class ErasurePlanPage extends Page
{
    use HandlesServiceErrors;
    use InteractsWithRecord;

    protected static string $resource = GdprRequestResource::class;

    protected string $view = 'filament-gdpr-requests::pages.erasure-plan';

    /** The plan of this Livewire request: the view and the actions ask for it several times. Never sent to the browser. */
    private ?ErasurePlan $plan = null;

    private bool $planned = false;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(Gates::allows(Gates::ERASE, auth()->user()) && $this->gdprRequest()->type->allowsErasure(), 403);
    }

    private function gdprRequest(): GdprRequest
    {
        $record = $this->record;

        if (!$record instanceof GdprRequest) {
            abort(404);
        }

        return $record;
    }

    public function getTitle(): string
    {
        return Lang::t('plan.title', ['reference' => $this->gdprRequest()->reference()]);
    }

    public function getBreadcrumb(): string
    {
        return Lang::t('plan.breadcrumb');
    }

    /**
     * The preview is computed once per request (it is read-only), so it is never stale and never computed twice for one
     * render. An action that changes the request forgets it.
     */
    public function getPlan(): ?ErasurePlan
    {
        if (!$this->planned) {
            $this->plan = $this->computePlan();
            $this->planned = true;
        }

        return $this->plan;
    }

    private function computePlan(): ?ErasurePlan
    {
        $this->gdprRequest()->refresh();

        if (!$this->gdprRequest()->hasSubject() || !$this->gdprRequest()->status->isOpen()) {
            return null;
        }

        try {
            return app(ErasureService::class)->plan($this->gdprRequest());
        } catch (GdprException) {
            return null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function forgetPlan(): void
    {
        $this->plan = null;
        $this->planned = false;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')->label(Lang::t('plan.back'))->color('gray')->icon('heroicon-o-arrow-left')
                ->url(fn (): string => GdprRequestResource::getUrl('view', ['record' => $this->gdprRequest()])),
            $this->confirmAction(),
            $this->runAction(),
        ];
    }

    private function confirmAction(): Action
    {
        return Action::make('confirm')->label(Lang::t('plan.confirm'))->icon('heroicon-o-check-badge')->color('warning')
            ->visible(fn (): bool => in_array($this->gdprRequest()->status, [RequestStatus::InProgress, RequestStatus::ErasureConfirmed, RequestStatus::Partial], true) || $this->gdprRequest()->isStaleRun())
            ->disabled(fn (): bool => !$this->gdprRequest()->isVerified() || ($this->getPlan()?->isBlocked() ?? true))
            ->modalHeading(Lang::t('plan.confirm'))
            ->modalDescription(Lang::t('plan.confirm_help'))
            ->modalSubmitActionLabel(Lang::t('plan.confirm_submit'))
            ->schema(fn (): array => [$this->typedReference()])
            ->action(function (): void {
                $this->forgetPlan();
                $confirmed = null;
                $this->attempt(function () use (&$confirmed): void {
                    $confirmed = app(ErasureService::class)->confirm($this->gdprRequest(), Actor::require());
                }, 'notifications.confirmed');
                $this->gdprRequest()->refresh();

                // The plan the confirmation was made on is the preview of this moment: no second scan for the render.
                if ($confirmed instanceof ErasurePlan && $this->gdprRequest()->status->isOpen()) {
                    $this->plan = $confirmed;
                    $this->planned = true;
                }
            });
    }

    private function runAction(): Action
    {
        return Action::make('run')->label(fn (): string => $this->gdprRequest()->status === RequestStatus::ErasureConfirmed ? Lang::t('plan.run') : Lang::t('plan.resume'))
            ->icon('heroicon-o-play')->color('danger')
            ->visible(fn (): bool => in_array($this->gdprRequest()->status, [RequestStatus::ErasureConfirmed, RequestStatus::Partial], true) || $this->gdprRequest()->isStaleRun())
            ->modalHeading(Lang::t('plan.run'))
            ->modalDescription(Lang::t('plan.run_help'))
            ->modalSubmitActionLabel(Lang::t('plan.run_submit'))
            ->schema(fn (): array => [$this->typedReference()])
            ->action(function (): void {
                $this->attempt(fn () => app(ErasureService::class)->start($this->gdprRequest(), Actor::require()), 'notifications.started');
                $this->gdprRequest()->refresh();
                $this->forgetPlan();
            });
    }

    /**
     * The person must type the request reference: a click alone never starts anything irreversible.
     */
    private function typedReference(): TextInput
    {
        return TextInput::make('reference')
            ->label(Lang::t('plan.type_reference', ['reference' => $this->gdprRequest()->reference()]))
            ->required()
            ->rules([fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                if (strtoupper(trim((string) $value)) !== $this->gdprRequest()->reference()) {
                    $fail(Lang::t('plan.reference_mismatch'));
                }
            }]);
    }
}
