<?php $__env->startSection('title', 'Credits & licenses · '.config('app.name')); ?>

<?php $__env->startSection('content'); ?>
    <div class="mx-auto max-w-3xl px-4 py-12 sm:px-6">
        <h1 class="text-3xl font-semibold tracking-tight">Credits &amp; licenses</h1>
        <p class="mt-3 text-stone-600">This store is built on open-source software that allows commercial use. Thank you to their authors.</p>

        <h2 class="mt-10 text-lg font-semibold">Software</h2>
        <ul class="mt-3 divide-y divide-stone-200 rounded-2xl border border-stone-200 bg-white">
            <?php $__currentLoopData = [
                ['WebAR.rocks.hand', 'Foot detection and tracking', 'MIT License, © 2020 WebAR.rocks', 'https://github.com/WebAR-rocks/WebAR.rocks.hand', 'vendor/webar-rocks/LICENSE'],
                ['three.js', '3D rendering', 'MIT License, © 2010-2021 three.js authors', 'https://threejs.org', 'vendor/three-r131/LICENSE'],
                ['<model-viewer>', '3D product viewer and AR', 'Apache License 2.0, © Google LLC', 'https://modelviewer.dev', null],
                ['Laravel', 'Web application framework', 'MIT License, © Taylor Otwell', 'https://laravel.com', null],
                ['Tailwind CSS', 'Styling', 'MIT License, © Tailwind Labs', 'https://tailwindcss.com', null],
            ]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as [$name, $purpose, $license, $url, $licenseFile]): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <li class="flex flex-col gap-1 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <a href="<?php echo e($url); ?>" class="font-semibold hover:underline" rel="noopener"><?php echo e($name); ?></a>
                        <p class="text-sm text-stone-600"><?php echo e($purpose); ?></p>
                    </div>
                    <p class="text-sm text-stone-500">
                        <?php if($licenseFile): ?>
                            <a href="<?php echo e(asset($licenseFile)); ?>" class="hover:underline"><?php echo e($license); ?></a>
                        <?php else: ?>
                            <?php echo e($license); ?>

                        <?php endif; ?>
                    </p>
                </li>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </ul>

        <?php if($products->isNotEmpty()): ?>
            <h2 class="mt-10 text-lg font-semibold">3D models</h2>
            <ul class="mt-3 divide-y divide-stone-200 rounded-2xl border border-stone-200 bg-white">
                <?php $__currentLoopData = $products; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $product): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <li class="p-4">
                        <a href="<?php echo e(route('products.show', $product)); ?>" class="font-semibold hover:underline"><?php echo e($product->name); ?></a>
                        <p class="text-sm text-stone-600"><?php echo e($product->attribution); ?></p>
                    </li>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </ul>
        <?php endif; ?>
    </div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.store', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH F:\Sab Cis\3 year sem 1\7Needle Intern\2026.06\Thilakawardhana\github dep tryon\shoetryon\resources\views/store/credits.blade.php ENDPATH**/ ?>