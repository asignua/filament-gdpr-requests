<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Resources\Concerns;

use Asignua\FilamentGdprRequests\Exceptions\GdprException;
use Asignua\FilamentGdprRequests\Exceptions\PlanBlocked;
use Asignua\FilamentGdprRequests\Support\Lang;
use Closure;
use Filament\Notifications\Notification;
use Throwable;

trait HandlesServiceErrors
{
    /**
     * Runs a service call and turns a deliberate refusal into a notification; anything else is reported, not shown.
     */
    protected function attempt(Closure $callback, ?string $success = null): bool
    {
        try {
            $callback();
        } catch (PlanBlocked $e) {
            Notification::make()->danger()->title($e->getMessage())
                ->body(implode("\n", array_map(static fn ($blocker): string => '• '.$blocker->message(), array_slice($e->blockers, 0, 8))))
                ->persistent()->send();

            return false;
        } catch (GdprException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return false;
        } catch (Throwable $e) {
            report($e);
            Notification::make()->danger()->title(Lang::t('notifications.failed'))->body(Lang::t('notifications.failed_body'))->persistent()->send();

            return false;
        }

        if ($success !== null) {
            Notification::make()->success()->title(Lang::t($success))->send();
        }

        return true;
    }
}
