<?php

namespace App\Http\Controllers;

use App\Models\Mirror;
use App\Models\Zone;
use App\Npm\Connection;
use App\Npm\Mirrors;
use App\Support\Zones;
use Illuminate\Http\RedirectResponse;

/** Admin: what each zone's proxy mirrors from other zones, and the mirrors nothing asks for any more. */
class MirrorController extends Controller
{
    public function index()
    {
        $zones = Zones::all();
        $all = Zone::query()->get();
        $sections = [];

        foreach ($zones as $zone) {
            $wanted = Mirrors::wanted($zone, null, $all);
            $records = Mirror::query()->where('zone_id', $zone->id)->get()->keyBy('domain');
            if ($wanted === [] && $records->isEmpty()) {
                continue;
            }

            $sections[] = [
                'zone' => $zone, 'connected' => Connection::exists($zone),
                'rows' => array_map(fn ($w) => ['wanted' => $w, 'record' => $records->get($w['domain'])], array_values($wanted)),
                'orphans' => Mirrors::orphans($zone, $wanted),
            ];
        }

        return view('npm.mirrors', ['sections' => $sections, 'zones' => $zones]);
    }

    /** Stop tracking a mirror (after the host was removed in NPM by hand). Nothing is deleted in NPM. */
    public function forget(Mirror $mirror): RedirectResponse
    {
        $mirror->delete();

        return redirect()->route('npm.mirrors')->with('status', "LabMan no longer tracks the mirror of {$mirror->domain}. Nothing was changed in NPM.");
    }
}
