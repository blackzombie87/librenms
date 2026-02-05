<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mist_orgs', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('Display name for this Mist org');
            $table->string('api_url')->comment('Mist API base URL (e.g. https://api.mist.com)');
            $table->text('api_key')->comment('Mist API token');
            $table->string('org_id', 36)->comment('Mist organization UUID');
            $table->text('site_ids')->nullable()->comment('Comma-separated list of site UUIDs to monitor (empty = all sites)');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique('org_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mist_orgs');
    }
};
