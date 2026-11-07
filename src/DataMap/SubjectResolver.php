<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\DataMap;

use Asignua\FilamentGdprRequests\Erasure\Blocker;
use Asignua\FilamentGdprRequests\Models\GdprRequest;
use Asignua\FilamentGdprRequests\Support\Pseudonym;
use Illuminate\Database\QueryException;

/**
 * Who a request is about, read from the linked record. The record is the only authority: the address typed on the
 * request is a claim and is never used to find rows. A record that cannot be read, an address that differs from it
 * and an address nobody can vouch for all become blockers, for a person to resolve.
 *
 * Once this run has changed the record (the root group is done), its address may already be anonymized. From then on
 * the address taken at confirmation is the one in force, and the checks against the record no longer apply.
 */
final class SubjectResolver
{
    public function __construct(private readonly PersonalDataRegistry $registry) {}

    public function resolve(GdprRequest $request): ?ResolvedSubject
    {
        $model = $request->subject_model;
        $root = $model === null ? null : $this->registry->rootFor($model);

        if ($model === null || $root === null || $request->subject_id === null) {
            return null;
        }

        $claimed = Pseudonym::normalizeEmail($request->subject_email);

        if ($this->rootAlreadyErased($request, $root)) {
            return new ResolvedSubject(new Subject($root, $request->subject_id, $claimed));
        }

        $row = $this->rootRow($root, $request->subject_id);

        if ($row === null) {
            return new ResolvedSubject(new Subject($root, $request->subject_id), [
                new Blocker('subject_row_missing', ['model' => class_basename($model), 'id' => $request->subject_id]),
            ]);
        }

        $column = $root->getEmailColumn();
        $email = $column === null ? null : Pseudonym::normalizeEmail(is_scalar($row[$column] ?? null) ? (string) $row[$column] : null);
        $blockers = [];

        if ($claimed !== null && $email !== null && $claimed !== $email) {
            $blockers[] = new Blocker('subject_email_mismatch');
        }

        if ($email === null) {
            foreach ($this->registry->forSubject($model) as $entry) {
                if ($entry->getMatchKind() === PersonalData::MATCH_EMAIL) {
                    $blockers[] = new Blocker('subject_email_unknown', ['entry' => $entry->getLabel()], $entry->group);
                }
            }
        }

        return new ResolvedSubject(new Subject($root, $request->subject_id, $email), $blockers);
    }

    /**
     * Read through the same exact comparison as every other group: a key that does not match here matches nothing.
     *
     * @return array<string, mixed>|null
     */
    private function rootRow(PersonalData $root, string $id): ?array
    {
        try {
            foreach (Selection::forSubject($root, new Subject($root, $id))->rows(1) as $row) {
                return $row;
            }
        } catch (QueryException) {
            // A missing table has its own blocker in the preview.
        }

        return null;
    }

    private function rootAlreadyErased(GdprRequest $request, PersonalData $root): bool
    {
        $fingerprint = $request->erasure_plan['fingerprint'] ?? null;

        if (!is_string($fingerprint)) {
            return false;
        }

        return $request->steps()->where('group_key', $root->group)->first()?->rowsDone($fingerprint) ?? false;
    }
}
