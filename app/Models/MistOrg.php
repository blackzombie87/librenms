<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MistOrg extends Model
{
    public $timestamps = true;
    protected $table = 'mist_orgs';
    protected $fillable = [
        'name',
        'api_url',
        'api_key',
        'org_id',
        'site_ids',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    /**
     * Get site IDs as array (empty = all sites)
     */
    public function getSiteIdsArray(): array
    {
        if (empty($this->site_ids)) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $this->site_ids))
        ));
    }

    /**
     * Get the device representing this org (if exists)
     */
    public function device()
    {
        return $this->hasOne(Device::class, 'sysObjectID', 'org_id')
            ->where('os', 'mist-org');
    }
}
