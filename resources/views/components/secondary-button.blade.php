<button {{ $attributes->merge(['type' => 'button', 'class' => 'btn btn-default']) }}>
    {{ $slot }}
</button>
