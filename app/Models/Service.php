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
        'expect', 'verify_tls', 'public_check', 'websockets', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'verify_tls' => 'boolean',
            'public_check' => 'boolean',
            'websockets' => 'boolean',
            'checks' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** The host the backend check connects to: the linked server's address, else our own. */
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

    public function publicUrl(): ?string
    {
        return $this->domain ? 'https://'.$this->domain.$this->path : null;
    }

    /** What clicking the service opens: the public URL, else the backend URL. */
    public function openUrl(): ?string
    {
        return $this->publicUrl() ?? $this->backendUrl();
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
