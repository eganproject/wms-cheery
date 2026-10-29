<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Akun super admin hanya boleh diubah super admin: siapa pun yang
        // bisa mengganti kata sandinya bisa masuk sebagai dia.
        return $this->user()->can('users.update')
            && \App\Support\AccessGuard::mayManage($this->user(), $this->route('user'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'lowercase', 'email', 'max:255',
                Rule::unique('users')->ignore($this->route('user')->id),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            $actor = $this->user();
            $target = $this->route('user');
            $role = \App\Models\Role::find($this->input('role_id'));

            if ($role?->is_super_admin && ! $actor->isSuperAdmin()) {
                $validator->errors()->add('role_id', 'Hanya super admin yang bisa memberikan role super admin.');
            }

            // Mengunci diri sendiri di luar pintu tidak bisa dibatalkan dari
            // akun yang sama.
            if ($target->is($actor)) {
                if ((int) $this->input('role_id') !== (int) $target->role_id) {
                    $validator->errors()->add('role_id', 'Anda tidak bisa mengubah role akun Anda sendiri.');
                }

                if (! $this->boolean('is_active')) {
                    $validator->errors()->add('is_active', 'Anda tidak bisa menonaktifkan akun Anda sendiri.');
                }
            }

            if (\App\Support\AccessGuard::isLastActiveSuperAdmin($target)
                && (! $role?->is_super_admin || ! $this->boolean('is_active'))) {
                $validator->errors()->add('role_id', 'Ini satu-satunya super admin yang aktif. Jadikan pengguna lain super admin lebih dulu.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower((string) $this->input('email')),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nama',
            'email' => 'email',
            'phone' => 'nomor telepon',
            'role_id' => 'role',
            'password' => 'kata sandi',
        ];
    }
}
