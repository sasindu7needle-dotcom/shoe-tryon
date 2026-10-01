<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;

class CreateAdmin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:create-admin
                            {email : Email address used to sign in}
                            {--name=Admin : Display name}
                            {--password= : Password (prompted when omitted; avoid in shell history)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create an admin account, or promote and reset the password of an existing user';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $input = [
            'email' => $this->argument('email'),
            'password' => $this->option('password') ?? password(
                label: 'Password',
                required: true,
                hint: 'At least 12 characters.',
            ),
        ];

        $validator = Validator::make($input, [
            'email' => ['required', 'email'],
            'password' => ['required', Password::min(12)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => $input['email']]);
        $user->forceFill([
            'name' => $user->name ?? $this->option('name'),
            'password' => $input['password'],
            'is_admin' => true,
        ])->save();

        $this->components->info(($user->wasRecentlyCreated ? 'Created' : 'Updated')." admin {$user->email}.");

        return self::SUCCESS;
    }
}
