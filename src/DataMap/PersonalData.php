<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\DataMap;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * One entry of the data map: how to find the rows of a data subject in a model, what to export and what to do with
 * them on erasure. The map is the ONLY way the plugin finds personal data: there is no search, no LIKE, no guessing.
 *
 *     PersonalData::for(User::class)
 *         ->label('Account')
 *         ->subject(email: 'email')
 *         ->export(['id', 'name', 'email', 'created_at'])
 *         ->erase(Erase::anonymize([
 *             'name' => Rule::fixed('Erased user'),
 *             'email' => Rule::uniqueToken('erased-', '@invalid.example'),
 *         ]));
 *
 *     PersonalData::for(Order::class)
 *         ->of(User::class)
 *         ->matchedBy('user_id')
 *         ->export(['id', 'number', 'total', 'shipping_address'])
 *         ->erase(Erase::retain('Tax law: invoices', days: 3650, from: 'created_at'));
 */
final class PersonalData
{
    public const string MATCH_SUBJECT = 'subject';

    public const string MATCH_COLUMN = 'column';

    public const string MATCH_EMAIL = 'email';

    public const string MATCH_CLOSURE = 'closure';

    /** @var class-string<Model> */
    public readonly string $model;

    public readonly string $group;

    private ?string $label = null;

    /** @var class-string<Model>|null */
    private ?string $of = null;

    private ?string $matchKind = null;

    private ?string $matchColumn = null;

    private ?string $emailColumn = null;

    private ?Closure $matchClosure = null;

    /** @var list<string> */
    private array $exportColumns = [];

    private ?Closure $exportUsing = null;

    private ?Closure $files = null;

    private ?Erase $erase = null;

    private ?Retention $retention = null;

    /**
     * @param class-string<Model> $model
     */
    private function __construct(string $model, ?string $group)
    {
        if (!is_subclass_of($model, Model::class)) {
            throw new InvalidArgumentException("[{$model}] is not an Eloquent model.");
        }

        $this->model = $model;
        $this->group = $group ?? $model;
    }

