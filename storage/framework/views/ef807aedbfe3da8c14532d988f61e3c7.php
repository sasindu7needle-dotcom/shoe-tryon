<!DOCTYPE html>
<html lang="<?php echo e(str_replace('_', '-', app()->getLocale())); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Sign in · <?php echo e(config('app.name')); ?></title>
    <?php echo app('Illuminate\Foundation\Vite')(['resources/css/app.css']); ?>
</head>
<body class="grid min-h-screen place-items-center bg-stone-100 p-4 font-sans text-stone-900 antialiased">
    <form method="POST" action="<?php echo e(route('admin.login')); ?>" class="w-full max-w-sm rounded-3xl border border-stone-200 bg-white p-8 shadow-sm">
        <?php echo csrf_field(); ?>
        <h1 class="text-xl font-semibold">Sign in to <?php echo e(config('app.name')); ?></h1>
        <p class="mt-1 text-sm text-stone-500">Admin access only.</p>

        <label class="mt-6 block text-sm font-medium" for="email">Email</label>
        <input id="email" name="email" type="email" value="<?php echo e(old('email')); ?>" required autofocus autocomplete="username"
               class="mt-1 w-full rounded-xl border border-stone-300 px-3 py-2 focus:border-stone-900 focus:outline-none">
        <?php $__errorArgs = ['email'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
            <p class="mt-1 text-sm text-red-600"><?php echo e($message); ?></p>
        <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>

        <label class="mt-4 block text-sm font-medium" for="password">Password</label>
        <input id="password" name="password" type="password" required autocomplete="current-password"
               class="mt-1 w-full rounded-xl border border-stone-300 px-3 py-2 focus:border-stone-900 focus:outline-none">

        <label class="mt-4 flex items-center gap-2 text-sm text-stone-600">
            <input type="checkbox" name="remember" class="rounded border-stone-300"> Keep me signed in
        </label>

        <button class="mt-6 w-full rounded-full bg-stone-900 py-2.5 font-semibold text-white hover:bg-stone-700">Sign in</button>
    </form>
</body>
</html>
<?php /**PATH F:\Sab Cis\3 year sem 1\7Needle Intern\2026.06\Thilakawardhana\github dep tryon\shoetryon\resources\views/admin/auth/login.blade.php ENDPATH**/ ?>