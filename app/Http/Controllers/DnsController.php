<?php

namespace App\Http\Controllers;

use App\Dns\DnsExport;
use App\Dns\DnsOwners;
use App\Dns\DnsPlan;
use App\Models\Service;
use App\Models\Zone;
use App\Npm\Mirrors;
use App\Support\Zones;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Admin: the DNS plan the zones need (read-only), name collisions to settle, and exports. */
class DnsController extends Controller
{
    public function index()
    {
        $plan = DnsPlan::build();

        return view('dns.index', [
            'plan' => $plan, 'zones' => Zones::all()->keyBy('id'),
            'open' => count(DnsPlan::open($plan['conflicts'])), 'formats' => DnsExport::FORMATS,
        ]);
    }

    public function export(Request $request)
    {
        $format = $request->query('format', 'text');
        abort_unless(array_key_exists($format, DnsExport::FORMATS), 404);

        return response(DnsExport::render(DnsPlan::build(), $format), 200, [
            'Content-Type' => DnsExport::mime($format),
            'Content-Disposition' => 'attachment; filename="labman-dns.'.DnsExport::extension($format).'"',
        ]);
    }

    /** Settle a collision: the picked zone owns the domain or the name. */
    public function owner(Request $request): RedirectResponse
    {
        $v = $request->validate([
            'kind' => ['required', Rule::in(['domain', 'name'])],
            'key' => ['required', 'string', 'max:253'],
            'zone_id' => ['required', 'integer', 'exists:zones,id'],
        ]);

        DnsOwners::set($v['kind'], $v['key'], (int) $v['zone_id']);

        return redirect()->route('dns.index')->with('status', Zone::find($v['zone_id'])->name." now owns {$v['key']}.");
    }

    /** After an owner is picked, let the other zone reach the owner's hosts by mirroring them. */
    public function propose(Request $request): RedirectResponse
    {
        $v = $request->validate([
            'kind' => ['required', Rule::in(['zone', 'service'])],
            'target_zone' => ['required', 'integer', 'exists:zones,id'],
            'source_zone' => ['nullable', 'integer', 'exists:zones,id'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
        ]);

        $target = Zone::findOrFail($v['target_zone']);

        if ($v['kind'] === 'zone') {
            abort_if(empty($v['source_zone']) || (int) $v['source_zone'] === $target->id, 422);
            $refs = Mirrors::refs($target->mirror_sources);
            if (! in_array((int) $v['source_zone'], array_column($refs, 'zone_id'), true)) {
                $refs[] = ['zone_id' => (int) $v['source_zone'], 'upstream' => 'auto'];
                $target->update(['mirror_sources' => $refs]);
            }
            $what = "{$target->name} will mirror the hosts of ".Zone::find($v['source_zone'])->name;
        } else {
            $service = Service::findOrFail((int) ($v['service_id'] ?? 0));
            $refs = Mirrors::refs($service->publish_zones);
            if (! in_array($target->id, array_column($refs, 'zone_id'), true)) {
                $refs[] = ['zone_id' => $target->id, 'upstream' => 'auto'];
                $service->update(['publish_zones' => $refs]);
            }
            $what = "{$service->name} will also be published in {$target->name}";
        }

        return redirect()->route('dns.index')->with('status', "{$what}. Nothing changes in NPM until you apply a previewed push.");
    }
}