    /**
     * Declares (and registers) a map entry. Call it from a service provider's boot().
     *
     * @param class-string<Model> $model
     * @param string|null         $group only needed when one model appears twice in the map
     */
    public static function for(string $model, ?string $group = null): self
    {
        $entry = new self($model, $group);
        app(PersonalDataRegistry::class)->add($entry);

        return $entry;
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * This model IS the data subject (a user, a customer): the subject is one row, found by its primary key.
     * $email names the column with the e-mail address, used to link a request that arrived with an address
     * only (exact match after trim + lowercase, never LIKE).
     */
    public function subject(?string $email = null): self
    {
        $this->matchKind = self::MATCH_SUBJECT;
        $this->emailColumn = $email;

        return $this;
    }

    /**
     * The subject model this entry belongs to (required for every entry that is not the subject itself).
     *
     * @param class-string<Model> $subject
     */
    public function of(string $subject): self
    {
        $this->of = $subject;

        return $this;
    }

    /**
     * Rows whose $column equals the subject's key (e.g. orders.user_id).
     */
    public function matchedBy(string $column): self
    {
        $this->matchKind = self::MATCH_COLUMN;
        $this->matchColumn = $column;

        return $this;
    }

    /**
     * Rows whose $column equals the subject's e-mail (trim + lowercase on both sides, exact).
     */
    public function matchedByEmail(string $column): self
    {
        $this->matchKind = self::MATCH_EMAIL;
        $this->matchColumn = $column;

        return $this;
    }

    /**
     * Your own query, for rows reached through another table: fn (Builder $query, Subject $subject): void.
     * The plugin cannot verify such a match row by row, so keep it exact. Global scopes are already removed.
     *
     * @param Closure(Builder<Model>, Subject): void $callback
     */
    public function matchedUsing(Closure $callback): self
    {
        $this->matchKind = self::MATCH_CLOSURE;
        $this->matchClosure = $callback;

        return $this;
    }

    /**
     * The columns that go into the export. Only these. Third parties' data inside these columns is your
     * responsibility; the plugin does not look at the content.
     *
     * @param list<string> $columns
     */
    public function export(array $columns): self
    {
        $this->exportColumns = $columns;

        return $this;
    }

    /**
     * Extra derived values for the export: fn (Model $row): array<string, mixed>, merged after the columns.
     *
     * @param Closure(Model): array<string, mixed> $callback
     */
    public function exportUsing(Closure $callback): self
    {
        $this->exportUsing = $callback;

        return $this;
    }

    /**
     * Files that belong to a row (media, uploads): fn (Model $row): iterable of ['disk' => ..., 'path' => ...].
     * They go into the export archive and, when the erase strategy says `files: true`, are removed on erasure.
     *
     * @param Closure(Model): iterable<array{disk: string, path: string}> $callback
     */
    public function files(Closure $callback): self
    {
        $this->files = $callback;

        return $this;
    }

    public function erase(Erase $strategy): self
    {
        $this->erase = $strategy;

        return $this;
    }

    /**
     * Housekeeping for `gdpr:retention`: rows older than the policy are anonymized or deleted, only with --apply.
     */
    public function retention(Retention $policy): self
    {
        $this->retention = $policy;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label ?? class_basename($this->model);
    }

    public function isSubject(): bool
    {
        return $this->matchKind === self::MATCH_SUBJECT;
    }

    /**
     * @return class-string<Model>|null the subject model: itself for a root entry, else the declared `of`
     */
    public function subjectModel(): ?string
    {
        return $this->isSubject() ? $this->model : $this->of;
    }

    public function getMatchKind(): ?string
    {
        return $this->matchKind;
    }

    public function getMatchColumn(): ?string
    {
        return $this->matchColumn;
    }

    public function getEmailColumn(): ?string
    {
        return $this->emailColumn;
    }

    public function getMatchClosure(): ?Closure
    {
        return $this->matchClosure;
    }

    /**
     * @return list<string>
     */
    public function getExportColumns(): array
    {
        return $this->exportColumns;
    }

    public function getExportUsing(): ?Closure
    {
        return $this->exportUsing;
    }

    public function getFiles(): ?Closure
    {
        return $this->files;
    }

    public function getErase(): ?Erase
    {
        return $this->erase;
    }

    public function getRetention(): ?Retention
    {
        return $this->retention;
    }

    public function hasExport(): bool
    {
        return $this->exportColumns !== [] || $this->exportUsing !== null || $this->files !== null;
    }

    /**
     * Structural problems, as codes the planner turns into blockers. An entry that cannot say how it finds a
     * subject's rows is never run.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        if ($this->matchKind === null) {
            $problems[] = 'no_matcher';
        }

        if (!$this->isSubject() && $this->of === null) {
            $problems[] = 'no_subject_model';
        }

        if (($this->matchKind === self::MATCH_COLUMN || $this->matchKind === self::MATCH_EMAIL) && ($this->matchColumn === null || $this->matchColumn === '')) {
            $problems[] = 'no_matcher';
        }

        return $problems;
    }

    /**
     * Stable text of the entry's rules; part of the plan fingerprint, so a changed map invalidates a confirmation.
     */
    public function definitionSignature(): string
    {
        $erase = $this->erase;
        $erasePart = match (true) {
            $erase instanceof Anonymize => 'anonymize:'.json_encode(array_map(static fn (Rule $rule): string => $rule->describe(), $erase->rules)),
            $erase instanceof Retain => 'retain:'.$erase->basis.':'.$erase->days.':'.$erase->from,
            $erase instanceof Delete => 'delete',
            default => 'none',
        };

        return implode('|', [$this->group, $this->matchKind, $this->matchColumn, $this->of, $erasePart, $erase?->removesFiles() ? 'files' : '']);
    }
}
