<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

final class CreateAdmin extends Command
{
    protected $signature = 'save-it:admin:create';

    protected $description = 'Interactively create the single Save It administrator';

    public function handle(): int
    {
        if (User::query()->where('is_admin', true)->exists()) {
            $this->error('An administrator already exists.');

            return self::FAILURE;
        }

        $username = (string) $this->ask('Username', 'admin');
        $email = (string) $this->ask('Email address');
        $password = (string) $this->secret('Password');
        $confirmation = (string) $this->secret('Confirm password');
        $validator = Validator::make(compact('username', 'email', 'password', 'confirmation'), [
            'username' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/', 'unique:users,username'],
            'email' => ['required', 'email:rfc', 'max:254', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12'],
            'confirmation' => ['same:password'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        User::query()->create([
            'name' => $username,
            'username' => $username,
            'email' => $email,
            'password' => Hash::make($password),
            'is_admin' => true,
        ]);
        $this->info('Administrator created.');

        return self::SUCCESS;
    }
}
