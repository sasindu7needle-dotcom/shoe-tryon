<?php $__env->startSection('content'); ?>
    <section class="mx-auto grid max-w-6xl items-center gap-10 px-4 py-12 sm:px-6 md:grid-cols-2 md:py-20">
        <div>
            <p class="text-sm font-semibold uppercase tracking-widest text-orange-600">Virtual try-on</p>
            <h1 class="mt-3 text-4xl font-semibold tracking-tight text-balance sm:text-5xl">See them on your feet before you buy.</h1>
            <p class="mt-4 max-w-md text-lg text-stone-600">
                Point your phone at your feet and the shoe appears on them, live. No app to install, and your camera never leaves your device.
            </p>
            <?php if($products->isNotEmpty()): ?>
                <div class="mt-8 flex flex-wrap gap-3">
                    <a href="<?php echo e(route('try-on.show', $products->first())); ?>" class="rounded-full bg-stone-900 px-6 py-3 font-semibold text-white transition hover:bg-stone-700">Try one on now</a>
                    <a href="#catalog" class="rounded-full border border-stone-300 px-6 py-3 font-semibold transition hover:border-stone-900">Browse shoes</a>
                </div>
            <?php endif; ?>
        </div>
        <ol class="grid gap-4 sm:grid-cols-3 md:grid-cols-1">
            <?php $__currentLoopData = [
                ['Open on your phone', 'Works in Safari and Chrome over HTTPS.'],
                ['Point at your feet', 'Stand in good light, about 1 m (3 ft) away.'],
                ['Walk around', 'Turn your feet, switch colourways and snap a photo.'],
            ]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as [$title, $text]): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <li class="flex gap-4 rounded-2xl border border-stone-200 bg-white p-5">
                    <span class="grid size-9 shrink-0 place-items-center rounded-full bg-orange-100 font-semibold text-orange-700"><?php echo e($loop->iteration); ?></span>
                    <div>
                        <p class="font-semibold"><?php echo e($title); ?></p>
                        <p class="text-sm text-stone-600"><?php echo e($text); ?></p>
                    </div>
                </li>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </ol>
    </section>

    <section id="catalog" class="mx-auto max-w-6xl scroll-mt-20 px-4 pb-20 sm:px-6">
        <h2 class="text-2xl font-semibold tracking-tight">Shoes you can try on</h2>

        <?php if($products->isEmpty()): ?>
            <p class="mt-6 rounded-2xl border border-dashed border-stone-300 p-10 text-center text-stone-500">
                No products yet. Sign in to the admin to add your first shoe.
            </p>
        <?php else: ?>
            <ul class="mt-6 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                <?php $__currentLoopData = $products; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $product): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <li class="group relative flex flex-col overflow-hidden rounded-3xl border border-stone-200 bg-white transition hover:shadow-lg">
                        <div class="aspect-square bg-gradient-to-b from-stone-100 to-stone-50">
                            <?php if($product->posterUrl()): ?>
                                <img src="<?php echo e($product->posterUrl()); ?>" alt="<?php echo e($product->name); ?>" loading="lazy" class="h-full w-full object-contain p-6 transition duration-300 group-hover:scale-105">
                            <?php endif; ?>
                        </div>
                        <div class="flex flex-1 flex-col p-5">
                            <p class="text-xs font-medium uppercase tracking-wide text-stone-500"><?php echo e($product->brand); ?></p>
                            <h3 class="mt-1 font-semibold">
                                <a href="<?php echo e(route('products.show', $product)); ?>" class="after:absolute after:inset-0"><?php echo e($product->name); ?></a>
                            </h3>
                            <p class="mt-1 text-stone-700"><?php echo e($product->formattedPrice()); ?></p>
                            <a href="<?php echo e(route('try-on.show', $product)); ?>" class="relative z-10 mt-4 inline-flex items-center justify-center gap-2 rounded-full bg-orange-500 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-orange-600">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                                Try on
                            </a>
                        </div>
                    </li>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </ul>
        <?php endif; ?>
    </section>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.store', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH F:\Sab Cis\3 year sem 1\7Needle Intern\2026.06\Thilakawardhana\shoetryonbit\resources\views/store/index.blade.php ENDPATH**/ ?>