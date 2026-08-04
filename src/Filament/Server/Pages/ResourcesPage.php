<?php

namespace JoanFo\Resources\Filament\Server\Pages;

use App\Facades\Activity;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\Paginator as PaginatorContract;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use JoanFo\Resources\Jobs\IdentifyInstalledResourcesJob;
use JoanFo\Resources\Models\InstalledFile;
use JoanFo\Resources\Models\InstalledResourceCache;
use JoanFo\Resources\Models\Record;
use JoanFo\Resources\Services\CurseForgeService;
use JoanFo\Resources\Services\ModrinthService;
use JoanFo\Resources\Services\SpigotService;
use RuntimeException;

class ResourcesPage extends Page implements HasTable
{
    use InteractsWithTable;

    /** Page size used against the upstream APIs (CurseForge's maximum). */
    protected const API_PAGE_SIZE = 50;

    protected string $view = 'resources::filament.pages.resources';

    protected static ?string $slug = 'resources';

    protected static ?int $navigationSort = 10;

    public string $source = 'modrinth';

    public string $projectType = 'all';

    public ?string $searchQuery = '';

    public int $tablePage = 1;

    /** 'remote' browses the upstream APIs; 'installed' lists files from the server. */
    public string $mode = 'remote';

    public bool $viewAsGrid = false;

    private ?int $cachedTotalCount = null;

    private ?array $cachedInstalledFiles = null;

    private ?array $cachedUpdatableFiles = null;


    protected ModrinthService $modrinthService;

    protected CurseForgeService $curseForgeService;

    protected SpigotService $spigotService;

    protected DaemonFileRepository $fileRepository;

    public function boot(
        ModrinthService $modrinthService,
        CurseForgeService $curseForgeService,
        SpigotService $spigotService,
        DaemonFileRepository $fileRepository,
    ): void {
        $this->modrinthService = $modrinthService;
        $this->curseForgeService = $curseForgeService;
        $this->spigotService = $spigotService;
        $this->fileRepository = $fileRepository;
    }

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();


        $server->loadMissing('egg');

