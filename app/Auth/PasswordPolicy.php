<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PasswordPolicy
{
    public function change(User $user, string $password, bool $recovery = false): void
    {
        DB::transaction(function () use ($user, $password, $recovery) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($user->auth_provider !== 'local') {
                throw ValidationException::withMessages(['password' => 'Пароль управляется провайдером.']);
            }
            if (! $recovery && $user->password_changed_at?->gt(now()->subDay())) {
                throw ValidationException::withMessages(['password' => 'Пароль можно менять не чаще одного раза в сутки.']);
            }
            $history = DB::table('password_histories')->where('user_id', $user->id)->latest('id')->limit(11)->pluck('password');
            if ($history->prepend($user->password)->contains(fn ($hash) => Hash::check($password, $hash))) {
                throw ValidationException::withMessages(['password' => 'Нельзя использовать последние 12 паролей.']);
            }
            DB::table('password_histories')->insert(['user_id' => $user->id, 'password' => $user->password, 'created_at' => now()]);
            $user->forceFill(['password' => $password, 'password_changed_at' => now(), 'remember_token' => null])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            AuthAudit::record($recovery ? 'password_reset' : 'password_changed', $user->id, 'local');
        });
    }
}
