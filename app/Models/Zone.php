<?php

namespace App\Models;

use App\Support\Domains;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A trust level that servers (and through them, services) belong to. */
class Zone extends Model
{
    protected $fillable = ['name', 'level', 'color', 'icon', 'is_default', 'cidrs', 'domains', 'proxy_address', 'notes'];

    protected function casts(): array
    {
        return ['level' => 'integer', 'is_default' => 'boolean', 'cidrs' => 'array', 'domains' => 'array'];
    }

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    /** Services that name this zone themselves (the rare override); the others follow their server. */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * The zone's domains as {local: [], public: []}. The default zone still reads the two global lists from the
     * settings (one place to edit them, as before); every other zone keeps its own.
     *
     * @return array{local: list<string>, public: list<string>}
     */
    public function domains(): array
    {
        if ($this->is_default) {
            return ['local' => Domains::local(), 'public' => Domains::public()];
        }

        $stored = is_array($this->domains) ? $this->domains : [];

        return ['local' => Domains::parse($stored['local'] ?? []), 'public' => Domains::parse($stored['public'] ?? [])];
    }

    /** 'local' or 'public' if the host sits under one of this zone's domains (public wins a tie), else null. */
    public function scopeOf(string $host): ?string
    {
        $host = mb_strtolower($host);
        $domains = $this->domains();

        foreach (['public', 'local'] as $scope) {
            foreach ($domains[$scope] as $domain) {
                if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                    return $scope;
                }
            }
        }

        return null;
    }

    /** Every domain name of the zone, local first. @return list<string> */
    public function domainNames(): array
    {
        $d = $this->domains();

        return array_values(array_unique([...$d['local'], ...$d['public']]));
    }
}
