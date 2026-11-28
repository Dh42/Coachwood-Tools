<?php

use App\Tools\ToolRegistry;
use Illuminate\Database\Migrations\Migration;

/**
 * The VC Image Manager, beside the Listings Editors in "Amazon · Listings",
 * with the name and description the old app's admins last set. It has no
 * scheduled task: its audit runs only from the page. `legacy:import-platform`
 * then copies the old order and active flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        ToolRegistry::register([
            'slug' => 'vc-image-manager',
            'name' => 'VC Image Manager',
            'description' => "Upload, replace, and remove Vendor Central product images without Amazon's native UI, plus an auditor showing which live image slots are not our contribution.",
            'group' => 'Amazon · Listings',
            'icon' => 'layers',
            'url' => '/tools/vc-image-manager',
        ]);
    }

    public function down(): void
    {
        ToolRegistry::unregister('vc-image-manager');
    }
};
