<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Http;

use Asignua\FilamentGdprRequests\Models\GdprRequestExport;
use Asignua\FilamentGdprRequests\Services\ExportService;
use Asignua\FilamentGdprRequests\Support\Environment;
use Asignua\FilamentGdprRequests\Support\Gates;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Streams an export archive. Four independent locks: a valid signature, the same signed-in user the link was
 * issued to (a leaked link is useless to anyone else), the export gate, and a verified, still-open request.
 * Every refusal is a plain 403/404 without saying which lock held.
 */
final class ExportDownloadController
{
    public function __invoke(Request $request, string $export, ExportService $service): Response
    {
        abort_unless(URL::hasValidSignature($request), 403);

        try {
            Environment::assertSupported();
            $panel = Filament::getPanel((string) $request->query('panel'));
            /** @var Authenticatable|null $user */
            $user = $panel?->auth()->user();
        } catch (Throwable) {
            abort(403);
        }

        abort_if($panel === null, 403);

        abort_unless(
            $user !== null
                && (string) $user->getAuthIdentifier() === (string) $request->query('uid')
                && (!$user instanceof FilamentUser || $user->canAccessPanel($panel))
                && Gates::allows(Gates::EXPORT, $user),
            403,
        );

        $record = GdprRequestExport::query()->with('request')->where('ulid', $export)->first();
        abort_if($record === null, 404);

        try {
            $service->assertDownloadable($record);
        } catch (Throwable) {
            abort(404);
        }

        abort_if($record->disk === null || $record->path === null, 404);
        $path = (string) $record->path;

        $disk = Storage::disk($record->disk);
        abort_unless($disk->exists($path), 404);

        $service->noteDownload($record, $user);

        return $disk->download($path, 'gdpr-export-'.$record->owner()->reference().'.zip', [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
