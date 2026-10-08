<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/** Creates or resets your admin login. Safe on production, unlike db:seed. */
class CreateAdminCommand extends Command
{
    protected $signature = 'portal:admin {--email= : Login email (defaults to the company email)} {--name=Keron Lewis : Display name} {--password= : Password (prompted if omitted)}';

    protected $description = 'Create or update the Code Canvas admin account';

    public function handle(): int
    {
        $email = $this->option('email') ?: config('company.email');
        $name = $this->option('name');
        $password = $this->option('password') ?: $this->secret('Password (min 12 characters)');

        $validator = Validator::make(compact('email', 'name', 'password'), ['email' => ['required', 'email'], 'name' => ['required', 'string', 'max:100'], 'password' => ['required', 'string', 'min:12']]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::updateOrCreate(['email' => $email], ['name' => $name, 'password' => $password, 'role' => 'admin', 'client_id' => null]);
        $this->info(($user->wasRecentlyCreated ? 'Created' : 'Updated').' admin account for '.$user->email.'.');
        $this->line('Sign in at '.route('login'));

        return self::SUCCESS;
    }
}
