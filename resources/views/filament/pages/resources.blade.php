<x-filament-panels::page>
    <div wire:init="loadUpdatableFiles">

        <div style="display:flex;justify-content:center;margin-bottom:1rem;">
            <x-filament::tabs style="width:fit-content;">
                <x-filament::tabs.item
                    :active="$mode === 'remote'"
                    wire:click="switchMode('remote')"
                    icon="heroicon-o-globe-alt"
                >
                    {{ trans('resources::resources.view.remote') }}
                </x-filament::tabs.item>

                <x-filament::tabs.item
                    :active="$mode === 'installed'"
                    wire:click="switchMode('installed')"
                    icon="heroicon-o-archive-box"
                >
                    {{ trans('resources::resources.view.installed') }}
                </x-filament::tabs.item>
            </x-filament::tabs>
        </div>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
