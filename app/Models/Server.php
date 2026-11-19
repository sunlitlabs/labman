<?php

namespace App\Models;

use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Server extends Model
{
    protected $fillable = [
        'name', 'address', 'domain', 'group', 'ports', 'ssh_user', 'ssh_port', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'ports' => 'array',
            'checks' => 'array',
            'ssh_port' => 'integer',
            'checked_at' => 'datetime',
        ];
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /** TCP ports to probe: the SSH port first, then the extra ones. @return list<int> */
    public function checkPorts(): array
    {
        $ports = array_map('intval', $this->ports ?? []);
        array_unshift($ports, (int) $this->ssh_port ?: 22);

        return array_values(array_unique($ports));
    }

    /**
     * "ssh [-p PORT] [user@]host", built only from stored (validated) fields.
     * The host is the domain when settings say so and the server has one.
     */
    public function sshCommand(): string
    {
        $host = (Settings::get('ssh_target') === 'domain' && $this->domain) ? $this->domain : $this->address;
        $user = $this->ssh_user ?: (string) Settings::get('ssh_user', '');

        $parts = ['ssh'];
        $port = (int) $this->ssh_port ?: 22;
        if ($port !== 22) {
            $parts[] = '-p '.$port;
        }
        $parts[] = ($user !== '' ? $user.'@' : '').$host;

        return implode(' ', $parts);
    }
}
