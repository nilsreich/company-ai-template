<x-filament-panels::page>
    <div @if($this->pending()) wire:poll.3s="refreshProcessing" @endif>
        {{ $this->infolist }}
    </div>
    <x-filament::section heading="Änderungshistorie (letzte 100 Ereignisse)">
        @forelse($this->auditHistory() as $entry)
            <details class="border-b border-gray-200 py-3 dark:border-white/10" wire:key="audit-{{ $entry->id }}">
                <summary class="cursor-pointer font-medium">#{{ $entry->chain_position }} · {{ $entry->created_at->format('d.m.Y. H:i:s') }} · {{ $entry->action }} · {{ $entry->user_id ? 'Benutzer #'.$entry->user_id : 'System' }}</summary>
                <pre class="mt-2 overflow-x-auto whitespace-pre-wrap break-words rounded bg-gray-50 p-3 text-xs dark:bg-white/5">{{ json_encode($entry->changes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                <small class="text-gray-500 dark:text-gray-400">SHA-256: {{ $entry->entry_hash }}</small>
            </details>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">Noch keine Änderungen protokolliert.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
