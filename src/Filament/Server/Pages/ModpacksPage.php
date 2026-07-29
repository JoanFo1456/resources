<?php

namespace JoanFo\Resources\Filament\Server\Pages;

use App\Models\Server;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\ImageColumn;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\Paginator as PaginatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JoanFo\Resources\Jobs\InstallModpackJob;
use JoanFo\Resources\Models\InstalledModpackCache;
use JoanFo\Resources\Models\PendingModpackCache;
use JoanFo\Resources\Models\Modpack;
use JoanFo\Resources\Services\CurseForgeService;
use JoanFo\Resources\Services\ModrinthService;
use RuntimeException;

class ModpacksPage extends Page implements HasTable
{
    use InteractsWithTable;

    /** Page size used against the upstream APIs (CurseForge's maximum). */
    protected const API_PAGE_SIZE = 50;

    protected string $view = 'resources::filament.pages.modpacks';

    protected static ?string $slug = 'modpacks';

    protected static ?int $navigationSort = 11;

    public string $source = 'modrinth';

    public ?string $searchQuery = '';

    public int $tablePage = 1;

    public bool $viewAsGrid = false;

    private ?int $cachedTotalCount = null;

    protected ModrinthService $modrinthService;

    protected CurseForgeService $curseForgeService;

    public function boot(ModrinthService $modrinthService, CurseForgeService $curseForgeService): void
    {
        $this->modrinthService = $modrinthService;
        $this->curseForgeService = $curseForgeService;
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
    }

    public function getTitle(): string
    {
        return trans('resources::resources.modpacks.title');
    }

    public static function getNavigationLabel(): string
    {
        return trans('resources::resources.modpacks.navigation');
    }

    public function getHeading(): string
    {
        return trans('resources::resources.modpacks.heading');
    }

    public static function getNavigationIcon(): string
    {
        return 'tabler-box';
    }

