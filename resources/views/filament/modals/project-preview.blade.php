@php
    $source      = $record->source ?? 'modrinth';
    $projectType = $record->type ?? 'modpack';
    $sourceLabel = ucfirst($source);

    $sourceBg = match($source) {
        'modrinth'   => '#1bd96a',
        'curseforge' => '#f76b1c',
        'spigot'     => '#ca8a04',
        'bukkit'     => '#ca8a04',
        default      => '#6b7280',
    };
    $sourceTextColor = $source === 'modrinth' ? '#064e3b' : '#ffffff';

    $updatedAt = $details['updated'] ?? $details['dateModified'] ?? $details['date_modified'] ?? null;
    $createdAt = $details['published'] ?? $details['dateCreated'] ?? $details['date_created'] ?? null;

    $links = [];
    if (!empty($details['source_url']))  $links['Source']  = $details['source_url'];
    if (!empty($details['wiki_url']))    $links['Wiki']    = $details['wiki_url'];
    if (!empty($details['issues_url']))  $links['Issues']  = $details['issues_url'];
    if (!empty($details['discord_url'])) $links['Discord'] = $details['discord_url'];

    // Modrinth returns categories as strings; CurseForge returns objects with a 'name' key.
    $cats = collect($details['categories'] ?? [])
        ->map(fn ($cat) => is_array($cat) ? ($cat['name'] ?? '') : (string) $cat)
        ->filter()
        ->values()
        ->all();
@endphp

