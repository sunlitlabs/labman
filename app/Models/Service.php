<?php

namespace App\Models;

use App\Support\ExpectRange;
use App\Support\Hosts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class Service extends Model
{
    protected $fillable = [
        'name', 'domain', 'server_id', 'address', 'port', 'scheme', 'path', 'group',
        'expect', 'verify_tls', 'public_check', 'websockets', 'notes', 'npm_host_id', 'update_project', 'update_template',
        'health_path', 'monitor', 'check_tcp', 'icon', 'public_domain', 'npm_public_host_id', 'extra_domains', 'zone_id', 'cert_mode',
    ];

    /** Defaults for new models (the database defaults are older than the current ones). */
    protected $attributes = [
        'expect' => ExpectRange::DEFAULT,
        'monitor' => true,
        'check_tcp' => true,
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'extra_domains' => 'array',
            'verify_tls' => 'boolean',
            'public_check' => 'boolean',
            'websockets' => 'boolean',
            'monitor' => 'boolean',
            'check_tcp' => 'boolean',
            'checks' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** Only set when the service differs from its server's zone; see App\Support\Zones::of() for the effective zone. */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    /** The host the backend check connects to: the linked server's address, else our own. */
    /** Can this service be updated through a Semaphore workbook? */
    public function hasUpdate(): bool
    {
        return $this->update_project > 0 && $this->update_template > 0;
    }

    public function backendAddress(): ?string
    {
        $address = $this->server?->address ?: $this->address;

        return $address !== null && $address !== '' ? $address : null;
    }

    public function effectivePort(): int
    {
        return $this->port ?: ($this->scheme === 'https' ? 443 : 80);
    }

    public function backendUrl(): ?string
    {
        $host = $this->backendAddress();
        if ($host === null) {
            return null;
        }

        return $this->scheme.'://'.Hosts::forUrl($host).':'.$this->effectivePort().$this->path;
    }

    /** https://<domain>: the primary (usually local) name. */
    public function localUrl(): ?string
    {
        return $this->domain ? 'https://'.$this->domain.$this->path : null;
    }

    /** https://<public_domain>, when the service is also reachable from outside. */
    public function publicDomainUrl(): ?string
    {
        return $this->public_domain ? 'https://'.$this->public_domain.$this->path : null;
    }

    /** The name URL: the primary domain, else the public one. */
    public function publicUrl(): ?string
    {
        return $this->localUrl() ?? $this->publicDomainUrl();
    }

    /** What clicking the service opens: the primary domain (works at home), else the public one, else the backend. */
    public function openUrl(): ?string
    {
        return $this->publicUrl() ?? $this->backendUrl();
    }

    /** The path the health check requests: the dedicated health path if there is one, else the service path. */
    public function healthPath(): string
    {
        return $this->health_path ?: $this->path;
    }

    public function expectRange(): ExpectRange
    {
        try {
            return ExpectRange::parse($this->expect ?: ExpectRange::DEFAULT);
        } catch (InvalidArgumentException) {
            return ExpectRange::parse(ExpectRange::DEFAULT);
        }
    }
}
