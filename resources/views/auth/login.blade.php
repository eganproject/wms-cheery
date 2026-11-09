<x-guest-layout title="Masuk" heading="Masuk ke WMS" subheading="Gunakan akun yang diberikan admin gudang.">

    <x-auth-session-status class="mb-6" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf

        <x-ui.field label="Email" for="email" :error="$errors->get('email')" required>
            <div class="relative">
                <span class="pointer-events-none absolute inset-y-0 left-0 flex w-8 items-center justify-center text-ink-500">
                    <x-icon name="mail" class="h-4 w-4" />
                </span>
                <x-text-input id="email" type="email" name="email" :value="old('email')" required autofocus
                              autocomplete="username" placeholder="nama@perusahaan.com" class="pl-8" />
            </div>
        </x-ui.field>

        <x-ui.field label="Kata Sandi" for="password" :error="$errors->get('password')" required>
            <x-ui.password-input id="password" name="password" required autocomplete="current-password" />
        </x-ui.field>

        <div class="flex items-center justify-between gap-3">
            <label for="remember_me" class="inline-flex cursor-pointer items-center gap-2">
                <x-ui.checkbox id="remember_me" name="remember" />
                <span class="text-sm text-ink-600">Ingat saya</span>
            </label>

            <span class="text-xs text-ink-400">Lupa kata sandi? Hubungi admin.</span>
        </div>

        <x-ui.button type="submit" class="w-full">Masuk</x-ui.button>
    </form>

    <div class="mt-5 border-t border-ink-100 pt-4 text-center">
        <a href="{{ route('app.download-page') }}" class="inline-flex items-center gap-1.5 text-sm text-ink-600 hover:text-ink-950">
            <x-icon name="download" class="h-4 w-4" />
            Unduh aplikasi Android
        </a>
    </div>
</x-guest-layout>