        return parent::canAccess() && in_array('minecraft', $server->egg->tags ?? []);
    }

    public function mount(): void
    {
        $enabledPlatforms = $this->getEnabledPlatforms();

        if (!empty($enabledPlatforms)) {
            $this->source = array_key_first($enabledPlatforms);
        }

        /** @var Server $server */
        $server = Filament::getTenant();
        $server->loadMissing('egg');

        $features = $server->egg->features ?? [];

        if (in_array('plugins', $features)) {
            $this->projectType = 'plugin';
        } elseif (in_array('mods', $features)) {
            $this->projectType = 'mod';
        }

        if ($this->sourceSupportsPluginsOnly()) {
            $this->projectType = 'plugin';
        }
    }

    public function getTitle(): string
    {
        return trans('resources::resources.title');
    }

    public static function getNavigationLabel(): string
    {
        return trans('resources::resources.navigation');
    }

    public function getHeading(): string
    {
        return trans('resources::resources.heading');
    }

    public static function getNavigationIcon(): string
    {
        return 'tabler-puzzle';
    }

    public function updatedSource(): void
    {
        if ($this->sourceSupportsPluginsOnly()) {
            $this->projectType = 'plugin';
        }

        $this->cachedTotalCount = null;
        $this->resetTable();
    }

    public function updatedProjectType(): void
    {
        $this->cachedTotalCount = null;
        $this->resetTable();
    }
    
    public function updatedSearchQuery(): void
    {
        $this->cachedTotalCount = null;
        $this->tablePage = 1;
        $this->dispatch('$refresh');
    }

    public function updatedTableSearch(): void
    {
        $oldSearch = $this->searchQuery;
        $this->searchQuery = $this->getTableSearch();
        Record::clearCache($oldSearch, $this->projectType, $this->source);
        $this->tablePage = 1;
        $this->cachedTotalCount = null;
        $this->resetTable();
    }

    public function updatedTableRecordsPerPage(): void
    {
        $this->tablePage = 1;
        $this->resetTable();
    }

    public function updatedMode(): void
    {
        $this->cachedInstalledFiles = null;
        $this->cachedTotalCount = null;
        $this->tablePage = 1;
        $this->resetTable();
    }

    public function updatedViewAsGrid(): void
    {
        $this->resetTable();
    }

    public function switchMode(string $mode): void
    {
        $this->mode = $mode;
        $this->cachedInstalledFiles = null;
        $this->cachedTotalCount = null;
        $this->tablePage = 1;
        $this->resetTable();
    }

    public function getTableRecordKey($record): string
    {
        return (string) $record->getKey();
    }

    public function table(Table $table): Table
    {
        if ($this->mode === 'installed') {
            return $this->buildInstalledTable($table);
        }

        return $this->buildRemoteTable($table);
    }

    private function buildRemoteTable(Table $table): Table
    {
        $tableSearch = $this->getTableSearch() ?? '';

        if ($tableSearch !== $this->searchQuery) {
            $oldSearch = $this->searchQuery;
            $this->searchQuery = $tableSearch;
            $this->cachedTotalCount = null;
            Record::clearCache($oldSearch, $this->projectType, $this->source);
        }

        $table = $table
            ->query(fn () => Record::query()
                ->where('search_query', $this->searchQuery ?? '')
                ->where('project_type', $this->projectType)
                ->whereIn('source', explode('-', $this->source))
                ->orderByDesc('downloads'))
            ->defaultSort('downloads', 'desc')
            ->columns([
                ImageColumn::make('icon')
                    ->label('')
                    ->defaultImageUrl(config('app.favicon', '/pelican.ico'))
                    ->imageSize(32)
                    ->square(),
                TextColumn::make('name')
                    ->label(trans('resources::resources.table.name'))
                    ->weight('bold')
                    ->description(fn (Record $record) => Str::limit($record->description ?? '', 80)),
                TextColumn::make('author')
                    ->label(trans('resources::resources.table.author')),
                TextColumn::make('downloads')
                    ->label(trans('resources::resources.table.downloads'))
                    ->sortable()
                    ->formatStateUsing(fn ($state) => number_format($state)),
                TextColumn::make('loader')
                    ->label('Loader')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'fabric'     => 'success',
                        'neoforge'   => 'info',
                        'forge'      => 'warning',
                        'quilt'      => 'primary',
                        'multi'      => 'gray',
                        default      => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => $state === 'multi' ? 'Multi-Loader' : ucfirst((string) $state))
                    ->placeholder('—'),
                TextColumn::make('type')
                    ->label(trans('resources::resources.table.type'))
                    ->badge()
                    ->color(fn ($state) => $state === 'mod' ? 'success' : 'info')
                    ->formatStateUsing(fn ($state) => ucfirst($state)),
                TextColumn::make('source')
                    ->label(trans('resources::resources.table.source'))
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'curseforge' => 'warning',
                        'modrinth' => 'success',
                        default => 'gray',
                    })
                    ->icon(fn ($state) => match ($state) {
                        'modrinth'   => 'tabler-leaf',
                        'curseforge' => 'tabler-flame',
                        'spigot'     => 'tabler-bolt',
                        'bukkit'     => 'tabler-bucket',
                        default      => 'tabler-package',
                    })
                    ->formatStateUsing(fn ($state) => ucfirst($state)),
            ])
            ->searchable()
            ->searchPlaceholder(trans('resources::resources.search_placeholder'))
            ->searchDebounce('750ms')
            ->searchOnBlur()
            ->recordAction('preview')
            ->recordActions([
                Action::make('preview')
                    ->label(trans('resources::resources.preview.action'))
                    ->icon('tabler-eye')
                    ->color('gray')
                    ->modalContent(fn (Record $record) => view('resources::filament.modals.project-preview', [
                        'record'  => $record,
                        'details' => $this->fetchProjectPreview($record),
                    ]))
                    ->schema(fn (Record $record) => [
                        Select::make('version')
                            ->label(trans('resources::resources.download.version'))
                            ->placeholder(trans('resources::resources.download.version_placeholder'))
                            ->options(collect($this->getVersions($record->project_id, $record->source))->pluck('name', 'id'))
                            ->required()
                            ->searchable()
                            ->native(false),
                    ])
                    ->action(fn (array $data, Record $record) => $this->downloadProject($record, $data['version']))
                    ->modalSubmitActionLabel(trans('resources::resources.download.action'))
                    ->modalCancelActionLabel(trans('resources::resources.preview.close'))
                    ->modalWidth('3xl')
                    ->modalHeading(''),
                Action::make('download')
                    ->label(trans('resources::resources.download.action'))
                    ->icon('tabler-download')
                    ->color('primary')
                    ->schema(fn (Record $record) => [
                        Select::make('version')
                            ->label(trans('resources::resources.download.version'))
                            ->placeholder(trans('resources::resources.download.version_placeholder'))
                            ->options(collect($this->getVersions($record->project_id, $record->source))->pluck('name', 'id'))
                            ->required()
                            ->searchable()
                            ->native(false)
                            ->helperText(trans('resources::resources.download.version_help')),
                    ])
                    ->action(fn (array $data, Record $record) => $this->downloadProject($record, $data['version']))
                    ->modalWidth('md')
                    ->modalHeading(fn (Record $record) => trans('resources::resources.download.heading', ['name' => $record->name])),
            ])
            ->headerActions([
                Action::make('toggle_grid')
                    ->label(fn () => $this->viewAsGrid
                        ? trans('resources::resources.view.list')
                        : trans('resources::resources.view.grid'))
                    ->icon(fn () => $this->viewAsGrid ? 'tabler-list' : 'tabler-layout-grid')
                    ->color('gray')
                    ->action(function () {
                        $this->viewAsGrid = !$this->viewAsGrid;
                        $this->resetTable();
                    }),
                Action::make('filters')
                    ->label(trans('resources::resources.filters.label'))
                    ->icon('tabler-filter')
                    ->color('gray')
                    ->fillForm(fn () => [
                        'source'      => $this->source,
                        'projectType' => $this->sourceSupportsPluginsOnly() ? 'plugin' : $this->projectType,
                    ])
                    ->schema([
                        Select::make('source')
                            ->label(trans('resources::resources.filters.platform'))
                            ->options(fn () => $this->getEnabledPlatforms())
                            ->required()
                            ->live()
                            ->native(false),
                        Select::make('projectType')
                            ->label(trans('resources::resources.filters.type'))
                            ->options(function ($get): array {
                                $source = is_callable($get) ? $get('source') : $this->source;

                                return $this->sourceSupportsPluginsOnly($source)
                                    ? ['plugin' => trans('resources::resources.filters.type_plugin')]
                                    : [
                                        'all'    => trans('resources::resources.filters.type_all'),
                                        'mod'    => trans('resources::resources.filters.type_mod'),
                                        'plugin' => trans('resources::resources.filters.type_plugin'),
                                    ];
                            })
                            ->required()
                            ->native(false),
                    ])
                    ->action(function (array $data): void {
                        $oldSource = $this->source;
                        $oldType   = $this->projectType;

                        $this->source      = $data['source'];
                        $this->projectType = $this->sourceSupportsPluginsOnly($this->source)
                            ? 'plugin'
                            : ($data['projectType'] ?? 'all');

                        Record::clearCache($this->searchQuery ?? '', $oldType, $oldSource);
                        Record::clearCache($this->searchQuery ?? '', $this->projectType, $this->source);

                        $this->cachedTotalCount = null;
                        $this->tablePage        = 1;
                        $this->flushCachedTableRecords();
                        $this->dispatch('$refresh');
                    })
                    ->modalWidth('md'),
            ])
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateIcon('tabler-puzzle')
            ->emptyStateHeading(trans('resources::resources.empty_heading'))
            ->emptyStateDescription(trans('resources::resources.empty_description'));

        if ($this->viewAsGrid) {
            $table = $table
                ->columns([
                    Stack::make([
                        Split::make([
                            ImageColumn::make('icon')
                                ->label('')
                                ->defaultImageUrl(config('app.favicon', '/pelican.ico'))
                                ->size(40)
                                ->square()
                                ->grow(false),
                            Stack::make([
                                TextColumn::make('name')
                                    ->weight('bold')
                                    ->label(''),
                                TextColumn::make('author')
                                    ->label('')
                                    ->color('gray')
                                    ->size('sm'),
                            ]),
                        ]),
                        TextColumn::make('description')
                            ->label('')
                            ->wrap()
                            ->color('gray')
                            ->size('sm')
                            ->limit(120),
                        Split::make([
                            TextColumn::make('downloads')
                                ->label('')
                                ->icon('tabler-download')
                                ->formatStateUsing(fn ($state) => number_format($state))
                                ->color('gray')
                                ->size('sm'),
                            TextColumn::make('loader')
                                ->label('')
                                ->badge()
                                ->color(fn ($state) => match ($state) {
                                    'fabric'   => 'success',
                                    'neoforge' => 'info',
                                    'forge'    => 'warning',
                                    'quilt'    => 'primary',
                                    'multi'    => 'gray',
                                    default    => 'gray',
                                })
                                ->formatStateUsing(fn ($state) => $state === 'multi' ? 'Multi-Loader' : ucfirst((string) $state))
                                ->hidden(fn ($state) => empty($state)),
                            TextColumn::make('source')
                                ->label('')
                                ->badge()
                                ->color(fn ($state) => match ($state) {
                                    'curseforge' => 'warning',
                                    'modrinth' => 'success',
                                    default => 'gray',
                                })
                                ->icon(fn ($state) => match ($state) {
                                    'modrinth'   => 'tabler-leaf',
                                    'curseforge' => 'tabler-flame',
                                    'spigot'     => 'tabler-bolt',
                                    'bukkit'     => 'tabler-bucket',
                                    default      => 'tabler-package',
                                })
                                ->formatStateUsing(fn ($state) => ucfirst($state))
                                ->alignEnd(),
                        ]),
                    ]),
                ])
                ->contentGrid(['md' => 2, 'xl' => 3]);
        }

        return $table;
    }

    private function buildInstalledTable(Table $table): Table
    {
        $table = $table
            ->records(function () {
                $search = strtolower($this->getTableSearch() ?? '');
                $files  = $this->getInstalledFiles();

                if ($search !== '') {
                    $files = array_values(array_filter(
                        $files,
                        fn ($f) => str_contains(strtolower($f['name']), $search)
                    ));
                }

                return collect($files)
                    ->map(function (array $attributes) {
                        $file = new InstalledFile();
                        $file->setRawAttributes($attributes);
                        $file->exists = true;
                        $file->syncOriginal();

                        return $file;
                    })
                    ->values();
            })
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                ImageColumn::make('icon_url')
                    ->label('')
                    ->defaultImageUrl(config('app.favicon', '/pelican.ico'))
                    ->imageSize(32)
                    ->square(),
                TextColumn::make('name')
                    ->label(trans('resources::resources.table.name'))
                    ->weight('bold'),
                TextColumn::make('version')
                    ->label(trans('resources::resources.installed.version'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('size')
                    ->label(trans('resources::resources.installed.size'))
                    ->formatStateUsing(fn ($state) => $this->formatBytes((int) $state)),
                TextColumn::make('loader')
                    ->label('Loader')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'fabric'   => 'success',
                        'neoforge' => 'info',
                        'forge'    => 'warning',
                        'quilt'    => 'primary',
                        'multi'    => 'gray',
                        default    => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => $state === 'multi' ? 'Multi-Loader' : ucfirst((string) $state))
                    ->placeholder('—'),
                TextColumn::make('type')
                    ->label(trans('resources::resources.table.type'))
                    ->badge()
                    ->color(fn ($state) => $state === 'mod' ? 'success' : 'info')
                    ->formatStateUsing(fn ($state) => ucfirst($state)),
                TextColumn::make('modified_at')
                    ->label(trans('resources::resources.installed.modified'))
                    ->dateTime()
                    ->since(),
            ])
            ->recordActions([
                Action::make('update')
                    ->label(trans('resources::resources.installed.update'))
                    ->icon('tabler-refresh')
                    ->color('warning')
                    ->visible(fn (InstalledFile $record): bool => isset($this->getUpdatableFiles()[$record->directory . '/' . $record->name]))
                    ->schema(fn (InstalledFile $record) => [
                        Select::make('version_id')
                            ->label(trans('resources::resources.download.version'))
                            ->options(fn () => $this->resolveUpdateOptions($record))
                            ->searchable()
                            ->native(false)
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, $set) {
                                if (!$state) {
                                    $set('changelog', '');
                                    return;
                                }
                                [$source, $projectId, $versionId] = explode(':', $state, 3);
                                $set('changelog', $this->fetchVersionChangelog($source, $projectId, $versionId));
                            }),
                        Textarea::make('changelog')
                            ->label('Changelog')
                            ->rows(10)
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder('Select a version to see its changelog.')
                            ->extraAttributes(['style' => 'resize:vertical;font-size:0.8rem;line-height:1.5;']),
                    ])
                    ->action(function (array $data, InstalledFile $record): void {
                        try {
                            /** @var Server $server */
                            $server = Filament::getTenant();

                            // Value is encoded as "source:projectId:versionId".
                            [$source, $projectId, $versionId] = explode(':', $data['version_id'], 3);

                            $url = $this->getUrlBySource($source, $projectId, $versionId);

                            if (!$url) {
                                Notification::make()->danger()
                                    ->title(trans('resources::resources.download.failed'))
                                    ->body(trans('resources::resources.download.no_url'))
                                    ->send();

                                return;
                            }

                            $this->fileRepository->setServer($server)->pull($url, $record->directory, ['use_header' => true]);

                            $newFilename = urldecode(basename(parse_url($url, PHP_URL_PATH) ?? ''));
                            if ($newFilename) {
                                InstalledResourceCache::put($server->id, $record->directory, $newFilename, [
                                    'source'     => $source,
                                    'project_id' => $projectId,
                                    'version_id' => $versionId,
                                    'name'       => pathinfo($newFilename, PATHINFO_FILENAME),
                                ]);
                            }

                            Cache::forget($this->updatableFilesCacheKey());
                            $this->cachedUpdatableFiles = null;
                            $this->loadUpdatableFiles();

                            Notification::make()->success()
                                ->title(trans('resources::resources.installed.update_started'))
                                ->body(trans('resources::resources.download.started_body', ['name' => $record->name, 'directory' => $record->directory]))
                                ->send();
                        } catch (Exception $e) {
                            Notification::make()->danger()
                                ->title(trans('resources::resources.download.failed'))
                                ->body($e->getMessage())
                                ->send();
                        }
                    })
                    ->modalHeading(fn (InstalledFile $record) => trans('resources::resources.installed.update_heading', ['name' => $record->name]))
                    ->modalWidth('md'),
                Action::make('delete')
                    ->label(trans('resources::resources.installed.delete'))
                    ->icon('tabler-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (InstalledFile $record) => trans('resources::resources.installed.delete_heading', ['name' => $record->name]))
                    ->modalDescription(trans('resources::resources.installed.delete_confirm'))
                    ->modalSubmitActionLabel(trans('resources::resources.installed.delete_action'))
                    ->action(function (InstalledFile $record): void {
                        try {
                            /** @var Server $server */
                            $server = Filament::getTenant();

                            $this->fileRepository->setServer($server)->deleteFiles($record->directory, [$record->name]);

                            InstalledResourceCache::remove($server->id, $record->directory, $record->name);

                            $this->cachedInstalledFiles = null;

                            Cache::forget($this->updatableFilesCacheKey());
                            $this->cachedUpdatableFiles = null;

                            $this->resetTable();

                            Notification::make()->success()
                                ->title(trans('resources::resources.installed.deleted'))
                                ->body(trans('resources::resources.installed.deleted_body', ['name' => $record->name]))
                                ->send();
                        } catch (Exception $e) {
                            Notification::make()->danger()
                                ->title(trans('resources::resources.installed.delete_failed'))
                                ->body($e->getMessage())
                                ->send();
                        }
                    }),
            ])
            ->headerActions([
                Action::make('update_all')
                    ->label(trans('resources::resources.installed.update_all'))
                    ->icon('tabler-refresh-alert')
                    ->color('warning')
                    ->action(function (): void {
                        /** @var Server $server */
                        $server = Filament::getTenant();
                        $this->fileRepository->setServer($server);
                        $updated = 0;
                        $failed = 0;

                        $serverId = $server->id;

                        foreach ($this->getInstalledFiles() as $fileData) {
                            try {
                                $meta = InstalledResourceCache::get($serverId, $fileData['directory'], $fileData['name']);

                                if (!$meta || empty($meta['project_id'])) {
                                    $failed++;
                                    continue;
                                }

                                $versions  = $this->getVersions($meta['project_id'], $meta['source']);
                                $versionId = $versions[0]['id'] ?? null;
                                if (!$versionId) {
                                    $failed++;
                                    continue;
                                }

                                $url = $this->getUrlBySource($meta['source'], $meta['project_id'], $versionId);
                                if (!$url) {
                                    $failed++;
                                    continue;
                                }

                                $this->fileRepository->pull($url, $fileData['directory'], ['use_header' => true]);

                                $newFilename = urldecode(basename(parse_url($url, PHP_URL_PATH) ?? ''));
                                if ($newFilename) {
                                    InstalledResourceCache::put($serverId, $fileData['directory'], $newFilename, [
                                        'source'     => $meta['source'],
                                        'project_id' => $meta['project_id'],
                                        'version_id' => $versionId,
                                        'name'       => $meta['name'] ?? pathinfo($newFilename, PATHINFO_FILENAME),
                                    ]);
                                }

                                $updated++;
                            } catch (Exception) {
                                $failed++;
                            }
                        }

                        if ($updated > 0) {
                            Notification::make()->success()
                                ->title(trans('resources::resources.installed.update_all_done', ['count' => $updated]))
                                ->send();
                        }
                        if ($failed > 0) {
                            Notification::make()->warning()
                                ->title(trans('resources::resources.installed.update_all_failed', ['count' => $failed]))
                                ->send();
                        }
                    }),
                Action::make('toggle_grid')
                    ->label(fn () => $this->viewAsGrid
                        ? trans('resources::resources.view.list')
                        : trans('resources::resources.view.grid'))
                    ->icon(fn () => $this->viewAsGrid ? 'tabler-list' : 'tabler-layout-grid')
                    ->color('gray')
                    ->action(function () {
                        $this->viewAsGrid = !$this->viewAsGrid;
                        $this->resetTable();
                    }),
            ])
            ->searchable()
            ->searchPlaceholder(trans('resources::resources.installed.search_placeholder'))
            ->emptyStateIcon('tabler-package-off')
            ->emptyStateHeading(trans('resources::resources.installed.empty_heading'))
            ->emptyStateDescription(trans('resources::resources.installed.empty_description'));

        if ($this->viewAsGrid) {
            $table = $table
                ->columns([
                    Stack::make([
                        Split::make([
                            ImageColumn::make('icon_url')
                                ->label('')
                                ->defaultImageUrl(config('app.favicon', '/pelican.ico'))
                                ->size(40)
                                ->square()
                                ->grow(false),
                            Stack::make([
                                TextColumn::make('name')
                                    ->weight('bold')
                                    ->label(''),
                                Split::make([
                                    TextColumn::make('loader')
                                        ->label('')
                                        ->badge()
                                        ->color(fn ($state) => match ($state) {
                                            'fabric'   => 'success',
                                            'neoforge' => 'info',
                                            'forge'    => 'warning',
                                            'quilt'    => 'primary',
                                            'multi'    => 'gray',
                                            default    => 'gray',
                                        })
                                        ->formatStateUsing(fn ($state) => $state === 'multi' ? 'Multi-Loader' : ucfirst((string) $state))
                                        ->hidden(fn ($state) => empty($state)),
                                    TextColumn::make('type')
                                        ->label('')
                                        ->badge()
                                        ->color(fn ($state) => $state === 'mod' ? 'success' : 'info')
                                        ->formatStateUsing(fn ($state) => ucfirst($state))
                                        ->alignEnd(),
                                ]),
                            ])->grow(),
                        ]),
                        Split::make([
                            TextColumn::make('version')
                                ->label('')
                                ->badge()
                                ->color('gray'),
                            TextColumn::make('size')
                                ->label('')
                                ->formatStateUsing(fn ($state) => $this->formatBytes((int) $state))
                                ->color('gray')
                                ->alignEnd(),
                        ]),
                        TextColumn::make('modified_at')
                            ->label('')
                            ->dateTime()
                            ->since()
                            ->color('gray')
                            ->size('sm'),
                    ]),
                ])
                ->contentGrid(['md' => 2, 'xl' => 3]);
        }

        return $table;
    }

    protected function paginateTableQuery(Builder $query): PaginatorContract
    {
        $perPage = $this->getTableRecordsPerPage();
        $page = $this->getTablePage();
        $this->tablePage = $page;

        $startRecord = ($page - 1) * $perPage;
        $endApiPage = (int) floor(($startRecord + $perPage - 1) / self::API_PAGE_SIZE) + 1;

        for ($apiPage = 1; $apiPage <= $endApiPage; $apiPage++) {
            if (!Record::isPageCached($this->searchQuery ?? '', $this->projectType, $this->source, $apiPage)) {
                $this->fetchAndCachePage($apiPage);
            }
        }

        $sources = explode('-', $this->source);
        $searchQuery = $this->searchQuery ?? '';

        $results = collect(Record::readCache())
            ->filter(fn ($record) => ($record['search_query'] ?? '') === $searchQuery
                && ($record['project_type'] ?? '') === $this->projectType
                && in_array($record['source'] ?? '', $sources, true))
            ->sortBy(fn ($record) => $record['api_index'] ?? 0)
            ->slice($startRecord, $perPage)
            ->map(function (array $attributes) {
                $record = new Record();
                $record->setRawAttributes($attributes);
                $record->exists = true;
                $record->syncOriginal();

                return $record;
            })
            ->values();

        return new LengthAwarePaginator(
            $results,
            $this->getTotalRecordCount(),
            $perPage,
            $page,
            [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => $this->getTablePaginationPageName(),
            ]
        );
    }

    /**
     * Fetch project metadata from the source API for the preview modal.
     *
     * @return array<string, mixed>
     */
    private function fetchProjectPreview(Record $record): array
    {
        try {
            if ($record->source === 'modrinth') {
                $response = Http::withUserAgent('ResourcesPlugin')
                    ->acceptJson()
                    ->timeout(10)
                    ->get(config('resources.modrinth_api_url') . '/project/' . $record->project_id);

                if ($response->successful()) {
                    $data = $response->json();
                    $data['_url'] = 'https://modrinth.com/' . ($data['project_type'] ?? 'mod') . '/' . ($data['slug'] ?? $record->project_id);
                    $data['_source'] = 'modrinth';

                    return $data;
                }
            } elseif (in_array($record->source, ['curseforge', 'bukkit'])) {
                if (!$this->curseForgeService->isConfigured()) {
                    return [];
                }

                $cfBaseUrl = config('resources.curseforge_api_url');
                $cfApiKey  = (string) config('resources.curseforge_api_key');

                ['mod' => $modResponse, 'desc' => $descResponse] = Http::pool(fn ($pool) => [
                    $pool->as('mod')
                        ->withHeader('x-api-key', $cfApiKey)
                        ->acceptJson()
                        ->timeout(10)
                        ->get($cfBaseUrl . '/mods/' . $record->project_id),
                    $pool->as('desc')
                        ->withHeader('x-api-key', $cfApiKey)
                        ->acceptJson()
                        ->timeout(10)
                        ->get($cfBaseUrl . '/mods/' . $record->project_id . '/description'),
                ]);

                if ($modResponse->successful()) {
                    $data = $modResponse->json()['data'] ?? [];
                    $classId = $data['classId'] ?? null;
                    $cfType = $classId === CurseForgeService::CLASS_BUKKIT_PLUGINS ? 'bukkit-plugins' : 'mc-mods';
                    $data['_url']          = 'https://www.curseforge.com/minecraft/' . $cfType . '/' . ($data['slug'] ?? $record->project_id);
                    $data['_source']       = 'curseforge';
                    $data['_body_is_html'] = true;
                    $data['body']          = $descResponse->successful() ? ($descResponse->json()['data'] ?? null) : null;

                    return $data;
                }
            } elseif ($record->source === 'spigot') {
                return [
                    '_url'    => 'https://www.spigotmc.org/resources/' . $record->project_id,
                    '_source' => 'spigot',
                    'name'    => $record->name,
                    'description' => $record->description,
                    'summary' => $record->description,
                ];
            }
        } catch (Exception) {
        }

        return [
            '_url'    => null,
            '_source' => $record->source,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    protected function getInstalledFiles(): array
    {
        if ($this->cachedInstalledFiles !== null) {
            return $this->cachedInstalledFiles;
        }

        /** @var Server $server */
        $server = Filament::getTenant();
        $serverId = $server->id;
        $this->fileRepository->setServer($server);

        $tracked = collect(InstalledResourceCache::all($serverId));

        $files = [];

        foreach (['/plugins' => 'plugin', '/mods' => 'mod'] as $dir => $type) {
            try {
                foreach ($this->fileRepository->getDirectory($dir) as $entry) {
                    if (!($entry['file'] ?? false)) {
                        continue;
                    }
                    $name = $entry['name'] ?? '';
                    if (!str_ends_with(strtolower($name), '.jar')) {
                        continue;
                    }

                    $key = $dir . '/' . $name;
                    $resource = $tracked->get($key)
                        ?? $tracked->get($dir . '/' . rawurlencode($name))
                        ?? $tracked->get($dir . '/' . urldecode($name));

                    if (!$resource) {
                        InstalledResourceCache::put($serverId, $dir, $name, [
                            'source'     => 'manual',
                            'project_id' => null,
                            'version_id' => null,
                            'name'       => pathinfo($name, PATHINFO_FILENAME),
                        ]);
                    }

                    $files[] = [
                        'id'          => md5($dir . $name),
                        'name'        => $name,
                        'size'        => $entry['size'] ?? 0,
                        'type'        => $type,
                        'directory'   => $dir,
                        'modified_at' => $entry['modified'] ?? '',
                        'version'     => $this->extractVersionFromFilename($name),
                        'loader'      => $this->extractLoaderFromFilename($name),
                        'source'      => $resource['source'] ?? 'manual',
                        'project_id'  => $resource['project_id'] ?? null,
                        'icon_url'    => null,
                    ];
                }
            } catch (Exception) {
                // Directory may not exist yet — skip silently.
            }
        }

        return $this->cachedInstalledFiles = $this->resolveInstalledIcons($files);
    }

    private function resolveInstalledIcons(array $files): array
    {
        // Only use the already-fetched Record cache (the JSON file written during remote browse).
        // No API calls here — page load stays instant regardless of how many mods are installed.
        $recordIndex = collect(Record::readCache())
            ->filter(fn ($r) => !empty($r['icon']) && !empty($r['project_id']))
            ->keyBy(fn ($r) => ($r['source'] ?? '') . ':' . $r['project_id']);

        return array_map(function (array $file) use ($recordIndex): array {
            if (!empty($file['project_id'])) {
                $key = $file['source'] . ':' . $file['project_id'];
                $file['icon_url'] = $recordIndex->get($key)['icon'] ?? null;
            }
            return $file;
        }, $files);
    }

    /**
     * Returns the files that have an update available, keyed by "directory/filename".
     *
     * Files without a stored version_id always get a button (we don't yet know their version).
     * Files with a version_id only get a button when the API reports a newer version exists.
     *
     * Results are cached for the duration of this Livewire request.
     */
    private function updatableFilesCacheKey(): string
    {
        return 'updatable_files_server_' . Filament::getTenant()->id;
    }

    /** Called by wire:init — runs after the initial page render so the UI is instant. */
    public function loadUpdatableFiles(): void
    {
        // Kick off identification of manually-installed jars on the queue (it reads whole jars
        // off the daemon to hash them — far too slow for the request cycle). Once the job
        // back-fills their project/version ids, they flow into computeUpdatableFiles() on a
        // later load and get an update button like plugin-installed ones.
        $this->dispatchIdentifyJob();

        $result = $this->computeUpdatableFiles();
        Cache::put($this->updatableFilesCacheKey(), $result, now()->addMinutes(5));
        $this->cachedUpdatableFiles = $result;
    }

    /**
     * Queue identification of manually-installed jars (hashing reads whole jars off the daemon,
     * so it must never run in the request cycle). Only dispatched when there is actually
     * unidentified work, and at most once per short window so refreshes don't pile up jobs.
     */
    protected function dispatchIdentifyJob(): void
    {
        /** @var Server $server */
        $server   = Filament::getTenant();
        $serverId = $server->id;

        // Ensure the cache reflects what's currently on disk (records new jars as 'manual').
        $this->getInstalledFiles();

        $hasWork = collect(InstalledResourceCache::all($serverId))
            ->contains(fn ($r) => IdentifyInstalledResourcesJob::needsIdentification($r));

        if (!$hasWork) {
            return;
        }

        // Cache::add is atomic — the first caller wins, later ones no-op until it expires.
        if (!Cache::add('rp_identify_dispatched_' . $serverId, true, now()->addMinutes(2))) {
            return;
        }

        IdentifyInstalledResourcesJob::dispatch($server);
    }

    /** L1 (in-request) → L2 (cross-request cache) → empty while not yet loaded. */
    private function getUpdatableFiles(): array
    {
        if ($this->cachedUpdatableFiles !== null) {
            return $this->cachedUpdatableFiles;
        }

        return $this->cachedUpdatableFiles = Cache::get($this->updatableFilesCacheKey()) ?? [];
    }

    private function computeUpdatableFiles(): array
    {
        $serverId  = Filament::getTenant()->id;
        $tracked   = collect(InstalledResourceCache::all($serverId))->values();
        $updatable = [];

        foreach ($tracked->filter(fn ($r) => ($r['version_id'] ?? null) === null && !empty($r['project_id'])) as $resource) {
            $updatable[$resource['directory'] . '/' . $resource['filename']] = true;
        }

        $withVersion = $tracked->filter(fn ($r) => !empty($r['version_id']) && ($r['source'] ?? '') !== 'manual');
        $modrinth    = $withVersion->filter(fn ($r) => ($r['source'] ?? '') === 'modrinth');
        $curseforge  = $withVersion->filter(fn ($r) => ($r['source'] ?? '') === 'curseforge');

        if ($modrinth->isNotEmpty()) {
            $baseUrl = config('resources.modrinth_api_url');
            $responses = Http::pool(function ($pool) use ($modrinth, $baseUrl) {
                foreach ($modrinth as $resource) {
                    $params = ['limit' => 1];

                    $gv = $this->extractMcVersion($resource['filename']);
                    if ($gv) {
                        $params['game_versions'] = json_encode([$gv]);
                    }

                    $loader = $this->extractLoaderFromFilename($resource['filename']);
                    if ($loader) {
                        $params['loaders'] = json_encode([$loader]);
                    }

                    $poolKey = md5($resource['directory'] . $resource['filename']);
                    $pool->as($poolKey)
                        ->withUserAgent('ResourcesPlugin')
                        ->acceptJson()
                        ->connectTimeout(5)
                        ->timeout(15)
                        ->get($baseUrl . '/project/' . $resource['project_id'] . '/version', $params);
                }
            });

            foreach ($modrinth as $resource) {
                $key      = $resource['directory'] . '/' . $resource['filename'];
                $poolKey  = md5($resource['directory'] . $resource['filename']);
                $response = $responses[$poolKey] ?? null;
                if ($response instanceof \Throwable || $response === null || $response->failed()) {
                    $updatable[$key] = true;
                    continue;
                }
                $latest = $response->json()[0] ?? null;
                if ($latest && $latest['id'] !== $resource['version_id']) {
                    $updatable[$key] = true;
                }
            }
        }

        if ($curseforge->isNotEmpty()) {
            $cfBaseUrl = config('resources.curseforge_api_url');
            $cfApiKey  = (string) config('resources.curseforge_api_key');
            $responses = Http::pool(function ($pool) use ($curseforge, $cfBaseUrl, $cfApiKey) {
                foreach ($curseforge as $resource) {
                    $params = ['pageSize' => 50, 'index' => 0];

                    $gv = $this->extractMcVersion($resource['filename']);
                    if ($gv) {
                        $params['gameVersion'] = $gv;
                    }

                    $poolKey = md5($resource['directory'] . $resource['filename']);
                    $pool->as($poolKey)
                        ->withHeader('x-api-key', $cfApiKey)
                        ->acceptJson()
                        ->connectTimeout(5)
                        ->timeout(15)
                        ->get($cfBaseUrl . '/mods/' . $resource['project_id'] . '/files', $params);
                }
            });

            foreach ($curseforge as $resource) {
                $key      = $resource['directory'] . '/' . $resource['filename'];
                $poolKey  = md5($resource['directory'] . $resource['filename']);
                $response = $responses[$poolKey] ?? null;
                if ($response instanceof \Throwable || $response === null || $response->failed()) {
                    $updatable[$key] = true;
                    continue;
                }

                $files = $response->json()['data'] ?? [];

                $installedLoader = $this->extractLoaderFromFilename($resource['filename']);
                if ($installedLoader && in_array($installedLoader, ['fabric', 'quilt'], true)) {
                    $files = array_filter($files, function ($file) use ($installedLoader) {
                        $gameVersions = array_map('strtolower', $file['gameVersions'] ?? []);
                        return in_array($installedLoader, $gameVersions, true);
                    });
                }

                $latest = array_values($files)[0] ?? null;
                if ($latest && (string) ($latest['id'] ?? '') !== $resource['version_id']) {
                    $updatable[$key] = true;
                }
            }
        }

        return $updatable;
    }

    /** Extract the mod/plugin version from a filename, e.g. "sodium-0.5.6+mc1.21.jar" → "0.5.6". */
    private function extractVersionFromFilename(string $filename): string
    {
        $name = preg_replace('/\.jar$/i', '', $filename) ?? $filename;
        $name = preg_replace('/[-_+](fabric|forge|neoforge|quilt|spigot|paper|bukkit|velocity|sponge|liteloader)$/i', '', $name) ?? $name;
        $name = preg_replace('/[-+_]mc[\d.]+$/i', '', $name) ?? $name;
        if (preg_match('/[-+_](v?\d[\d.\-]*\d|\d+)$/', $name, $m)) {
            return ltrim($m[1], 'v');
        }

        return '1.0.0';
    }

    /** Extract Minecraft version from a mod filename, e.g. "sodium-0.5.6+mc1.21.jar" → "1.21". */
    private function extractMcVersion(string $filename): ?string
    {
        if (preg_match('/[-+_]mc(1\.\d+(?:\.\d+)?)/i', $filename, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Extract the mod loader from a filename.
     * Checks 'neoforge' before 'forge' to avoid false matches.
     */
    private function extractLoaderFromFilename(string $filename): ?string
    {
        $lower = strtolower($filename);

        foreach (['neoforge', 'fabric', 'forge', 'quilt', 'liteloader'] as $loader) {
            if (str_contains($lower, $loader)) {
                return $loader;
            }
        }

        return null;
    }

    /**
     * Build version Select options for the Update action.
     *
     * Option values are encoded as "source:projectId:versionId" so the action
     * can resolve the download URL without a separate DB lookup.
     *
     * Resolution order:
     *  1. InstalledResourceCache entry (accurate, saved at install time)
     *  1b. Same lookup with URL-encoded filename (auto-fixes old records that stored %2B instead of +)
     *  2. Modrinth filename search (fallback for pre-tracking installs)
     *  3. CurseForge filename search (second fallback)
     *
     * @return array<string, string>
     */
    private function resolveUpdateOptions(InstalledFile $record): array
    {
        $encode = fn (string $source, string $projectId, array $versions): array => collect($versions)
            ->mapWithKeys(fn ($v) => ["{$source}:{$projectId}:{$v['id']}" => $v['name']])
            ->all();

        $serverId = Filament::getTenant()->id;

        $meta = InstalledResourceCache::get($serverId, $record->directory, $record->name);

        // Fallback for entries stored with URL-encoded filename (e.g. %2B instead of +).
        if (!$meta) {
            $encoded = rawurlencode($record->name);
            if ($encoded !== $record->name) {
                $meta = InstalledResourceCache::get($serverId, $record->directory, $encoded);
                if ($meta) {
                    InstalledResourceCache::put($serverId, $record->directory, $record->name, $meta);
                    InstalledResourceCache::remove($serverId, $record->directory, $encoded);
                }
            }
        }

        $gameVersion = $this->extractMcVersion($record->name);
        $loader      = $this->extractLoaderFromFilename($record->name);

        if ($meta) {
            $versions = $this->getVersions($meta['project_id'] ?? null, $meta['source'] ?? '', $gameVersion, $loader);

            if (!empty($meta['version_id']) && !empty($versions)) {
                $installedIndex = array_search($meta['version_id'], array_column($versions, 'id'), true);
                if ($installedIndex !== false) {
                    $versions = array_slice($versions, 0, (int) $installedIndex);
                }
            }

            if (!empty($versions)) {
                return $encode($meta['source'], $meta['project_id'], $versions);
            }
        }

        $cleanName = $this->cleanFilename($record->name);

        try {
            $typeFacet = ['project_type' => $record->type === 'mod' ? ['mod'] : ['plugin']];
            $modrinthHits = $this->modrinthService->search($cleanName, $typeFacet, 1, 0)['hits'] ?? [];

            // Drop the type filter if no results — some projects are cross-classified.
            if (empty($modrinthHits)) {
                $modrinthHits = $this->modrinthService->search($cleanName, [], 1, 0)['hits'] ?? [];
            }

            if (!empty($modrinthHits)) {
                $projectId = $modrinthHits[0]['project_id'];
                $versions = $this->getVersions($projectId, 'modrinth', $gameVersion, $loader);
                if (!empty($versions)) {
                    return $encode('modrinth', $projectId, $versions);
                }
            }
        } catch (Exception) {
        }

        if ($this->curseForgeService->isConfigured()) {
            try {
                $classId = $record->type === 'mod' ? CurseForgeService::CLASS_MODS : CurseForgeService::CLASS_BUKKIT_PLUGINS;
                $cfResults = $this->curseForgeService->search($cleanName, $classId, CurseForgeService::GAME_MINECRAFT, 1, 0)['data'] ?? [];

                if (empty($cfResults)) {
                    $cfResults = $this->curseForgeService->search($cleanName, null, CurseForgeService::GAME_MINECRAFT, 1, 0)['data'] ?? [];
                }

                if (!empty($cfResults)) {
                    $projectId = (string) $cfResults[0]['id'];
                    $versions = $this->getVersions($projectId, 'curseforge', $gameVersion, $loader);
                    if (!empty($versions)) {
                        return $encode('curseforge', $projectId, $versions);
                    }
                }
            } catch (Exception) {
            }
        }

        return [];
    }

    private function getUrlBySource(string $source, string $projectId, string $versionId): ?string
    {
        return match ($source) {
            'modrinth'             => $this->modrinthService->getDownloadUrl($versionId),
            'curseforge', 'bukkit' => $this->curseForgeService->getDownloadUrl((int) $projectId, (int) $versionId),
            'spigot'               => $this->spigotService->getDownloadUrl((int) $projectId, $versionId),
            default                => null,
        };
    }

    private function fetchVersionChangelog(string $source, string $projectId, string $versionId): string
    {
        try {
            if ($source === 'modrinth') {
                $response = Http::withUserAgent('ResourcesPlugin')
                    ->acceptJson()
                    ->timeout(8)
                    ->get(config('resources.modrinth_api_url') . '/version/' . $versionId);
                if ($response->successful()) {
                    $text = $response->json()['changelog'] ?? null;
                    return $text ? trim($text) : 'No changelog provided.';
                }
            } elseif (in_array($source, ['curseforge', 'bukkit'], true)) {
                $cfApiKey = (string) config('resources.curseforge_api_key');
                $response = Http::withUserAgent('ResourcesPlugin')
                    ->withHeader('x-api-key', $cfApiKey)
                    ->acceptJson()
                    ->timeout(8)
                    ->get(config('resources.curseforge_api_url') . '/mods/' . $projectId . '/files/' . $versionId . '/changelog');
                if ($response->successful()) {
                    $html = $response->json()['data'] ?? null;
                    return $html ? trim(strip_tags($html)) : 'No changelog provided.';
                }
            }
        } catch (Exception) {
        }

        return 'Unable to fetch changelog.';
    }

    private function cleanFilename(string $filename): string
    {
        $name = preg_replace('/\.jar$/i', '', $filename) ?? $filename;
        $name = preg_replace('/[-_+](?:v|\d)[\d.\-_+a-zA-Z]*$/', '', $name) ?? $name;
        $name = preg_replace('/[-_+]mc[\d.]+$/i', '', $name) ?? $name;
        $name = preg_replace('/[-_+](fabric|forge|neoforge|quilt|spigot|paper|bukkit|velocity|sponge|liteloader)$/i', '', $name) ?? $name;

        return trim(str_replace(['-', '_', '+'], ' ', $name));
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1_048_576) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / 1_048_576, 1) . ' MB';
    }

    /**
     * @return array<string, string>
     */
    protected function getEnabledPlatforms(): array
    {
        $names = [
            'modrinth' => 'Modrinth',
            'curseforge' => 'CurseForge',
            'bukkit' => 'Bukkit',
            'spigot' => 'Spigot',
        ];

        $platforms = collect(config('resources.platforms'))->filter()->keys();

        if (!$this->curseForgeService->isConfigured()) {
            $platforms = $platforms->diff(['curseforge', 'bukkit']);
        }

        $enabled = $platforms
            ->mapWithKeys(fn (string $platform) => [$platform => $names[$platform] ?? $platform])
            ->all();

        if (isset($enabled['modrinth'], $enabled['curseforge'])) {
            $enabled['modrinth-curseforge'] = 'Modrinth & CurseForge';
        }

        if (isset($enabled['bukkit'], $enabled['spigot'])) {
            $enabled['bukkit-spigot'] = 'Bukkit & Spigot';
        }

        return $enabled;
    }

    private function sourceSupportsPluginsOnly(?string $source = null): bool
    {
        return in_array($source ?? $this->source, ['bukkit', 'spigot', 'bukkit-spigot'], true);
    }

    protected function getTotalRecordCount(): int
    {
        if ($this->cachedTotalCount !== null) {
            return $this->cachedTotalCount;
        }

        $sources = explode('-', $this->source);

        return $this->cachedTotalCount = array_sum(
            array_map(fn (string $source) => $this->getTotalCountFromSingleSource($source), $sources)
        );
    }

    protected function getTotalCountFromSingleSource(string $source): int
    {
        $searchQuery = $this->searchQuery ?? '';

        try {
            if ($this->projectType === 'all') {
                return match ($source) {
                    'modrinth' => $this->modrinthService->getTotalCount($searchQuery, [
                        'project_type' => ['mod', 'plugin'],
                    ]),
                    'curseforge' => $this->curseForgeService->getTotalCount($searchQuery, CurseForgeService::CLASS_MODS)
                        + $this->curseForgeService->getTotalCount($searchQuery, CurseForgeService::CLASS_BUKKIT_PLUGINS),
                    'bukkit' => $this->curseForgeService->getTotalCount($searchQuery, CurseForgeService::CLASS_BUKKIT_PLUGINS),
                    'spigot' => $this->spigotService->getTotalCount($searchQuery),
                    default => 0,
                };
            }

            return match ($source) {
                'modrinth' => $this->modrinthService->getTotalCount($searchQuery, $this->getModrinthFacets()),
                'curseforge' => $this->curseForgeService->getTotalCount($searchQuery, $this->getCurseForgeClassId()),
                'bukkit' => $this->curseForgeService->getTotalCount($searchQuery, CurseForgeService::CLASS_BUKKIT_PLUGINS),
                'spigot' => $this->spigotService->getTotalCount($searchQuery),
                default => 0,
            };
        } catch (RuntimeException $e) {
            $this->notifyCurseForgeError($e);

            return 0;
        }
    }

    protected function fetchAndCachePage(int $page): void
    {
        $cache = Record::readCache();

        foreach ($this->fetchProjects($page) as $index => $project) {
            $compositeId = md5($project['id'].$project['source'].($this->searchQuery ?? '').$this->projectType.$this->source);

            $cache[$compositeId] = [
                'id'             => $compositeId,
                'project_id'     => (string) $project['id'],
                'slug'           => $project['slug'],
                'name'           => $project['name'],
                'description'    => $project['description'] ?? '',
                'author'         => $project['author'],
                'icon'           => $project['icon'],
                'downloads'      => $project['downloads'],
                'type'           => $project['type'],
                'loader'         => $project['loader'] ?? null,
                'loader_detected' => true,
                'type_detected'  => 2,
                'source'         => $project['source'],
                'search_query'   => $this->searchQuery ?? '',
                'project_type'   => $this->projectType,
                'api_page'       => $page,
                'api_index'      => $project['api_index'] ?? (($page - 1) * self::API_PAGE_SIZE + $index),
            ];
        }

        Record::writeCache($cache);
    }

    protected function fetchProjects(int $page): Collection
    {
        $sources = explode('-', $this->source);
        $offset = ($page - 1) * self::API_PAGE_SIZE;

        return collect($sources)
            ->flatMap(fn (string $source) => $this->fetchFromSingleSource($source, $page))
            ->unique(fn ($project) => $project['id'].'-'.$project['source'])
            ->sortByDesc('downloads')
            ->slice(0, self::API_PAGE_SIZE)
            ->values()
            ->map(function (array $project, int $index) use ($offset): array {
                $project['api_index'] = $offset + $index;

                return $project;
            });
    }

    protected function fetchFromSingleSource(string $source, int $page): Collection
    {
        if ($this->projectType === 'all') {
            return match ($source) {
                'modrinth' => $this->fetchModrinthProjects($page),
                'curseforge' => collect([
                    ...$this->fetchCurseForgeProjects($page, CurseForgeService::CLASS_MODS, 'curseforge'),
                    ...$this->fetchCurseForgeProjects($page, CurseForgeService::CLASS_BUKKIT_PLUGINS, 'curseforge'),
                ]),
                'bukkit' => $this->fetchCurseForgeProjects($page, CurseForgeService::CLASS_BUKKIT_PLUGINS, 'bukkit'),
                'spigot' => $this->fetchSpigotProjects($page),
                default => collect(),
            };
        }

        return match ($source) {
            'modrinth' => $this->fetchModrinthProjects($page),
            'curseforge' => $this->fetchCurseForgeProjects($page, $this->getCurseForgeClassId(), 'curseforge'),
            'bukkit' => $this->fetchCurseForgeProjects($page, CurseForgeService::CLASS_BUKKIT_PLUGINS, 'bukkit'),
            'spigot' => $this->fetchSpigotProjects($page),
            default => collect(),
        };
    }

    /**
     * Restrict Modrinth results to mods and plugins — exclude resource packs, shaders, modpacks, etc.
     *
     * @return array<string, string|array<int, string>>
     */
    protected function getModrinthFacets(): array
    {
        if ($this->projectType === 'plugin') {
            return [
                'project_type' => 'plugin',
            ];
        }

        return [
            'project_type' => 'mod',
        ];
    }

    private function isModrinthPlugin(array $project): bool
    {
        return !empty(array_intersect(
            ['bukkit', 'spigot', 'paper', 'folia', 'purpur', 'velocity', 'waterfall', 'sponge'],
            array_map('strtolower', $project['categories'] ?? [])
        ));
    }

    protected function getCurseForgeClassId(): ?int
    {
        return match ($this->projectType) {
            'plugin' => CurseForgeService::CLASS_BUKKIT_PLUGINS,
            'mod' => CurseForgeService::CLASS_MODS,
            default => null,
        };
    }

    protected function fetchModrinthProjects(int $page): Collection
    {
        $limit = self::API_PAGE_SIZE;
        $offset = ($page - 1) * $limit;
        $pluginIds = [];
        $results = $this->modrinthService->search(
            $this->searchQuery ?? '',
            $this->getModrinthFacets(),
            $limit,
            $offset,
        );

        if ($this->projectType === 'all') {
            $pluginResults = $this->modrinthService->search(
                $this->searchQuery ?? '',
                [
                    'project_type' => 'plugin',
                ],
                $limit,
                $offset,
            );

            $results['hits'] = collect(array_merge(
                $results['hits'] ?? [],
                $pluginResults['hits'] ?? [],
            ))->unique('project_id')->values()->all();
            $pluginIds = collect($pluginResults['hits'] ?? [])
                ->pluck('project_id')
                ->map(fn ($id) => (string) $id)
                ->all();
        }

        $knownLoaders = ['neoforge', 'fabric', 'forge', 'quilt', 'liteloader'];

        return collect($results['hits'] ?? [])->map(function ($hit) use ($knownLoaders, $pluginIds) {
            $cats  = array_map('strtolower', $hit['categories'] ?? []);
            $found = array_values(array_filter($knownLoaders, fn ($l) => in_array($l, $cats)));
            $loader = match (count($found)) {
                0       => null,
                1       => $found[0],
                default => 'multi',
            };

            return [
                'id'          => $hit['project_id'],
                'slug'        => $hit['slug'],
                'name'        => $hit['title'],
                'description' => $hit['description'],
                'author'      => $hit['author'] ?? 'Unknown',
                'icon'        => $hit['icon_url'] ?? null,
                'downloads'   => $hit['downloads'] ?? 0,
                'type'        => $this->projectType !== 'all'
                    ? $this->projectType
                    : (in_array((string) $hit['project_id'], $pluginIds, true) || $this->isModrinthPlugin($hit)
                        ? 'plugin'
                        : 'mod'),
                'loader'      => $loader,
                'source'      => 'modrinth',
            ];
        });
    }

    /**
     * Fetch projects from the CurseForge API; used for both the CurseForge and Bukkit sources.
     */
    protected function fetchCurseForgeProjects(int $page, ?int $classId, string $source): Collection
    {
        if (!$this->curseForgeService->isConfigured()) {
            Notification::make()
                ->title(trans('resources::resources.curseforge_not_configured'))
                ->warning()
                ->body(trans('resources::resources.curseforge_not_configured_body'))
                ->send();

            return collect();
        }

        try {
            $results = $this->curseForgeService->search(
                $this->searchQuery ?? '',
                $classId,
                CurseForgeService::GAME_MINECRAFT,
                self::API_PAGE_SIZE,
                ($page - 1) * self::API_PAGE_SIZE,
            );
        } catch (RuntimeException $e) {
            $this->notifyCurseForgeError($e);

            return collect();
        }

        $cfLoaderNames = ['neoforge', 'fabric', 'forge', 'quilt', 'liteloader'];
        $cfLoaderMap   = [1 => 'forge', 3 => 'liteloader', 4 => 'fabric', 5 => 'quilt', 6 => 'neoforge'];

        return collect($results['data'] ?? [])->map(function ($mod) use ($source, $cfLoaderNames, $cfLoaderMap) {
            // Primary: scan gameVersions strings in latestFiles — more reliable than modLoaderType.
            // CurseForge includes strings like "Forge", "Fabric", "NeoForge" alongside game versions.
            $found = collect($mod['latestFiles'] ?? [])
                ->flatMap(fn ($f) => array_map('strtolower', $f['gameVersions'] ?? []))
                ->unique()
                ->intersect($cfLoaderNames)
                ->values()
                ->all();

            // Fallback: modLoaderType integers in latestFilesIndexes (many mods use 0=Any here).
            if (empty($found)) {
                $found = array_unique(array_values(array_filter(array_map(
                    fn ($idx) => $cfLoaderMap[$idx['modLoaderType'] ?? 0] ?? null,
                    $mod['latestFilesIndexes'] ?? []
                ))));
            }

            $loader = match (count($found)) {
                0       => null,
                1       => $found[0],
                default => 'multi',
            };

            return [
                'id'          => (string) $mod['id'],
                'slug'        => $mod['slug'],
                'name'        => $mod['name'],
                'description' => $mod['summary'] ?? '',
                'author'      => $mod['authors'][0]['name'] ?? 'Unknown',
                'icon'        => $mod['logo']['thumbnailUrl'] ?? null,
                'downloads'   => $mod['downloadCount'] ?? 0,
                'type'        => $this->projectType !== 'all'
                    ? $this->projectType
                    : (($mod['classId'] ?? null) === CurseForgeService::CLASS_BUKKIT_PLUGINS ? 'plugin' : 'mod'),
                'loader'      => $loader,
                'source'      => $source,
            ];
        });
    }

    protected function fetchSpigotProjects(int $page): Collection
    {
        $results = $this->spigotService->search($this->searchQuery ?? '', self::API_PAGE_SIZE, $page);

        return collect($results)->map(fn ($resource) => [
            'id' => (string) $resource['id'],
            'slug' => $resource['name'],
            'name' => $resource['name'],
            'description' => $resource['tag'] ?? '',
            'author' => $resource['author']['name'] ?? 'Unknown',
            'icon' => isset($resource['icon']['url']) ? 'https://www.spigotmc.org/'.$resource['icon']['url'] : null,
            'downloads' => $resource['downloads'] ?? 0,
            'type' => 'plugin',
            'source' => 'spigot',
        ]);
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    protected function getVersions(?string $projectId, string $source, ?string $gameVersion = null, ?string $loader = null): array
    {
        if (empty($projectId)) {
            return [];
        }

        try {
            return match ($source) {
                'modrinth' => $this->modrinthService->getVersionOptions($projectId, $gameVersion, $loader),
                'curseforge', 'bukkit' => $this->curseForgeService->getVersionOptions((int) $projectId, $gameVersion),
                'spigot' => $this->spigotService->getVersionOptions((int) $projectId),
                default => [],
            };
        } catch (RuntimeException $e) {
            $this->notifyCurseForgeError($e);

            return [];
        }
    }

    public function downloadProject(Record $record, string $versionId): void
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        try {
            $downloadUrl = match ($record->source) {
                'modrinth' => $this->modrinthService->getDownloadUrl($versionId),
                'curseforge', 'bukkit' => $this->curseForgeService->getDownloadUrl((int) $record->project_id, (int) $versionId),
                'spigot' => $this->spigotService->getDownloadUrl((int) $record->project_id, $versionId),
                default => null,
            };

            if (!$downloadUrl) {
                Notification::make()
                    ->title(trans('resources::resources.download.failed'))
                    ->danger()
                    ->body(trans('resources::resources.download.no_url'))
                    ->send();

                return;
            }

            $targetDir = $record->type === 'plugin' ? '/plugins' : '/mods';

            $this->fileRepository->setServer($server)->pull($downloadUrl, $targetDir, ['use_header' => true]);

            // URL-decode the filename: the daemon uses the decoded name on disk (e.g. "+" not "%2B").
            $filename = urldecode(basename(parse_url($downloadUrl, PHP_URL_PATH) ?? ''));
            if ($filename) {
                InstalledResourceCache::put($server->id, $targetDir, $filename, [
                    'source'     => $record->source,
                    'project_id' => $record->project_id,
                    'version_id' => $versionId,
                    'name'       => $record->name,
                ]);
            }

            Activity::event('server:file.pull')
                ->property('url', $downloadUrl)
                ->property('directory', $targetDir)
                ->property('source', $record->source)
                ->property('project', $record->name)
                ->log();

            Notification::make()
                ->title(trans('resources::resources.download.started'))
                ->success()
                ->body(trans('resources::resources.download.started_body', ['name' => $record->name, 'directory' => $targetDir]))
                ->send();
        } catch (Exception $e) {
            report($e);

            Notification::make()
                ->title(trans('resources::resources.download.failed'))
                ->danger()
                ->body($e->getMessage())
                ->send();
        }
    }

    protected function notifyCurseForgeError(RuntimeException $e): void
    {
        Notification::make()
            ->title(trans('resources::resources.curseforge_error'))
            ->danger()
            ->body($e->getMessage())
            ->send();
    }
}
