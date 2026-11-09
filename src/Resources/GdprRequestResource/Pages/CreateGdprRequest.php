<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Resources\GdprRequestResource\Pages;

use Asignua\FilamentGdprRequests\Enums\RequestChannel;
use Asignua\FilamentGdprRequests\Enums\RequestType;
use Asignua\FilamentGdprRequests\Exceptions\GdprException;
use Asignua\FilamentGdprRequests\Resources\Concerns\WarnsAboutSetup;
use Asignua\FilamentGdprRequests\Resources\GdprRequestResource;
use Asignua\FilamentGdprRequests\Services\RequestService;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateGdprRequest extends CreateRecord
{
    use WarnsAboutSetup;

    protected static string $resource = GdprRequestResource::class;

    /**
     * The request and its subject link go through the service: it checks the map, the gate and stamps the deadline.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $service = app(RequestService::class);

        try {
            $model = $data['subject_model'] ?? null;
            $id = $data['subject_id'] ?? null;

            // A subject picked without a key is looked up by the e-mail on the request: exact, never LIKE.
            if (is_string($model) && $model !== '' && ($id === null || $id === '') && !empty($data['email'])) {
                $id = $service->findSubjectByEmail($model, (string) $data['email']);
            }

            $request = $service->create([
                'type' => RequestType::from((string) $data['type']),
                'channel' => RequestChannel::from((string) $data['channel']),
                'name' => $data['name'] ?? null,
                'email' => $data['email'] ?? null,
                'message' => $data['message'] ?? null,
                'received_at' => CarbonImmutable::parse((string) $data['received_at']),
                'assignee_id' => $data['assignee_id'] ?? null,
                'subject_model' => $id !== null && $id !== '' ? $model : null,
                'subject_id' => $id !== null && $id !== '' ? $id : null,
            ]);
        } catch (GdprException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw new Halt;
        }

        return $request;
    }
}
