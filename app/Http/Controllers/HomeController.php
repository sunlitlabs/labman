<?php

namespace App\Http\Controllers;

use App\Models\Link;
use App\Models\Server;
use App\Models\Service;
use App\Models\Zone;
use App\Monitoring\StatusView;
use App\Support\Zones;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class HomeController extends Controller
{
    /** Kinds in the order they appear inside a card. */
    private const KINDS = ['services', 'servers', 'links'];

    /**
     * The dashboard for anyone who may see services or servers; otherwise the first screen they may see, else login.
     */
    public function __invoke(Request $request)
    {
        $canServices = Gate::allows('view-screen', 'services');
        $canServers = Gate::allows('view-screen', 'servers');

        if ($canServices || $canServers) {
            $services = $canServices ? Service::query()->with('server')->where('on_dashboard', true)->orderBy('group')->orderBy('name')->get() : collect();
            $servers = $canServers ? Server::query()->where('on_dashboard', true)->orderBy('group')->orderBy('name')->get() : collect();
            $links = Link::query()->where('on_dashboard', true)->orderBy('group')->orderBy('name')->get();

            // Zone badges and the by-zone view are for people opening the dashboard from a zone that asks for them.
            $zones = Zones::all()->keyBy('id');
            $viewer = Zones::suggestFor((string) $request->ip(), $zones->values());
            $indicator = $zones->count() > 1 && ((bool) $viewer?->dashboard_indicator || (Gate::allows('admin') && $request->boolean('indicator')));
            $view = $indicator && $request->query('view') === 'zones' ? 'zones' : 'groups';

            $defaultZone = Zones::defaultId();
            $zoneOf = [
                'services' => $services->mapWithKeys(fn (Service $s) => [$s->id => $s->zone_id ?: $s->server?->zone_id ?: $defaultZone])->all(),
                'servers' => $servers->mapWithKeys(fn (Server $s) => [$s->id => $s->zone_id ?: $defaultZone])->all(),
            ];

            return view('dashboard', [
                'canServices' => $canServices,
                'canServers' => $canServers,
                'cards' => self::cards($services, $servers, $links, $view, $zones, $zoneOf),
                'indicator' => $indicator,
                'view' => $view,
                'zones' => $zones,
                'zoneOf' => $zoneOf,
                'summary' => [
                    'services' => StatusView::summary($services),
                    'servers' => StatusView::summary($servers),
                ],
                'kinds' => ['services' => $services->count(), 'servers' => $servers->count(), 'links' => $links->count()],
                'empty' => $services->isEmpty() && $servers->isEmpty() && $links->isEmpty(),
            ]);
        }

        foreach (['settings', 'users'] as $screen) {
            if (Gate::allows('view-screen', $screen)) {
                return redirect()->route($screen.'.index');
            }
        }

        return auth()->check() ? abort(403) : redirect()->route('login');
    }

    /**
     * The dashboard as cards, side by side. By group: one card per group heading (items of every kind together),
     * then a card per kind for what has no group. By zone: one card per zone, most trusted first.
     *
     * @param  array{services: array<int, int|null>, servers: array<int, int|null>}  $zoneOf
     * @return list<array{title: string, entries: list<array<string, mixed>>}>
     */
    public static function cards(Collection $services, Collection $servers, Collection $links, string $view, Collection $zones, array $zoneOf): array
    {
        $buckets = [];   // sort key => ['title' => ..., 'items' => [[kind, model], ...]]

        $place = function (string $kind, $model) use (&$buckets, $view, $zones, $zoneOf) {
            if ($view === 'zones') {
                $zone = $zones->get($zoneOf[$kind][$model->id] ?? 0);
                $key = $zone ? sprintf('0|%03d|%s', 100 - $zone->level, mb_strtolower($zone->name)) : '2|'.$kind;
                $title = $zone ? $zone->name : ucfirst($kind);
                if ($kind === 'links') {
                    [$key, $title] = ['1|links', 'Links'];
                }
            } elseif (filled($model->group)) {
                $key = '0|'.mb_strtolower($model->group);
                $title = $model->group;
            } else {
                $key = '1|'.array_search($kind, self::KINDS, true);
                $title = ucfirst($kind);
            }
            $buckets[$key] ??= ['title' => $title, 'items' => []];
            $buckets[$key]['items'][] = [$kind, $model];
        };

        foreach ($services as $m) {
            $place('services', $m);
        }
        foreach ($servers as $m) {
            $place('servers', $m);
        }
        foreach ($links as $m) {
            $place('links', $m);
        }

        ksort($buckets, SORT_NATURAL);

        return array_values(array_map(fn ($bucket) => [
            'title' => $bucket['title'],
            'entries' => self::entries($bucket['items']),
        ], $buckets));
    }

    /**
     * Kinds in order, then by name; services that share a "same as" name collapse into one family entry
     * (at the place of their first member) when a card holds two or more of them.
     *
     * @param  list<array{0: string, 1: object}>  $items
     * @return list<array<string, mixed>>
     */
    private static function entries(array $items): array
    {
        usort($items, fn ($a, $b) => [array_search($a[0], self::KINDS, true), mb_strtolower($a[1]->name)] <=> [array_search($b[0], self::KINDS, true), mb_strtolower($b[1]->name)]);

        $families = [];
        foreach ($items as [$kind, $model]) {
            if ($kind === 'services' && filled($model->family)) {
                $families[mb_strtolower($model->family)][] = $model;
            }
        }

        $out = [];
        $done = [];
        foreach ($items as [$kind, $model]) {
            $key = $kind === 'services' && filled($model->family) ? mb_strtolower($model->family) : null;
            if ($key !== null && count($families[$key]) > 1) {
                if (! isset($done[$key])) {
                    $done[$key] = true;
                    $out[] = ['kind' => 'family', 'name' => $model->family, 'members' => $families[$key]];
                }

                continue;
            }
            $out[] = ['kind' => $kind, 'model' => $model];
        }

        return $out;
    }
}
