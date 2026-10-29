@props(['messages'])

@if ($messages)
    <ul {{ $attributes->merge(['class' => 'space-y-0.5 text-xs text-red-600']) }}>
        @foreach ((array) $messages as $message)
            <li>{{ $message }}</li>
        @endforeach
    </ul>
@endif
