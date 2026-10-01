<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Built assets are not committed, so tests assert markup rather than
        // resolved bundle URLs. `npm run build` is covered by its own CI job.
        $this->withoutVite();
    }

    /**
     * A binary glTF 2.0 file with a valid header, which is what uploads are
     * checked against.
     */
    protected function fakeGlb(string $name = 'shoe.glb'): UploadedFile
    {
        $json = str_pad('{"asset":{"version":"2.0"},"scenes":[{"nodes":[]}],"scene":0}', 64, ' ');
        $chunk = pack('V', strlen($json)).'JSON'.$json;
        $glb = 'glTF'.pack('VV', 2, 12 + strlen($chunk)).$chunk;

        return UploadedFile::fake()->createWithContent($name, $glb);
    }

    protected function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }
}
