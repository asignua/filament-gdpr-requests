<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\DataMap;

use Asignua\FilamentGdprRequests\Erasure\Blocker;
use Asignua\FilamentGdprRequests\Models\GdprRequest;
use Asignua\FilamentGdprRequests\Support\Pseudonym;
use Asignua\FilamentGdprRequests\Support\Sql;
use Illuminate\Database\QueryException;

/**
 * Who a request is about, read from the linked record. The record is the only authority: the address typed on the
 * request is a claim and is never used to find rows. A record that cannot be read, an address that differs from it
 * and an address nobody can vouch for all become blockers, for a person to resolve.
 *
 * Once the erasure has changed the record (the root group is done), its address may already be anonymized. From then on
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

        // The record no longer speaks for itself, but an address that has since become someone else's still finds their rows.
        if ($this->rootChanged($request)) {
            return new ResolvedSubject(new Subject($root, $request->subject_id, $claimed), $this->sharedAddress($root, $request->subject_id, $claimed));
        }

        $row = $this->rootRow($root, $request->subject_id);

        if ($row === null) {
            return new ResolvedSubject(new Subject($root, $request->subject_id), [
                new Blocker('subject_row_missing', ['model' => class_basename($model), 'id' => $request->subject_id]),
            ]);
        }

        $column = $root->getEmailColumn();
        $email = $column === null ? null : Pseudonym::normalizeEmail(is_scalar($row[$column] ?? null) ? (string) $row[$column] : null);
        $blockers = $this->sharedAddress($root, $request->subject_id, $email);

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
     * An address finds rows only while it stands for one person: with two records behind it, each one's erasure would take
     * the other's rows and each one's export would hold them. Soft-deleted records count, they still own their rows.
     *
     * @return list<Blocker>
     */
    private function sharedAddress(PersonalData $root, string $id, ?string $email): array
    {
        $column = $root->getEmailColumn();

        if ($column === null || $email === null) {
            return [];
        }

        $query = $root->model::query()->withoutGlobalScopes();
        $table = $query->getModel()->getTable();
        $key = $query->getModel()->getKeyName();
        Sql::whereNormalizedEquals($query, $table.'.'.$column, $email);

        try {
            $rows = $query->toBase()->select([$table.'.'.$key, $table.'.'.$column])->get();
        } catch (QueryException) {
            return []; // A missing table has its own blocker in the preview.
        }

        $others = $rows->filter(static fn (object $row): bool => (string) $row->{$key} !== $id && Pseudonym::normalizeEmail((string) $row->{$column}) === $email)->count();

        return $others === 0 ? [] : [new Blocker('subject_email_shared', ['rows' => $others + 1])];
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

    /**
     * This request already changed the record. A new confirmation carries that over: it starts the steps again, but the
     * record does not come back.
     */
    public function rootChanged(GdprRequest $request): bool
    {
        if (($request->erasure_plan['root_changed'] ?? false) === true) {
            return true;
        }

        $fingerprint = $request->erasure_plan['fingerprint'] ?? null;
        $root = $request->subject_model === null ? null : $this->registry->rootFor($request->subject_model);

        if (!is_string($fingerprint) || $root === null) {
            return false;
        }

        return $request->steps()->where('group_key', $root->group)->first()?->rowsDone($fingerprint) ?? false;
    }
}
