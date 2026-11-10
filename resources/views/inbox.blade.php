<x-filament-panels::page>
    @include('filament-feedback::board', ['board' => $this->getBoard()])

    {{ $this->table }}
</x-filament-panels::page>