<div style="margin-top:-0.5rem;" class="flex flex-col gap-4">

    {{-- ── Header card ──────────────────────────────────────────────────────── --}}
    <div class="rounded-xl border border-gray-200" style="overflow:hidden;">

        {{-- Dark top bar (intentionally dark in both modes) --}}
        <div style="background:#111827;padding:1rem;display:flex;align-items:flex-start;gap:0.75rem;">

            {{-- Icon: inline styles required — w-10/h-10/object-cover not compiled --}}
            @if($record->icon)
                <img
                    src="{{ $record->icon }}"
                    alt="{{ $record->name }}"
                    width="40" height="40"
                    style="width:40px;height:40px;min-width:40px;object-fit:cover;border-radius:6px;margin-top:2px;outline:1px solid rgba(255,255,255,0.1);flex-shrink:0;"
                    onerror="this.style.display='none'"
                >
            @else
                <div style="width:40px;height:40px;min-width:40px;border-radius:6px;background:#374151;display:flex;align-items:center;justify-content:center;margin-top:2px;flex-shrink:0;">
                    <x-heroicon-o-puzzle-piece style="width:20px;height:20px;color:#9ca3af;" />
                </div>
            @endif

            {{-- Name / author / badges -- min-width:0 required, not compiled --}}
            <div class="flex-1" style="min-width:0;">
                <p class="truncate" style="color:#ffffff;font-weight:600;font-size:0.875rem;line-height:1.25rem;">
                    {{ $record->name }}
                </p>
                <p style="color:#9ca3af;font-size:0.75rem;margin-top:2px;">by {{ $record->author }}</p>

                <div style="display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-top:8px;">
                    <span :style="`display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:4px;font-size:.75rem;font-weight:500;background:{{ $sourceBg }};color:{{ $sourceTextColor }};`">
                        {{ $sourceLabel }}
                    </span>
                    @if($projectType === 'modpack')
                        <span style="padding:2px 8px;border-radius:4px;font-size:0.75rem;font-weight:500;background:#6d28d9;color:#ede9fe;">Modpack</span>
                    @elseif($projectType === 'mod')
                        <span style="padding:2px 8px;border-radius:4px;font-size:0.75rem;font-weight:500;background:#15803d;color:#dcfce7;">Mod</span>
                    @elseif($projectType === 'plugin')
                        <span style="padding:2px 8px;border-radius:4px;font-size:0.75rem;font-weight:500;background:#1d4ed8;color:#dbeafe;">Plugin</span>
                    @elseif($projectType)
                        <span style="padding:2px 8px;border-radius:4px;font-size:0.75rem;font-weight:500;background:#374151;color:#e5e7eb;">{{ ucfirst($projectType) }}</span>
                    @endif
                    @foreach(array_slice($cats, 0, 4) as $cat)
                        <span style="padding:2px 8px;border-radius:4px;font-size:0.75rem;font-weight:500;background:#374151;color:#d1d5db;">
                            {{ ucwords(str_replace('-', ' ', $cat)) }}
                        </span>
                    @endforeach
                </div>
            </div>

            {{-- External link --}}
            @if(!empty($details['_url']))
                <a href="{{ $details['_url'] }}" target="_blank" rel="noopener noreferrer"
                   title="View on {{ $sourceLabel }}"
                   style="flex-shrink:0;padding:6px;border-radius:6px;background:#374151;color:#d1d5db;text-decoration:none;display:flex;align-items:center;">
                    <x-heroicon-o-arrow-top-right-on-square style="width:16px;height:16px;" />
                </a>
            @endif
        </div>

        {{-- Stats bar — no background set, inherits Filament modal color (auto dark/light) --}}
        <div class="flex flex-wrap items-center gap-4 px-4 border-t border-gray-200 text-xs text-gray-500" style="padding-top:0.625rem;padding-bottom:0.625rem;">
            <span class="flex items-center gap-1">
                <x-heroicon-o-arrow-down-tray style="width:13px;height:13px;" />
                <strong>{{ number_format($record->downloads) }}</strong>&nbsp;downloads
            </span>
            @if(!empty($details['followers']))
                <span class="flex items-center gap-1">
                    <x-heroicon-o-star style="width:13px;height:13px;" />
                    <strong>{{ number_format($details['followers']) }}</strong>&nbsp;followers
                </span>
            @endif
            @if($updatedAt)
                <span class="flex items-center gap-1">
                    <x-heroicon-o-arrow-path style="width:13px;height:13px;" />
                    Updated {{ \Carbon\Carbon::parse($updatedAt)->diffForHumans() }}
                </span>
            @endif
            @if($createdAt)
                <span class="flex items-center gap-1">
                    <x-heroicon-o-calendar-days style="width:13px;height:13px;" />
                    {{ \Carbon\Carbon::parse($createdAt)->format('M Y') }}
                </span>
            @endif
        </div>
    </div>

    {{-- ── Description — max-h-48 not compiled, use inline style --}}
    @if(!empty($details['body']) || !empty($record->description) || !empty($details['summary']))
        <div class="overflow-y-auto rounded-xl border border-gray-200" style="height:12rem;min-height:4rem;resize:vertical;">
            <div style="padding:0.75rem;font-size:0.875rem;line-height:1.625;">
                @if(!empty($details['body']))
                    @if(!empty($details['_body_is_html']))
                        @php
                            // Strip dangerous tags but keep structural/media ones.
                            // Then remove leftover style/class/on* attributes to keep it clean.
                            $html = strip_tags(
                                $details['body'],
                                '<p><br><b><i><em><strong><ul><ol><li><h1><h2><h3><h4><h5><h6><code><pre><blockquote><a><img><figure><figcaption><table><thead><tbody><tr><th><td><hr><span><div>'
                            );
                            // Remove style=, class=, on* event attributes from remaining tags.
                            $html = preg_replace('/\s(style|class|on\w+)="[^"]*"/i', '', $html);
                            // Make images responsive.
                            $html = preg_replace('/<img([^>]*)>/i', '<img$1 style="max-width:100%;height:auto;border-radius:4px;margin:4px 0;">', $html);
                        @endphp
                        {!! $html !!}
                    @else
                        {!! \Illuminate\Support\Str::markdown(
                            strip_tags($details['body'], '<b><i><em><strong><ul><ol><li><p><br><h1><h2><h3><h4><code><pre>'),
                            ['html_input' => 'strip', 'allow_unsafe_links' => false]
                        ) !!}
                    @endif
                @elseif(!empty($details['summary']))
                    {{ $details['summary'] }}
                @elseif(!empty($record->description))
                    {{ $record->description }}
                @endif
            </div>
        </div>
    @endif

    {{-- ── Links ──────────────────────────────────────────────────────────── --}}
    @if(!empty($links))
        <div class="flex flex-wrap gap-2">
            @foreach($links as $label => $url)
                <a href="{{ $url }}" target="_blank" rel="noopener noreferrer"
                   class="flex items-center gap-1 border border-gray-200 text-xs rounded text-gray-500"
                   style="padding:4px 10px;text-decoration:none;">
                    <x-heroicon-o-link style="width:12px;height:12px;" />{{ $label }}
                </a>
            @endforeach
        </div>
    @endif

    {{-- ── Divider before Filament-injected form fields ─────────────────── --}}
    <div class="border-t border-gray-200" style="padding-top:0.25rem;">
        <p class="text-xs font-medium text-gray-500 uppercase" style="letter-spacing:0.05em;margin-bottom:0.5rem;">
            {{ $projectType === 'modpack' ? 'Install this modpack' : 'Download this ' . ($projectType ?: 'mod') }}
        </p>
    </div>

</div>
