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
use JoanFo\Resources\Jobs\InstallModpackJob;
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

        return $table
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
                        'modrinth' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => ucfirst($state)),
            ])
            ->searchable()
            ->searchPlaceholder(trans('resources::resources.modpacks.search_placeholder'))
            ->searchDebounce('750ms')
            ->searchOnBlur()
            ->headerActions([
                Action::make('filters')
                    ->label(trans('resources::resources.filters.label'))
                    ->icon('tabler-filter')
                    ->color('gray')
                    ->fillForm([
                        'source' => $this->source,
                    ])
                    ->schema([
                        Select::make('source')
                            ->label(trans('resources::resources.filters.platform'))
                            ->options($this->getEnabledPlatforms())
                            ->required()
                            ->native(false),
                    ])
                    ->action(function (array $data): void {
                        Modpack::clearCache($this->searchQuery ?? '', $this->source);
                        $this->source = $data['source'];
                        $this->cachedTotalCount = null;
                        $this->tablePage = 1;
                        $this->resetTable();
                    })
                    ->modalWidth('md'),
            ])
            ->recordActions([
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
            // Key on the individual source so the same modpack can exist
            // under both Modrinth and CurseForge.
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

    protected function installModpack(Modpack $modpack, string $versionId, bool $deleteFiles): void
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        InstallModpackJob::dispatch(
            server: $server,
            source: $modpack->source,
            versionId: $versionId,
            projectId: $modpack->project_id,
            deleteFiles: $deleteFiles,
            modpackName: $modpack->name,
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
