@props(['checked' => false])

<input type="checkbox" @checked($checked) {{ $attributes->merge(['class' => 'h-3.5 w-3.5 cursor-pointer rounded-[4px] border-ink-300 text-ink-950']) }}>
