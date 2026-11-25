<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A bookmark on the dashboard for something that is neither a monitored service nor a server (a controller's web page, a cloud dashboard). Never health-checked. */
class Link extends Model
{
    protected $fillable = ['name', 'url', 'group', 'notes', 'icon', 'on_dashboard'];

    protected $attributes = ['on_dashboard' => true];

    protected function casts(): array
    {
        return ['on_dashboard' => 'boolean'];
    }

    /** The host part, shown under the name. */
    public function hostLabel(): string
    {
        return (string) (parse_url($this->url, PHP_URL_HOST) ?: $this->url);
    }
}
