<x-filament-panels::page>
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-4">
        <div class="flex flex-col gap-3">
            @forelse ($messages as $m)
                <div @class([
                    'max-w-[85%] whitespace-pre-wrap rounded-2xl px-4 py-2.5 text-sm leading-relaxed',
                    'self-end bg-primary-600 text-white' => ($m['role'] ?? '') === 'user',
                    'self-start bg-gray-100 text-gray-950 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-white dark:ring-white/10' => ($m['role'] ?? '') !== 'user',
                ])>
                    {{ $m['content'] }}
                </div>
            @empty
                <div class="py-10 text-center">
                    <p class="text-base font-semibold text-gray-700 dark:text-gray-200">Vraag het de assistent</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Bijvoorbeeld: "Hoeveel omzet vandaag?" of "Wat moet ik bijbestellen?"</p>
                </div>
            @endforelse

            <div wire:loading wire:target="send" class="self-start text-sm text-gray-500 dark:text-gray-400">
                Denkt na…
            </div>
        </div>

        <form wire:submit="send" class="sticky bottom-4 flex items-end gap-2">
            <textarea
                wire:model="question"
                rows="2"
                placeholder="Stel een vraag…"
                class="flex-1 rounded-xl border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white"
            ></textarea>

            <x-filament::button type="submit" icon="heroicon-m-paper-airplane" wire:loading.attr="disabled" wire:target="send">
                Vraag
            </x-filament::button>
        </form>
    </div>
</x-filament-panels::page>
