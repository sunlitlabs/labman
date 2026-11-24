<?php

namespace App\Npm;

use App\Models\Zone;

/**
 * A zone's proxy at a glance for the badges: whether a login is saved, whether NPM answers and accepts it, how many
 * proxy hosts it has, and drift (hosts NPM has that LabMan does not know, and changes LabMan would send). One proxy
 * being down only affects its own row. Read-only; short timeout so a dead proxy does not stall the page.
 */
final class ProxyStatus
{
    /**
     * @return array{zone: Zone, state: 'none'|'ok'|'error', message: ?string, version: ?string, hosts: int, unknown_to_labman: int, to_push: int, cert_ok: int, cert_expiring: int, cert_problems: int}
     */
    public static function of(Zone $zone): array
    {
        $out = ['zone' => $zone, 'state' => 'none', 'message' => null, 'version' => null, 'hosts' => 0, 'unknown_to_labman' => 0, 'to_push' => 0, 'cert_ok' => 0, 'cert_expiring' => 0, 'cert_problems' => 0];

        $connection = Connection::get($zone);
        if ($connection === null) {
            return $out;
        }

        try {
            $client = new NpmClient($connection, 4.0);
            $out['version'] = $client->test();
            $sync = new NpmSync($client, $zone);

            $import = $sync->importPlan();
            $out['hosts'] = count($import);
            $out['unknown_to_labman'] = count(array_filter($import, fn ($i) => $i['action'] === 'create'));
            $out['to_push'] = count(array_filter($sync->pushPlan(), fn ($i) => in_array($i['action'], ['create', 'update'], true)));
            $c = $sync->certificateReport()['counts'];
            $out['cert_ok'] = $c['ok'];
            $out['cert_expiring'] = $c['expiring'];
            $out['cert_problems'] = $c['expired'] + $c['uncovered'] + $c['untrusted'] + $c['missing'] + $c['unknown'];
            $out['state'] = 'ok';
        } catch (NpmException $e) {
            $out['state'] = 'error';
            $out['message'] = $e->getMessage();
        }

        return $out;
    }
}
