<x-filament-panels::page>
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-4">
        <div class="flex flex-col gap-4">
            @forelse ($messages as $m)
                @php($isUser = ($m['role'] ?? '') === 'user')
                <div @class([
                    'flex items-start gap-2.5',
                    'flex-row-reverse' => $isUser,
                ])>
                    <div @class([
                        'flex h-8 w-8 shrink-0 items-center justify-center rounded-full',
                        'bg-primary-600 text-white' => $isUser,
                        'bg-gray-100 text-gray-500 ring-1 ring-gray-950/5 dark:bg-white/10 dark:text-gray-300 dark:ring-white/10' => ! $isUser,
                    ])>
                        <x-filament::icon
                            :icon="$isUser ? 'heroicon-m-user' : 'heroicon-m-sparkles'"
                            class="h-4 w-4"
                        />
                    </div>

                    @if ($isUser)
                        <div class="max-w-[85%] whitespace-pre-wrap rounded-2xl rounded-tr-sm bg-primary-600 px-4 py-2.5 text-sm leading-relaxed text-white">{{ $m['content'] }}</div>
                    @else
                        <div class="prose prose-sm max-w-[85%] rounded-2xl rounded-tl-sm bg-gray-50 px-4 py-3 text-sm leading-relaxed text-gray-950 ring-1 ring-gray-950/5 dark:prose-invert dark:bg-white/5 dark:text-white dark:ring-white/10 prose-p:my-2 prose-p:first:mt-0 prose-p:last:mb-0 prose-ul:my-2 prose-li:my-0.5 prose-headings:mb-2 prose-headings:mt-3">
                            {!! \Illuminate\Support\Str::markdown($m['content'], ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                        </div>
                    @endif
                </div>
            @empty
                <div class="py-12 text-center">
                    <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-400">
                        <x-filament::icon icon="heroicon-o-sparkles" class="h-6 w-6" />
                    </div>
                    <p class="text-base font-semibold text-gray-700 dark:text-gray-200">Vraag het de assistent</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Bijvoorbeeld: "Hoeveel omzet vandaag?" of "Wat moet ik bijbestellen?"</p>
                </div>
            @endforelse

            <div wire:loading wire:target="send" class="flex items-center gap-2 self-start text-sm text-gray-500 dark:text-gray-400">
                <x-filament::loading-indicator class="h-4 w-4" />
                Denkt na…
            </div>
        </div>

        <form wire:submit="send" class="sticky bottom-4 flex items-end gap-2 rounded-2xl bg-white/80 p-2 ring-1 ring-gray-950/5 backdrop-blur dark:bg-gray-900/80 dark:ring-white/10">
            <textarea
                wire:model="question"
                rows="2"
                placeholder="Stel een vraag…"
                wire:keydown.enter.prevent="send"
                class="flex-1 resize-none rounded-xl border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white"
            ></textarea>

            <x-filament::button type="submit" icon="heroicon-m-paper-airplane" wire:loading.attr="disabled" wire:target="send">
                Vraag
            </x-filament::button>
        </form>
    </div>
</x-filament-panels::page>
