<x-filament-panels::page>
    <div @if($this->pending()) wire:poll.3s="refreshProcessing" @endif>
        {{ $this->infolist }}
    </div>
</x-filament-panels::page>
