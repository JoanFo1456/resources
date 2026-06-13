<?php

namespace JoanFo\Resources\Filament\Server\Pages;

use App\Facades\Activity;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\Paginator as PaginatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
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

    private ?int $cachedTotalCount = null;

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

        if (!$server) {
            return false;
        }

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
        Record::clearCache($this->searchQuery, $this->projectType, $this->source);
        $this->searchQuery = $this->getTableSearch();
        $this->tablePage = 1;
        $this->cachedTotalCount = null;
        $this->resetTable();
    }

    public function updatedTableRecordsPerPage(): void
    {
        $this->tablePage = 1;
        $this->resetTable();
    }

    public function getTableRecordKey($record): string
    {
        return (string) $record->getKey();
    }

    public function table(Table $table): Table
    {
        $tableSearch = $this->getTableSearch() ?? '';

        if ($tableSearch !== $this->searchQuery) {
            $this->searchQuery = $tableSearch;
            $this->cachedTotalCount = null;
            Record::clearCache($this->searchQuery, $this->projectType, $this->source);
        }

        return $table
            ->query(fn () => Record::query()
                ->where('search_query', $this->searchQuery ?? '')
                ->where('project_type', $this->projectType)
                ->whereIn('source', explode('-', $this->source))
                ->orderByDesc('downloads'))
            ->defaultSort('downloads', 'desc')
            ->columns([
                ImageColumn::make('icon')
                    ->label('')
                    ->defaultImageUrl('/images/egg-default.png')
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
                    ->formatStateUsing(fn ($state) => ucfirst($state)),
            ])
            ->searchable()
            ->searchPlaceholder(trans('resources::resources.search_placeholder'))
            ->searchDebounce('750ms')
            ->searchOnBlur()
            ->recordActions([
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
                Action::make('filters')
                    ->label(trans('resources::resources.filters.label'))
                    ->icon('tabler-filter')
                    ->color('gray')
                    ->fillForm([
                        'source' => $this->source,
                        'projectType' => $this->projectType,
                    ])
                    ->schema([
                        Select::make('source')
                            ->label(trans('resources::resources.filters.platform'))
                            ->options($this->getEnabledPlatforms())
                            ->required()
                            ->native(false),
                        Select::make('projectType')
                            ->label(trans('resources::resources.filters.type'))
                            ->options([
                                'all' => trans('resources::resources.filters.type_all'),
                                'mod' => trans('resources::resources.filters.type_mod'),
                                'plugin' => trans('resources::resources.filters.type_plugin'),
                            ])
                            ->required()
                            ->native(false),
                    ])
                    ->action(function (array $data): void {
                        Record::clearCache($this->searchQuery ?? '', $this->projectType, $this->source);
                        $this->source = $data['source'];
                        $this->projectType = $data['projectType'] ?? 'all';
                        $this->cachedTotalCount = null;
                        $this->tablePage = 1;
                        $this->resetTable();
                    })
                    ->modalWidth('md'),
            ])
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateIcon('tabler-puzzle')
            ->emptyStateHeading(trans('resources::resources.empty_heading'))
            ->emptyStateDescription(trans('resources::resources.empty_description'));
    }

    protected function paginateTableQuery(Builder $query): PaginatorContract
    {
        $perPage = $this->getTableRecordsPerPage();
        $page = $this->getTablePage();
        // Keep the Livewire property in sync with Filament's internal page state.
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

        // CurseForge and Bukkit cannot be browsed without a CurseForge API key.
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
                'id' => $compositeId,
                'project_id' => (string) $project['id'],
                'slug' => $project['slug'],
                'name' => $project['name'],
                'description' => $project['description'] ?? '',
                'author' => $project['author'],
                'icon' => $project['icon'],
                'downloads' => $project['downloads'],
                'type' => $project['type'],
                'source' => $project['source'],
                'search_query' => $this->searchQuery ?? '',
                'project_type' => $this->projectType,
                'api_page' => $page,
                'api_index' => ($page - 1) * self::API_PAGE_SIZE + $index,
            ];
        }

        Record::writeCache($cache);
    }

    protected function fetchProjects(int $page): Collection
    {
        $sources = explode('-', $this->source);

        return collect($sources)
            ->flatMap(fn (string $source) => $this->fetchFromSingleSource($source, $page))
            ->unique(fn ($project) => $project['id'].'-'.$project['source'])
            ->sortByDesc('downloads')
            ->values();
    }

    protected function fetchFromSingleSource(string $source, int $page): Collection
    {
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
        return [
            'project_type' => $this->projectType === 'all' ? ['mod', 'plugin'] : $this->projectType,
        ];
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
        $results = $this->modrinthService->search(
            $this->searchQuery ?? '',
            $this->getModrinthFacets(),
            self::API_PAGE_SIZE,
            ($page - 1) * self::API_PAGE_SIZE,
        );

        return collect($results['hits'] ?? [])->map(fn ($hit) => [
            'id' => $hit['project_id'],
            'slug' => $hit['slug'],
            'name' => $hit['title'],
            'description' => $hit['description'],
            'author' => $hit['author'] ?? 'Unknown',
            'icon' => $hit['icon_url'] ?? null,
            'downloads' => $hit['downloads'] ?? 0,
            'type' => $hit['project_type'] ?? 'mod',
            'source' => 'modrinth',
        ]);
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

        return collect($results['data'] ?? [])->map(fn ($mod) => [
            'id' => (string) $mod['id'],
            'slug' => $mod['slug'],
            'name' => $mod['name'],
            'description' => $mod['summary'] ?? '',
            'author' => $mod['authors'][0]['name'] ?? 'Unknown',
            'icon' => $mod['logo']['thumbnailUrl'] ?? null,
            'downloads' => $mod['downloadCount'] ?? 0,
            'type' => ($mod['classId'] ?? null) === CurseForgeService::CLASS_BUKKIT_PLUGINS ? 'plugin' : 'mod',
            'source' => $source,
        ]);
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
    protected function getVersions(?string $projectId, string $source): array
    {
        if (empty($projectId)) {
            return [];
        }

        try {
            return match ($source) {
                'modrinth' => $this->modrinthService->getVersionOptions($projectId),
                'curseforge', 'bukkit' => $this->curseForgeService->getVersionOptions((int) $projectId),
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