    public function updatedSource(): void
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
        Modpack::clearCache($this->searchQuery, $this->source);
        $this->searchQuery = $this->getTableSearch();
        $this->tablePage = 1;
        $this->cachedTotalCount = null;
        $this->resetTable();
    }

    public function updatedViewAsGrid(): void
    {
        $this->resetTable();
    }

    public function getInstalledModpacks(): Collection
    {
        $serverId = Filament::getTenant()->id;
        $modpack  = InstalledModpackCache::get($serverId);

        if (!$modpack) {
            return collect();
        }

        if (empty($modpack['icon_url']) && ($modpack['source'] ?? '') === 'modrinth' && !empty($modpack['project_id'])) {
            $icon = $this->modrinthService->getProjectIcon($modpack['project_id']);
            if ($icon) {
                $modpack['icon_url'] = $icon;
                InstalledModpackCache::put($serverId, $modpack);
            }
        }

        return collect([$modpack]);
    }

    /** Returns the latest version name if a newer version is available, null otherwise. */
    public function checkForUpdate(array $modpack): ?string
    {
        $source = $modpack['source'] ?? '';
        if (empty($modpack['project_id'])) {
            return null;
        }

        try {
            if ($source === 'modrinth') {
                $versions = $this->modrinthService->getProjectVersions($modpack['project_id']);
                if (empty($versions)) {
                    return null;
                }
                $latest = $versions[0];
                if (($latest['id'] ?? null) === ($modpack['version_id'] ?? null)) {
                    return null;
                }

                return ($latest['name'] ?? 'Unknown')
                    .' ('.implode(', ', array_slice($latest['game_versions'] ?? [], 0, 2)).')';
            }

            if ($source === 'curseforge') {
                $latest = $this->latestCurseForgeFile((int) $modpack['project_id']);
                if (!$latest) {
                    return null;
                }
                if ((string) ($latest['id'] ?? '') === (string) ($modpack['version_id'] ?? '')) {
                    return null;
                }

                return $this->curseForgeFileLabel($latest);
            }
        } catch (\Exception) {
            return null;
        }

        return null;
    }

    /** Newest CurseForge file (by upload date) for a project, or null. */
    private function latestCurseForgeFile(int $projectId): ?array
    {
        return collect($this->curseForgeService->getModFiles($projectId))
            ->sortByDesc('fileDate')
            ->first();
    }

    /** Human label for a CurseForge file: display name + up to two numeric game versions. */
    private function curseForgeFileLabel(array $file): string
    {
        $gv = collect($file['gameVersions'] ?? [])
            ->filter(fn ($v) => preg_match('/^\d+\.\d+/', $v))
            ->take(2)
            ->implode(', ');

        return ($file['displayName'] ?? 'Unknown').($gv ? ' ('.$gv.')' : '');
    }

    public function updateModpackAction(): Action
    {
        return Action::make('updateModpack')
            ->label(trans('resources::resources.installed_modpacks.update'))
            ->color('warning')
            ->icon('tabler-refresh')
            ->schema(fn () => [
                Select::make('version_id')
                    ->label(trans('resources::resources.download.version'))
                    ->options(fn () => $this->updateVersionOptions())
                    ->searchable()
                    ->native(false)
                    ->required(),
            ])
            ->action(fn (array $data) => $this->doUpdateModpack($data['version_id']))
            ->modalHeading(fn () =>
                trans('resources::resources.installed_modpacks.update_confirm', [
                    'name' => InstalledModpackCache::get(Filament::getTenant()->id)['name'] ?? '',
                ])
            )
            ->modalSubmitActionLabel(trans('resources::resources.installed_modpacks.update'))
            ->modalWidth('md');
    }

    /** Version dropdown options for the currently installed modpack, per its source. */
    protected function updateVersionOptions(): array
    {
        $modpack = InstalledModpackCache::get(Filament::getTenant()->id);
        if (!$modpack || empty($modpack['project_id'])) {
            return [];
        }

        $options = ($modpack['source'] ?? '') === 'curseforge'
            ? $this->curseForgeService->getVersionOptions((int) $modpack['project_id'])
            : $this->modrinthService->getVersionOptions($modpack['project_id']);

        return collect($options)->pluck('name', 'id')->all();
    }

    public function doUpdateModpack(string $versionId): void
    {
        $serverId = Filament::getTenant()->id;
        $modpack  = InstalledModpackCache::get($serverId);

        if (!$modpack || !in_array($modpack['source'] ?? '', ['modrinth', 'curseforge'], true)) {
            return;
        }

        try {
            if (($modpack['source'] ?? '') === 'curseforge') {
                $file = collect($this->curseForgeService->getModFiles((int) $modpack['project_id']))
                    ->first(fn ($f) => (string) ($f['id'] ?? '') === (string) $versionId) ?? [];
                $latestName = $file ? $this->curseForgeFileLabel($file) : '';
            } else {
                $versions   = $this->modrinthService->getProjectVersions($modpack['project_id']);
                $version    = collect($versions)->firstWhere('id', $versionId) ?? [];
                $latestName = ($version['name'] ?? '')
                    .' ('.implode(', ', array_slice($version['game_versions'] ?? [], 0, 2)).')';
            }

            InstallModpackJob::dispatch(
                server: Filament::getTenant(),
                source: $modpack['source'],
                versionId: $versionId,
                projectId: $modpack['project_id'],
                deleteFiles: false,
                modpackName: $modpack['name'],
                versionName: $latestName,
                iconUrl: $modpack['icon_url'] ?? null,
            );

            InstalledModpackCache::put($serverId, array_merge($modpack, [
                'version_id'   => $versionId,
                'version_name' => $latestName,
            ]));

            Notification::make()
                ->info()
                ->title(trans('resources::resources.modpacks.queued'))
                ->body(trans('resources::resources.modpacks.queued_body', ['name' => $modpack['name']]))
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->danger()
                ->title(trans('resources::resources.modpacks.failed'))
                ->body($e->getMessage())
                ->send();
        }
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
            Modpack::clearCache($this->searchQuery, $this->source);
        }

        $table = $table
            ->query(fn () => Modpack::query()
                ->where('search_query', $this->searchQuery ?? '')
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
                    ->description(fn (Modpack $record) => Str::limit($record->description ?? '', 80)),
                TextColumn::make('author')
                    ->label(trans('resources::resources.table.author')),
                TextColumn::make('downloads')
                    ->label(trans('resources::resources.table.downloads'))
                    ->sortable()
                    ->formatStateUsing(fn ($state) => number_format($state)),
                TextColumn::make('source')
                    ->label(trans('resources::resources.table.source'))
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'curseforge' => 'warning',
                        'modrinth'   => 'success',
                        default      => 'gray',
                    })
                    ->icon(fn ($state) => match ($state) {
                        'modrinth'   => 'tabler-leaf',
                        'curseforge' => 'tabler-flame',
                        default      => 'tabler-package',
                    })
                    ->formatStateUsing(fn ($state) => ucfirst($state)),
            ])
            ->searchable()
            ->searchPlaceholder(trans('resources::resources.modpacks.search_placeholder'))
            ->searchDebounce('750ms')
            ->searchOnBlur()
            ->recordAction('preview')
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
                    ->fillForm(fn () => ['source' => $this->source])
                    ->schema([
                        Select::make('source')
                            ->label(trans('resources::resources.filters.platform'))
                            ->options(fn () => $this->getEnabledPlatforms())
                            ->required()
                            ->native(false),
                    ])
                    ->action(function (array $data): void {
                        $oldSource = $this->source;
                        $this->source = $data['source'];
                        Modpack::clearCache($this->searchQuery ?? '', $oldSource);
                        $this->cachedTotalCount = null;
                        $this->tablePage = 1;
                        $this->flushCachedTableRecords();
                        $this->dispatch('$refresh');
                    })
                    ->modalWidth('md'),
            ])
            ->recordActions([
                Action::make('preview')
                    ->label(trans('resources::resources.preview.action'))
                    ->icon('tabler-eye')
                    ->color('gray')
                    ->modalContent(fn (Modpack $record) => view('resources::filament.modals.project-preview', [
                        'record'  => $record,
                        'details' => $this->fetchModpackPreview($record),
                    ]))
                    ->schema(fn (Modpack $record) => [
                        Select::make('version')
                            ->label(trans('resources::resources.download.version'))
                            ->placeholder(trans('resources::resources.download.version_placeholder'))
                            ->options(collect($this->getVersions($record->project_id, $record->source))->pluck('name', 'id'))
                            ->required()
                            ->searchable()
                            ->native(false),
                        Checkbox::make('delete_files')
                            ->label(trans('resources::resources.modpacks.delete_files'))
                            ->helperText(trans('resources::resources.modpacks.delete_files_help'))
                            ->default(false),
                    ])
                    ->action(fn (array $data, Modpack $record) => $this->installModpack($record, $data['version'], $data['delete_files'] ?? false))
                    ->modalSubmitActionLabel(trans('resources::resources.modpacks.install'))
                    ->modalCancelActionLabel(trans('resources::resources.preview.close'))
                    ->modalWidth('3xl')
                    ->modalHeading(''),
                Action::make('install')
                    ->label(trans('resources::resources.modpacks.install'))
                    ->icon('tabler-download')
                    ->color('primary')
                    ->schema(fn (Modpack $record) => [
                        Select::make('version')
                            ->label(trans('resources::resources.download.version'))
                            ->placeholder(trans('resources::resources.download.version_placeholder'))
                            ->options(collect($this->getVersions($record->project_id, $record->source))->pluck('name', 'id'))
                            ->required()
                            ->searchable()
                            ->native(false),
                        Checkbox::make('delete_files')
                            ->label(trans('resources::resources.modpacks.delete_files'))
                            ->helperText(trans('resources::resources.modpacks.delete_files_help'))
                            ->default(false),
                    ])
                    ->action(fn (array $data, Modpack $record) => $this->installModpack($record, $data['version'], $data['delete_files'] ?? false))
                    ->modalWidth('md')
                    ->modalHeading(fn (Modpack $record) => trans('resources::resources.modpacks.install_heading', ['name' => $record->name])),
            ])
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateIcon('tabler-box')
            ->emptyStateHeading(trans('resources::resources.modpacks.empty_heading'))
            ->emptyStateDescription(trans('resources::resources.empty_description'));

        if ($this->viewAsGrid) {
            $table = $table
                ->columns([
                    Stack::make([
                        Split::make([
                            ImageColumn::make('icon')
                                ->label('')
                                ->defaultImageUrl('/images/egg-default.png')
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
                                    ->size(TextSize::Small),
                            ]),
                        ]),
                        TextColumn::make('description')
                            ->label('')
                            ->wrap()
                            ->color('gray')
                            ->size(TextSize::Small)
                            ->limit(120),
                        Split::make([
                            TextColumn::make('downloads')
                                ->label('')
                                ->icon('tabler-download')
                                ->formatStateUsing(fn ($state) => number_format($state))
                                ->color('gray')
                                ->size(TextSize::Small),
                            TextColumn::make('source')
                                ->label('')
                                ->badge()
                                ->color(fn ($state) => match ($state) {
                                    'curseforge' => 'warning',
                                    'modrinth'   => 'success',
                                    default      => 'gray',
                                })
                                ->icon(fn ($state) => match ($state) {
                                    'modrinth'   => 'tabler-leaf',
                                    'curseforge' => 'tabler-flame',
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

    protected function paginateTableQuery(Builder $_query): PaginatorContract
    {
        $perPage = $this->getTableRecordsPerPage();
        $page = $this->getTablePage();
        $this->tablePage = $page;

        $startRecord = ($page - 1) * $perPage;
        $endApiPage = (int) floor(($startRecord + $perPage - 1) / self::API_PAGE_SIZE) + 1;

        for ($apiPage = 1; $apiPage <= $endApiPage; $apiPage++) {
            if (!Modpack::isPageCached($this->searchQuery ?? '', $this->source, $apiPage)) {
                $this->fetchAndCachePage($apiPage);
            }
        }

        $sources = explode('-', $this->source);
        $searchQuery = $this->searchQuery ?? '';

        $results = collect(Modpack::readCache())
            ->filter(fn ($record) => ($record['search_query'] ?? '') === $searchQuery
                && in_array($record['source'] ?? '', $sources, true))
            ->sortBy(fn ($record) => $record['page_number'] ?? 0)
            ->slice($startRecord, $perPage)
            ->map(function (array $attributes) {
                $modpack = new Modpack();
                $modpack->setRawAttributes($attributes);
                $modpack->exists = true;
                $modpack->syncOriginal();

                return $modpack;
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
        $platforms = [];

        if (config('resources.platforms.modrinth')) {
            $platforms['modrinth'] = 'Modrinth';
        }

        if (config('resources.platforms.curseforge') && $this->curseForgeService->isConfigured()) {
            $platforms['curseforge'] = 'CurseForge';
        }

        if (isset($platforms['modrinth'], $platforms['curseforge'])) {
            $platforms['modrinth-curseforge'] = 'Modrinth & CurseForge';
        }

        return $platforms;
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
                'modrinth' => $this->modrinthService->getTotalCount($searchQuery, ['project_type' => 'modpack']),
                'curseforge' => $this->curseForgeService->getTotalCount($searchQuery, CurseForgeService::CLASS_MODPACKS),
                default => 0,
            };
        } catch (RuntimeException $e) {
            $this->notifyCurseForgeError($e);

            return 0;
        }
    }

    protected function fetchAndCachePage(int $page): void
    {
        $cache = Modpack::readCache();

        foreach ($this->fetchModpacks($page) as $modpack) {
            $compositeId = md5($modpack['id'].($this->searchQuery ?? '').$modpack['source'].$page);

            $cache[$compositeId] = [
                'id' => $compositeId,
                'project_id' => (string) $modpack['id'],
                'slug' => $modpack['slug'],
                'name' => $modpack['name'],
                'description' => $modpack['description'] ?? '',
                'author' => $modpack['author'],
                'icon' => $modpack['icon'],
                'downloads' => $modpack['downloads'],
                'source' => $modpack['source'],
                'search_query' => $this->searchQuery ?? '',
                'page_number' => $page,
            ];
        }

        Modpack::writeCache($cache);
    }

    protected function fetchModpacks(int $page): Collection
    {
        $sources = explode('-', $this->source);

        return collect($sources)
            ->flatMap(fn (string $source) => match ($source) {
                'modrinth' => $this->fetchModrinthModpacks($page),
                'curseforge' => $this->fetchCurseForgeModpacks($page),
                default => collect(),
            })
            ->sortByDesc('downloads')
            ->values();
    }

    protected function fetchModrinthModpacks(int $page): Collection
    {
        $results = $this->modrinthService->search(
            $this->searchQuery ?? '',
            ['project_type' => 'modpack'],
            self::API_PAGE_SIZE,
            ($page - 1) * self::API_PAGE_SIZE,
        );

        return collect($results['hits'] ?? [])->map(fn ($hit) => [
            'id' => $hit['project_id'],
            'slug' => $hit['slug'],
            'name' => $hit['title'],
            'description' => $hit['description'] ?? '',
            'author' => $hit['author'] ?? 'Unknown',
            'icon' => $hit['icon_url'] ?? '',
            'downloads' => $hit['downloads'] ?? 0,
            'source' => 'modrinth',
        ]);
    }

    protected function fetchCurseForgeModpacks(int $page): Collection
    {
        if (!$this->curseForgeService->isConfigured()) {
            return collect();
        }

        try {
            $results = $this->curseForgeService->search(
                $this->searchQuery ?? '',
                CurseForgeService::CLASS_MODPACKS,
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
            'icon' => $mod['logo']['url'] ?? '',
            'downloads' => $mod['downloadCount'] ?? 0,
            'source' => 'curseforge',
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
                'curseforge' => $this->curseForgeService->getVersionOptions((int) $projectId),
                default => [],
            };
        } catch (RuntimeException $e) {
            $this->notifyCurseForgeError($e);

            return [];
        }
    }

    /**
     * Fetch modpack metadata from the source API for the preview modal.
     *
     * @return array<string, mixed>
     */
    private function fetchModpackPreview(Modpack $modpack): array
    {
        try {
            if ($modpack->source === 'modrinth') {
                $response = Http::withUserAgent('ResourcesPlugin')
                    ->acceptJson()
                    ->timeout(10)
                    ->get(config('resources.modrinth_api_url') . '/project/' . $modpack->project_id);

                if ($response->successful()) {
                    $data = $response->json();
                    $data['_url']    = 'https://modrinth.com/modpack/' . ($data['slug'] ?? $modpack->project_id);
                    $data['_source'] = 'modrinth';

                    return $data;
                }
            } elseif ($modpack->source === 'curseforge') {
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
                        ->get($cfBaseUrl . '/mods/' . $modpack->project_id),
                    $pool->as('desc')
                        ->withHeader('x-api-key', $cfApiKey)
                        ->acceptJson()
                        ->timeout(10)
                        ->get($cfBaseUrl . '/mods/' . $modpack->project_id . '/description'),
                ]);

                if ($modResponse->successful()) {
                    $data = $modResponse->json()['data'] ?? [];
                    $data['_url']          = 'https://www.curseforge.com/minecraft/modpacks/' . ($data['slug'] ?? $modpack->project_id);
                    $data['_source']       = 'curseforge';
                    $data['_body_is_html'] = true;
                    $data['body']          = $descResponse->successful() ? ($descResponse->json()['data'] ?? null) : null;

                    return $data;
                }
            }
        } catch (Exception) {
        }

        return ['_url' => null, '_source' => $modpack->source];
    }

    public function getPendingInstall(): ?array
    {
        $serverId = Filament::getTenant()->id;
        $pending  = PendingModpackCache::get($serverId);

        if ($pending !== null) {
            $installed   = InstalledModpackCache::get($serverId);
            $queuedAt    = $pending['queued_at'] ?? null;
            $installedAt = $installed['installed_at'] ?? null;

            if ($installedAt && $queuedAt && $installedAt >= $queuedAt) {
                PendingModpackCache::remove($serverId);
                return null;
            }
        }

        return $pending;
    }

    protected function installModpack(Modpack $modpack, string $versionId, bool $deleteFiles): void
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        $versions = $this->getVersions($modpack->project_id, $modpack->source);
        $versionName = collect($versions)->firstWhere('id', $versionId)['name'] ?? null;

        PendingModpackCache::put($server->id, [
            'name'      => $modpack->name,
            'icon_url'  => $modpack->icon ?: null,
            'source'    => $modpack->source,
            'queued_at' => now()->toISOString(),
        ]);

        InstallModpackJob::dispatch(
            server: $server,
            source: $modpack->source,
            versionId: $versionId,
            projectId: $modpack->project_id,
            deleteFiles: $deleteFiles,
            modpackName: $modpack->name,
            versionName: $versionName,
            iconUrl: $modpack->icon ?: null,
        );

        Notification::make()
            ->info()
            ->title(trans('resources::resources.modpacks.queued'))
            ->body(trans('resources::resources.modpacks.queued_body', ['name' => $modpack->name]))
            ->send();
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
