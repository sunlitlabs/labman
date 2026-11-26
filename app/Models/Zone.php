<?php

namespace App\Models;

use App\Support\Domains;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A trust level that servers (and through them, services) belong to. */
class Zone extends Model
{
    protected $fillable = ['name', 'level', 'color', 'icon', 'is_default', 'cidrs', 'domains', 'proxy_address', 'notes', 'certificate_policy', 'mirror_sources', 'dns', 'dashboard_indicator'];

    protected function casts(): array
    {
        return ['level' => 'integer', 'is_default' => 'boolean', 'dashboard_indicator' => 'boolean', 'cidrs' => 'array', 'domains' => 'array', 'certificate_policy' => 'array', 'mirror_sources' => 'array', 'dns' => 'array'];
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

    /**
     * How the zone's names are answered. Split-horizon: the inside resolver also answers this zone's public names, with
     * its proxy. Public address: what the public provider points this zone's public names at (a CDN, a tunnel, a WAN address).
     *
     * @return array{split_horizon: bool, public_address: ?string}
     */
    public function dnsSettings(): array
    {
        $d = is_array($this->dns) ? $this->dns : [];

        return ['split_horizon' => (bool) ($d['split_horizon'] ?? false), 'public_address' => filled($d['public_address'] ?? null) ? (string) $d['public_address'] : null];
    }

    /** How this zone's proxy gets certificates. */
    public function certificatePolicy(): \App\Npm\CertificatePolicy
    {
        return \App\Npm\CertificatePolicy::fromArray($this->certificate_policy);
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
