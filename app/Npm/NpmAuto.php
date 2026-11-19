<?php

namespace App\Npm;

use App\Models\Notice;
use App\Models\Server;
use App\Models\Service;
use App\Support\Secrets;
use Throwable;

/**
 * LabMan is the source of truth for Nginx Proxy Manager: when a service (or the server it runs on) changes, or the
 * certificate chosen for a kind of domain changes, the matching proxy hosts are updated in NPM, once, after the
 * response has gone out. Only what LabMan owns is written (domain, forward target, websockets, certificate), a host
 * that does not exist yet is created, and nothing is ever deleted in NPM. Switch it off and the guided sync and the
 * push preview still work by hand.
 */
final class NpmAuto
{
    /** Fields of a service that end up in its proxy host. */
    public const SERVICE_FIELDS = ['domain', 'public_domain', 'scheme', 'port', 'address', 'server_id', 'websockets'];

    /** What is waiting to be sent. Kept on the application instance so it never leaks between requests (or tests). */
    private static function pending(): \ArrayObject
    {
        if (! app()->bound('labman.npm.pending')) {
            app()->instance('labman.npm.pending', new \ArrayObject(['queued' => false, 'services' => [], 'scopes' => []]));
        }

        return app('labman.npm.pending');
    }

    public static function enabled(): bool
    {
        return Connection::exists() && Secrets::plain('npm.auto') !== '0';
    }

    public static function setEnabled(bool $on): void
    {
        Secrets::putPlain('npm.auto', $on ? '1' : '0');
    }

    /** @param list<int> $ids */
    public static function queueServices(array $ids): void
    {
        if ($ids !== []) {
            self::pending()['services'] = array_values(array_unique([...self::pending()['services'], ...$ids]));
            self::schedule();
        }
    }

    /** Every host of this kind of domain ('local' or 'public'), e.g. after its certificate changed. @param list<string> $scopes */
    public static function queueScopes(array $scopes): void
    {
        if ($scopes !== []) {
            self::pending()['scopes'] = array_values(array_unique([...self::pending()['scopes'], ...$scopes]));
            self::schedule();
        }
    }

    public static function serviceSaved(Service $service): void
    {
        if ($service->wasChanged(self::SERVICE_FIELDS)) { // also true for a service that was just created with any of them set
            self::queueServices([$service->id]);
        }
    }

    /** A server's address decides where its services' proxy hosts forward to. */
    public static function serverSaved(Server $server): void
    {
        if ($server->wasChanged('address')) {
            self::queueServices(Service::query()->where('server_id', $server->id)->pluck('id')->map(fn ($i) => (int) $i)->all());
        }
    }

    private static function schedule(): void
    {
        if (! self::enabled() || self::pending()['queued']) {
            return;
        }

        self::pending()['queued'] = true;
        app()->terminating(fn () => self::flush());
    }

    public static function flush(): void
    {
        $services = self::pending()['services'];
        $scopes = self::pending()['scopes'];
        self::pending()->exchangeArray(['queued' => false, 'services' => [], 'scopes' => []]);

        if (! self::enabled() || ($services === [] && $scopes === [])) {
            return;
        }

        try {
            self::run($services, $scopes);
        } catch (Throwable $e) {
            $message = $e instanceof NpmException ? $e->getMessage() : 'Unexpected error: '.$e->getMessage();
            self::record(false, 0, [['title' => 'Nginx Proxy Manager', 'outcome' => 'failed', 'message' => $message]]);
            Notice::raise('npm-sync', 'warning', 'Nginx Proxy Manager was not updated', $message, route('npm.index'));
        }
    }

    /**
     * Push the chosen services' hosts (and every host of the chosen kinds of domain) to NPM now.
     *
     * @param  list<int>  $services
     * @param  list<string>  $scopes
     * @return array{applied: int, results: list<array<string, string>>}
     *
     * @throws NpmException
     */
    public static function run(array $services, array $scopes): array
    {
        $sync = new NpmSync(NpmClient::fromStored());
        $selected = [];

        foreach ($sync->pushPlan($scopes === [] ? $services : null) as $item) {
            $wanted = in_array($item['data']['service_id'] ?? 0, $services, true) || in_array($item['data']['scope'] ?? '', $scopes, true);
            if ($wanted && in_array($item['action'], ['create', 'update'], true)) {
                $selected[$item['key']] = $item['fingerprint'];
            }
        }

        $result = $selected === [] ? ['applied' => 0, 'results' => []] : $sync->applyPush($selected);
        $failed = array_values(array_filter($result['results'], fn ($r) => $r['outcome'] === 'failed'));

        self::record($failed === [], $result['applied'], $result['results']);

        if ($failed === []) {
            Notice::resolve('npm-sync');
        } else {
            Notice::raise('npm-sync', 'warning', 'Nginx Proxy Manager was not fully updated', $failed[0]['title'].': '.$failed[0]['message'], route('npm.index'));
        }

        return $result;
    }

    /** @return array{ok: bool, at: string, applied: int, results: list<array<string, string>>}|null */
    public static function last(): ?array
    {
        $data = json_decode((string) Secrets::plain('npm.last_sync'), true);

        return is_array($data) && isset($data['ok'], $data['at']) ? $data : null;
    }

    public static function forget(): void
    {
        Secrets::forget(['npm.auto', 'npm.last_sync']);
        Notice::resolve('npm-sync');
    }

    /** @param list<array<string, string>> $results */
    private static function record(bool $ok, int $applied, array $results): void
    {
        Secrets::putPlain('npm.last_sync', json_encode(['ok' => $ok, 'at' => now()->toIso8601String(), 'applied' => $applied, 'results' => array_slice($results, 0, 20)]));
    }
}
