<x-filament-panels::page>
    <style>
        .rp-installed-card {
            display: flex;
            align-items: center;
            gap: 1rem;
            border-radius: 0.75rem;
            border: 1px solid rgb(229, 231, 235);
            background: rgb(255, 255, 255);
            padding: 0.875rem 1rem;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        }
        .dark .rp-installed-card {
            border-color: rgba(255, 255, 255, 0.1);
            background: rgba(255, 255, 255, 0.05);
            box-shadow: none;
        }
        .rp-installed-icon-placeholder {
            display: flex;
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 0.5rem;
            align-items: center;
            justify-content: center;
            background: rgb(243, 244, 246);
        }
        .dark .rp-installed-icon-placeholder {
            background: rgba(255, 255, 255, 0.1);
        }
        .rp-installed-name {
            font-size: 0.9375rem;
            font-weight: 600;
            color: rgb(3, 7, 18);
        }
        .dark .rp-installed-name {
            color: rgb(255, 255, 255);
        }
        .rp-installed-meta {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.75rem;
            margin-top: 0.25rem;
            font-size: 0.8125rem;
            color: rgb(107, 114, 128);
        }
        .dark .rp-installed-meta {
            color: rgb(156, 163, 175);
        }
        @keyframes rp-spin {
            from { transform: rotate(0deg); }
            to   { transform: rotate(360deg); }
        }
        .rp-spinning {
            animation: rp-spin 1s linear infinite;
        }
    </style>

    @php
        $installedModpacks = $this->getInstalledModpacks();
        $pendingInstall    = $this->getPendingInstall();
    @endphp

    @if($pendingInstall)
        <div
            class="rp-installed-card"
            x-data
            x-init="let i=setInterval(()=>$wire.$refresh(),3000);$cleanup(()=>clearInterval(i))"
            style="border-color:rgba(234,179,8,0.4);background:rgba(234,179,8,0.06);"
        >
            <div style="flex-shrink:0">
                @if(!empty($pendingInstall['icon_url']))
                    <img src="{{ $pendingInstall['icon_url'] }}" alt="{{ $pendingInstall['name'] }}"
                         style="width:2.5rem;height:2.5rem;object-fit:cover;border-radius:0.5rem;display:block;opacity:0.6"
                         onerror="this.style.display='none'">
                @else
                    <div class="rp-installed-icon-placeholder">
                        <x-filament::icon icon="tabler-loader-2" class="rp-spinning" style="width:1.25rem;height:1.25rem;opacity:.5;" />
                    </div>
                @endif
            </div>
            <div style="min-width:0;flex:1">
                <div style="display:flex;flex-wrap:wrap;align-items:center;gap:0.5rem">
                    <span class="rp-installed-name">{{ $pendingInstall['name'] }}</span>
                    <x-filament::badge color="warning" icon="tabler-loader-2">Installing…</x-filament::badge>
                </div>
                <div class="rp-installed-meta">
                    <span>Queued {{ \Carbon\Carbon::parse($pendingInstall['queued_at'])->diffForHumans() }} — downloading &amp; extracting files</span>
                </div>
            </div>
        </div>
    @endif

    @if($installedModpacks->isNotEmpty())
        <div style="display:flex;flex-direction:column;gap:0.75rem">
            @foreach($installedModpacks as $modpack)
                @php $updateVersion = $this->checkForUpdate($modpack); @endphp
                <div class="rp-installed-card">

                    {{-- Icon --}}
                    <div style="flex-shrink:0">
                        @if(!empty($modpack['icon_url']))
                            <img
                                src="{{ $modpack['icon_url'] }}"
                                alt="{{ $modpack['name'] }}"
                                style="width:2.5rem;height:2.5rem;object-fit:cover;border-radius:0.5rem;display:block"
                                onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='flex'"
                            >
                        @endif
                        <div class="rp-installed-icon-placeholder" style="{{ !empty($modpack['icon_url']) ? 'display:none' : '' }}">
                            <x-filament::icon icon="tabler-box" style="width:1.25rem;height:1.25rem;opacity:.4" />
                        </div>
                    </div>

                    {{-- Info --}}
                    <div style="min-width:0;flex:1">
                        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:0.5rem">
                            <span class="rp-installed-name">{{ $modpack['name'] }}</span>
                            @if(!empty($modpack['modloader']))
                                <x-filament::badge
                                    :color="match($modpack['modloader']) {
                                        'fabric'   => 'success',
                                        'forge'    => 'warning',
                                        'neoforge' => 'info',
                                        'quilt'    => 'primary',
                                        default    => 'gray',
                                    }"
                                >{{ ucfirst($modpack['modloader']) }}</x-filament::badge>
                            @endif
                            <x-filament::badge :color="($modpack['source'] ?? '') === 'modrinth' ? 'success' : 'warning'">
                                {{ ucfirst($modpack['source'] ?? '') }}
                            </x-filament::badge>
                        </div>

                        <div class="rp-installed-meta">
                            @if(!empty($modpack['version_name']))
                                <span style="display:flex;align-items:center;gap:0.25rem">
                                    <x-filament::icon icon="tabler-tag" style="width:0.875rem;height:0.875rem;flex-shrink:0" />
                                    {{ $modpack['version_name'] }}
                                </span>
                            @endif
                            @if(!empty($modpack['installed_at']))
                                <span style="display:flex;align-items:center;gap:0.25rem">
                                    <x-filament::icon icon="tabler-clock" style="width:0.875rem;height:0.875rem;flex-shrink:0" />
                                    {{ \Carbon\Carbon::parse($modpack['installed_at'])->diffForHumans() }}
                                </span>
                            @endif
                        </div>

                        @if($updateVersion)
                            <div style="margin-top:0.5rem">
                                <x-filament::badge color="warning" icon="tabler-arrow-up-circle">
                                    {{ trans('resources::resources.installed_modpacks.update_available', ['version' => $updateVersion]) }}
                                </x-filament::badge>
                            </div>
                        @endif
                    </div>

                    {{-- Update button --}}
                    @if($updateVersion)
                        <div style="flex-shrink:0">
                            <x-filament::button
                                color="warning"
                                icon="tabler-refresh"
                                wire:click="mountAction('updateModpack')"
                            >
                                {{ trans('resources::resources.installed_modpacks.update') }}
                            </x-filament::button>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
