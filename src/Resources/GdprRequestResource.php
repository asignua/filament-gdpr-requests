<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Resources;

use Asignua\FilamentGdprRequests\DataMap\PersonalDataRegistry;
use Asignua\FilamentGdprRequests\Enums\RequestChannel;
use Asignua\FilamentGdprRequests\Enums\RequestStatus;
use Asignua\FilamentGdprRequests\Enums\RequestType;
use Asignua\FilamentGdprRequests\GdprRequestsPlugin;
use Asignua\FilamentGdprRequests\Models\GdprRequest;
use Asignua\FilamentGdprRequests\Resources\GdprRequestResource\Pages\CreateGdprRequest;
use Asignua\FilamentGdprRequests\Resources\GdprRequestResource\Pages\ErasurePlanPage;
use Asignua\FilamentGdprRequests\Resources\GdprRequestResource\Pages\ListGdprRequests;
use Asignua\FilamentGdprRequests\Resources\GdprRequestResource\Pages\ViewGdprRequest;
use Asignua\FilamentGdprRequests\Support\EventDetails;
use Asignua\FilamentGdprRequests\Support\Gates;
use Asignua\FilamentGdprRequests\Support\Lang;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class GdprRequestResource extends Resource
{
    protected static ?string $model = GdprRequest::class;

    /** Requests are global in v1; the stock tenant scoping (which needs an ownership relation) is off. */
    protected static bool $isScopedToTenant = false;

    public static function canAccess(): bool
    {
        $panel = Filament::getCurrentPanel();

        return $panel !== null
            && $panel->hasPlugin(GdprRequestsPlugin::ID)
            && !$panel->hasTenancy()
            && Gates::allows(Gates::VIEW, auth()->user());
    }

    /**
     * The rules live in the `get…AuthorizationResponse()` methods: from Filament 5.11.2 the pages ask those, not `canX()`
     * (which is `getXAuthorizationResponse()->allowed()` and so follows these by itself).
     */
    public static function getCreateAuthorizationResponse(): Response
    {
        return Gates::allows(Gates::MANAGE, auth()->user()) ? Response::allow() : Response::deny();
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return Response::deny();
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return Response::deny();
    }

    public static function getDeleteAnyAuthorizationResponse(): Response
    {
        return Response::deny();
    }

    public static function getModelLabel(): string
    {
        return Lang::t('resource.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return Lang::t('resource.plural');
    }

    public static function getNavigationLabel(): string
    {
        return Lang::t('resource.navigation');
    }

    public static function getNavigationGroup(): ?string
    {
        return self::plugin()?->getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        return self::plugin()?->getNavigationSort();
    }

    public static function getNavigationIcon(): string
    {
        return self::plugin()?->getNavigationIcon() ?? 'heroicon-o-shield-check';
    }

    public static function getNavigationBadge(): ?string
    {
        $overdue = GdprRequest::query()->whereIn('status', RequestStatus::openValues())->where('due_at', '<', now())->count();

        return $overdue > 0 ? (string) $overdue : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function getRecordTitle(?Model $record): ?string
    {
        return $record instanceof GdprRequest ? '#'.$record->reference() : null;
    }

    public static function form(Schema $schema): Schema
    {
        $roots = collect(app(PersonalDataRegistry::class)->subjectRoots())
            ->mapWithKeys(static fn ($entry): array => [$entry->model => $entry->getLabel()])
            ->all();

        return $schema->components([
            Section::make(Lang::t('form.request'))->columns(2)->schema([
                Select::make('type')->label(Lang::t('form.type'))->required()->native(false)
                    ->options(collect(RequestType::cases())->mapWithKeys(static fn (RequestType $type): array => [$type->value => $type->label()])->all()),
                Select::make('channel')->label(Lang::t('form.channel'))->required()->native(false)->default(RequestChannel::Email->value)
                    ->options(collect(RequestChannel::cases())->mapWithKeys(static fn (RequestChannel $channel): array => [$channel->value => $channel->label()])->all()),
                DateTimePicker::make('received_at')->label(Lang::t('form.received_at'))->required()->default(now())->maxDate(now()->addMinute()),
                Select::make('assignee_id')->label(Lang::t('form.assignee'))->native(false)->searchable()
                    ->options(static fn (): array => self::userOptions()),
                Textarea::make('message')->label(Lang::t('form.message'))->rows(3)->columnSpanFull(),
            ]),
            Section::make(Lang::t('form.requester'))->description(Lang::t('form.requester_help'))->columns(2)->schema([
                TextInput::make('name')->label(Lang::t('form.name'))->maxLength(255),
                TextInput::make('email')->label(Lang::t('form.email'))->email()->maxLength(255),
                Select::make('subject_model')->label(Lang::t('form.subject_model'))->native(false)->options($roots)->live(),
                TextInput::make('subject_id')->label(Lang::t('form.subject_id'))->helperText(Lang::t('form.subject_id_help'))->maxLength(64),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(Lang::t('view.overview'))->columns(4)->schema([
                TextEntry::make('reference')->label(Lang::t('view.reference'))->state(fn (GdprRequest $record): string => $record->reference())->copyable(),
                TextEntry::make('type')->label(Lang::t('form.type'))->badge()->formatStateUsing(fn (RequestType $state): string => $state->label()),
                TextEntry::make('status')->label(Lang::t('view.status'))->badge()
                    ->formatStateUsing(fn (RequestStatus $state): string => $state->label())->color(fn (RequestStatus $state): string => $state->color()),
                TextEntry::make('channel')->label(Lang::t('form.channel'))->formatStateUsing(fn (RequestChannel $state): string => $state->label()),
                TextEntry::make('received_at')->label(Lang::t('form.received_at'))->dateTime(),
                TextEntry::make('due_at')->label(Lang::t('table.due'))->dateTime('Y-m-d')
                    ->color(fn (GdprRequest $record): string => $record->isOverdue() ? 'danger' : 'gray')
                    ->hintIcon(fn (GdprRequest $record): ?string => $record->isOverdue() ? 'heroicon-o-exclamation-triangle' : null),
                TextEntry::make('extension_reason')->label(Lang::t('view.extension_reason'))->placeholder('—'),
                TextEntry::make('assignee_id')->label(Lang::t('form.assignee'))->placeholder('—')
                    ->formatStateUsing(static fn (?string $state): ?string => $state === null ? null : (self::userOptions()[$state] ?? '#'.$state)),
            ]),
            Section::make(Lang::t('view.subject'))->columns(3)->schema([
                TextEntry::make('subject_name')->label(Lang::t('form.name'))->placeholder('—'),
                TextEntry::make('subject_email')->label(Lang::t('form.email'))->placeholder('—'),
                TextEntry::make('subject_link')->label(Lang::t('view.linked_to'))->placeholder(Lang::t('view.not_linked'))
                    ->state(fn (GdprRequest $record): ?string => $record->hasSubject() ? class_basename((string) $record->subject_model).' #'.$record->subject_id : null),
                TextEntry::make('identity')->label(Lang::t('view.identity'))->badge()
                    ->state(fn (GdprRequest $record): string => $record->isVerified() ? Lang::t('view.verified') : Lang::t('view.unverified'))
                    ->color(fn (GdprRequest $record): string => $record->isVerified() ? 'success' : 'warning'),
                TextEntry::make('identity_note')->label(Lang::t('view.identity_note'))->placeholder('—')->columnSpan(2),
                TextEntry::make('pseudonym')->label(Lang::t('view.pseudonym'))->placeholder('—')
                    ->formatStateUsing(static fn (?string $state): ?string => $state === null ? null : substr($state, 0, 16).'…')->fontFamily('mono'),
                TextEntry::make('message')->label(Lang::t('form.message'))->placeholder('—')->columnSpanFull(),
                TextEntry::make('notes')->label(Lang::t('view.notes'))->placeholder('—')->columnSpanFull()->prose(),
            ]),
            Section::make(Lang::t('view.steps'))->visible(fn (GdprRequest $record): bool => $record->steps()->exists())->schema([
                RepeatableEntry::make('steps')->label(Lang::t('view.steps'))->hiddenLabel()->columns(6)->schema([
                    TextEntry::make('label')->label(Lang::t('plan.entry'))->columnSpan(2),
                    TextEntry::make('strategy')->label(Lang::t('plan.action'))->badge()->formatStateUsing(static fn (string $state): string => Lang::t('action.'.($state === '' ? 'none' : $state))),
                    TextEntry::make('status')->label(Lang::t('view.status'))->badge()->formatStateUsing(fn ($state): string => $state->label())->color(fn ($state): string => $state->color()),
                    TextEntry::make('counts')->label(Lang::t('plan.rows'))
                        ->state(static fn ($record): string => Lang::t('report.counts', ['matched' => $record->matched, 'changed' => $record->changed, 'trashed' => $record->trashed])),
                    TextEntry::make('error')->label(Lang::t('report.error'))->placeholder('—')
                        ->state(static fn ($record): ?string => $record->errorMessage()),
                ]),
            ]),
            Section::make(Lang::t('view.exports'))->visible(fn (GdprRequest $record): bool => $record->exports()->exists())->schema([
                RepeatableEntry::make('exports')->label(Lang::t('view.exports'))->hiddenLabel()->columns(5)->schema([
                    TextEntry::make('created_at')->label(Lang::t('view.created'))->dateTime(),
                    TextEntry::make('status')->label(Lang::t('view.status'))->badge()->formatStateUsing(static fn (string $state): string => Lang::t('export_status.'.$state)),
                    TextEntry::make('size')->label(Lang::t('view.size'))->formatStateUsing(static fn ($state): string => number_format(((int) $state) / 1024, 1).' KB'),
                    TextEntry::make('expires_at')->label(Lang::t('view.expires'))->dateTime('Y-m-d')->placeholder('—'),
                    TextEntry::make('error')->label(Lang::t('report.error'))->placeholder('—')
                        ->state(static fn ($record): ?string => $record->errorMessage()),
                ]),
            ]),
            Section::make(Lang::t('view.log'))->collapsible()->schema([
                RepeatableEntry::make('events')->label(Lang::t('view.log'))->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('created_at')->label(Lang::t('view.when'))->dateTime(),
                    TextEntry::make('event')->label(Lang::t('view.event'))->formatStateUsing(fn ($state, $record): string => $record->label()),
                    TextEntry::make('actor_id')->label(Lang::t('view.by'))->placeholder('—')
                        ->formatStateUsing(static fn (?string $state): ?string => $state === null ? null : (self::userOptions()[$state] ?? '#'.$state)),
                    TextEntry::make('context')->label(Lang::t('view.details'))->placeholder('—')
                        ->state(static fn ($record): ?string => EventDetails::format($record->context)),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('due_at')
            ->columns([
                TextColumn::make('ulid')->label(Lang::t('view.reference'))->state(fn (GdprRequest $record): string => $record->reference())->fontFamily('mono')->searchable(query: fn (Builder $query, string $search): Builder => $query->where('ulid', 'like', '%'.strtolower($search).'%')),
                TextColumn::make('type')->label(Lang::t('form.type'))->badge()->formatStateUsing(fn (RequestType $state): string => $state->label()),
                TextColumn::make('status')->label(Lang::t('view.status'))->badge()
                    ->formatStateUsing(fn (RequestStatus $state): string => $state->label())->color(fn (RequestStatus $state): string => $state->color()),
                TextColumn::make('subject_name')->label(Lang::t('table.subject'))->placeholder('—')->description(fn (GdprRequest $record): ?string => $record->subject_email)
                    ->searchable(['subject_name', 'subject_email']),
                IconColumn::make('identity_verified_at')->label(Lang::t('view.identity'))->boolean()->alignCenter(),
                TextColumn::make('due_at')->label(Lang::t('table.due'))->date('Y-m-d')->sortable()
                    ->color(fn (GdprRequest $record): string => $record->isOverdue() ? 'danger' : 'gray')
                    ->icon(fn (GdprRequest $record): ?string => $record->isOverdue() ? 'heroicon-o-exclamation-triangle' : null)
                    ->description(fn (GdprRequest $record): ?string => $record->isOverdue() ? Lang::t('table.overdue') : null),
                TextColumn::make('received_at')->label(Lang::t('form.received_at'))->date('Y-m-d')->sortable()->toggleable(),
                TextColumn::make('assignee_id')->label(Lang::t('form.assignee'))->placeholder('—')->toggleable()
                    ->formatStateUsing(static fn (?string $state): ?string => $state === null ? null : (self::userOptions()[$state] ?? '#'.$state)),
            ])
            ->filters([
                SelectFilter::make('status')->label(Lang::t('view.status'))->multiple()
                    ->options(collect(RequestStatus::cases())->mapWithKeys(static fn (RequestStatus $status): array => [$status->value => $status->label()])->all()),
                SelectFilter::make('type')->label(Lang::t('form.type'))->multiple()
                    ->options(collect(RequestType::cases())->mapWithKeys(static fn (RequestType $type): array => [$type->value => $type->label()])->all()),
                Filter::make('open')->label(Lang::t('table.only_open'))->default()
                    ->query(fn (Builder $query): Builder => $query->whereIn('status', RequestStatus::openValues())),
                Filter::make('overdue')->label(Lang::t('table.only_overdue'))
                    ->query(fn (Builder $query): Builder => $query->whereIn('status', RequestStatus::openValues())->where('due_at', '<', now())),
                Filter::make('mine')->label(Lang::t('table.only_mine'))
                    ->query(fn (Builder $query): Builder => $query->where('assignee_id', (string) auth()->id())),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('open')->label(Lang::t('actions.open'))->icon('heroicon-o-eye')
                        ->url(fn (GdprRequest $record): string => static::getUrl('view', ['record' => $record])),
                ]),
            ])
            ->recordUrl(fn (GdprRequest $record): string => static::getUrl('view', ['record' => $record]))
            ->emptyStateHeading(Lang::t('table.empty'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGdprRequests::route('/'),
            'create' => CreateGdprRequest::route('/create'),
            'view' => ViewGdprRequest::route('/{record}'),
            'erasure' => ErasurePlanPage::route('/{record}/erasure'),
        ];
    }

    /**
     * @return array<string, string> id => name, for the assignee picker and the log (staff names, not subjects).
     */
    public static function userOptions(): array
    {
        // One query per request, not one per table row; held in the container so a new application starts clean.
        $key = 'filament-gdpr-requests.user-options';

        if (app()->bound($key)) {
            return app($key);
        }

        /** @var class-string<Model>|null $model */
        $model = config('auth.providers.users.model');

        $options = !is_string($model) || !class_exists($model) ? [] : $model::query()->limit(500)->get()
            ->mapWithKeys(static fn (Model $user): array => [(string) $user->getKey() => (string) ($user->getAttribute('name') ?? $user->getAttribute('email') ?? $user->getKey())])
            ->all();

        app()->instance($key, $options);

        return $options;
    }

    private static function plugin(): ?GdprRequestsPlugin
    {
        $panel = Filament::getCurrentPanel();

        if ($panel === null || !$panel->hasPlugin(GdprRequestsPlugin::ID)) {
            return null;
        }

        /** @var GdprRequestsPlugin */
        return $panel->getPlugin(GdprRequestsPlugin::ID);
    }
}
