<?php

namespace App\Http\Controllers;

use App\Models\Zone;
use App\Npm\Connection;
use App\Npm\NpmAuto;
use App\Npm\NpmClient;
use App\Npm\NpmException;
use App\Npm\NpmSync;
use App\Npm\NpmWizard;
use App\Npm\ProxyStatus;
use App\Support\DomainSuggestions;
use App\Support\Zones;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Admin-only Nginx Proxy Manager screen: connect, then preview and apply import / push. Every action belongs to one
 * zone's proxy (`zone` in the query or form; none means the default zone), and "all proxies" previews them together.
 */
class NpmController extends Controller
{
    public function index(Request $request)
    {
        $zone = $this->zone($request);
        $connection = Connection::summary($zone);

        // The certificate pickers need NPM's list; without a (working) connection the page falls back to ids.
        $certificates = null;
        if ($connection) {
            try {
                $certificates = NpmClient::fromStored($zone)->certificates();
            } catch (\Throwable) {
                $certificates = null;
            }
        }

        return view('npm.index', [
            'zone' => $zone, 'zones' => Zones::all(), 'zp' => $this->zp($zone),
            'connection' => $connection, 'certificates' => $certificates, 'values' => \App\Support\Settings::all(),
            'auto' => NpmAuto::enabled(), 'last' => NpmAuto::last(),
            'proxies' => Zones::multiple() ? Zones::all()->map(fn (Zone $z) => ProxyStatus::of($z))->all() : [],
        ]);
    }

    public function connect(Request $request): RedirectResponse
    {
        $zone = $this->zone($request);
        $data = $request->validate([
            'url' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'verify_tls' => ['sometimes', 'boolean'],
        ]);

        try {
            $url = Connection::cleanUrl($data['url']);
            $verify = $request->boolean('verify_tls');
            $version = (new NpmClient(['url' => $url, 'email' => $data['email'], 'password' => $data['password'], 'verify_tls' => $verify]))->test();
        } catch (NpmException $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }

        $first = Connection::summary($zone) === null;
        Connection::save($url, $data['email'], $data['password'], $verify, $zone);

        // A new connection goes straight to the guided sync; changing details of an existing one stays here.
        return redirect()->route($first ? 'npm.wizard' : 'npm.index', $this->zp($zone))->with('status', "Connected to Nginx Proxy Manager {$version}".(Zones::multiple() ? " for the {$zone->name} zone" : '').'.');
    }

    /** The guided sync: everything that can be matched up between NPM and LabMan, with defaults chosen. */
    public function wizard(Request $request)
    {
        $zone = $this->zone($request);
        $only = $request->query('service') ? (int) $request->query('service') : null;
        $suggest = $request->boolean('suggest') && DomainSuggestions::available();

        try {
            $built = (new NpmWizard(new NpmSync(NpmClient::fromStored($zone), $zone)))->build($only, $suggest);
        } catch (NpmException $e) {
            return redirect()->route('npm.index', $this->zp($zone))->withErrors(['npm' => $e->getMessage()]);
        }

        return view('npm.wizard', $built + ['only' => $only, 'suggest' => $suggest, 'suggestAvailable' => DomainSuggestions::available(), 'connection' => Connection::summary($zone), 'zone' => $zone, 'zp' => $this->zp($zone)]);
    }

    public function wizardApply(Request $request): RedirectResponse
    {
        $zone = $this->zone($request);
        $data = $request->validate([
            'choice' => ['required', 'array'],
            'choice.*' => ['in:import,push,skip,apply'],
            'fp_import' => ['array'], 'fp_import.*' => ['string', 'size:40'],
            'push_key' => ['array'], 'push_key.*' => ['string', 'max:100'],
            'fp_push' => ['array'], 'fp_push.*' => ['string', 'size:40'],
            'domain_edit' => ['array'], 'domain_edit.*' => ['nullable', 'string', 'max:255'],
        ]);

        $named = NpmWizard::applyDomainChoices($data['choice'], $data['domain_edit'] ?? []);
        $sel = NpmWizard::selections($data['choice'], $data['fp_import'] ?? [], $data['push_key'] ?? [], $data['fp_push'] ?? []);

        if ($sel['import'] === [] && $sel['push'] === [] && $named === []) {
            return redirect()->route('npm.index', $this->zp($zone))->with('status', 'Nothing was selected, so nothing changed.');
        }

        try {
            $sync = new NpmSync(NpmClient::fromStored($zone), $zone);
            $imported = $sel['import'] ? $sync->applyImport($sel['import']) : ['applied' => 0, 'results' => []];
            $pushed = $sel['push'] ? $sync->applyPush($sel['push']) : ['applied' => 0, 'results' => []];
        } catch (NpmException $e) {
            return redirect()->route('npm.wizard', $this->zp($zone))->withErrors(['npm' => $e->getMessage()]);
        }

        $applied = $imported['applied'] + $pushed['applied'] + count($named);

        return redirect()->route('npm.index', $this->zp($zone))
            ->with('status', "{$applied} change(s) applied.")
            ->with('npm_results', array_merge($imported['results'], $pushed['results'], $named));
    }

    /** The local and public domain lists and the certificate for each (the default zone's; other zones keep theirs on the Zones page). A changed certificate is pushed to NPM (when updating automatically). */
    public function domains(Request $request): RedirectResponse
    {
        $keys = ['local_domains', 'public_domains', 'local_default', 'public_default', 'npm_cert_local', 'npm_cert_public'];
        $rules = array_filter(\App\Domain\SettingsInput::rules(), fn ($key) => in_array($key, $keys, true), ARRAY_FILTER_USE_KEY);

        $before = [\App\Support\Settings::get('npm_cert_local'), \App\Support\Settings::get('npm_cert_public')];
        \App\Domain\SettingsInput::update($request->validate($rules));
        $certChanged = $before !== [\App\Support\Settings::get('npm_cert_local'), \App\Support\Settings::get('npm_cert_public')];

        $note = match (true) {
            ! $certChanged => '',
            NpmAuto::enabled() => ' The certificate change is being sent to the proxy hosts in NPM now; the result is shown below.',
            default => ' Automatic updates are off, so use the push preview to send the certificate change to NPM.',
        };

        return redirect()->route('npm.index')->with('status', 'Domains saved.'.$note);
    }

    public function auto(Request $request): RedirectResponse
    {
        NpmAuto::setEnabled($request->boolean('auto'));

        return redirect()->route('npm.index', $this->zp($this->zone($request)))->with('status', $request->boolean('auto') ? 'LabMan will now update NPM whenever a service changes.' : 'Automatic updates to NPM are off. Use the guided sync or the push preview.');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $zone = $this->zone($request);
        Connection::forget($zone);

        return redirect()->route('npm.index', $this->zp($zone))->with('status', 'Disconnected. The saved NPM login was deleted.');
    }

    public function importPreview(Request $request)
    {
        return $this->preview($request, 'import', fn (NpmSync $sync) => $sync->importPlan());
    }

    public function pushPreview(Request $request)
    {
        return $this->preview($request, 'push', fn (NpmSync $sync) => $sync->pushPlan());
    }

    public function importApply(Request $request): RedirectResponse
    {
        return $this->apply($request, 'import', fn (NpmSync $s, array $sel) => $s->applyImport($sel));
    }

    public function pushApply(Request $request): RedirectResponse
    {
        return $this->apply($request, 'push', fn (NpmSync $s, array $sel) => $s->applyPush($sel));
    }

    /**
     * One preview for every proxy: a section per zone with a proxy, each with its own switch. A proxy that cannot be
     * reached shows its error in its own section and does not hide the others.
     */
    public function allPreview(Request $request)
    {
        $mode = $request->query('mode') === 'import' ? 'import' : 'push';
        $sections = [];

        foreach (Connection::connectedZones() as $zone) {
            $section = ['zone' => $zone, 'items' => [], 'error' => null];
            try {
                $sync = new NpmSync(NpmClient::fromStored($zone), $zone);
                $section['items'] = $mode === 'import' ? $sync->importPlan() : $sync->pushPlan();
            } catch (NpmException $e) {
                $section['error'] = $e->getMessage();
            }
            $sections[] = $section;
        }

        if ($sections === []) {
            return redirect()->route('npm.index')->withErrors(['npm' => 'No zone has a Nginx Proxy Manager connection yet.']);
        }

        return view('npm.all', ['mode' => $mode, 'sections' => $sections]);
    }

    /** Apply the ticked changes of every section whose switch is on; one proxy failing does not stop the others. */
    public function allApply(Request $request): RedirectResponse
    {
        $mode = $request->input('mode') === 'import' ? 'import' : 'push';
        $data = $request->validate([
            'zones' => ['required', 'array', 'min:1'],
            'zones.*' => ['integer'],
            'apply' => ['array'], 'apply.*' => ['array'], 'apply.*.*' => ['string', 'max:100'],
            'fp' => ['array'], 'fp.*' => ['array'], 'fp.*.*' => ['string', 'size:40'],
        ], ['zones.required' => 'Switch on at least one proxy to apply its changes.']);

        $results = [];
        $applied = 0;
        $failures = 0;

        foreach (Zone::query()->whereIn('id', $data['zones'])->orderByDesc('level')->get() as $zone) {
            $selected = [];
            foreach ($data['apply'][$zone->id] ?? [] as $key) {
                $selected[$key] = $data['fp'][$zone->id][$key] ?? '';
            }
            if ($selected === []) {
                continue;
            }

            try {
                $sync = new NpmSync(NpmClient::fromStored($zone), $zone);
                $result = $mode === 'import' ? $sync->applyImport($selected) : $sync->applyPush($selected);
            } catch (NpmException $e) {
                $result = ['applied' => 0, 'results' => [['title' => 'Nginx Proxy Manager', 'outcome' => 'failed', 'message' => $e->getMessage()]]];
            }

            $applied += $result['applied'];
            foreach ($result['results'] as $r) {
                $failures += $r['outcome'] === 'failed' ? 1 : 0;
                $results[] = ['title' => $zone->name.': '.$r['title']] + $r;
            }
        }

        return redirect()->route('npm.index')
            ->with('status', "{$applied} change(s) applied".($failures ? ", {$failures} failed (see below)" : '').'.')
            ->with('npm_results', $results);
    }

    private function preview(Request $request, string $mode, \Closure $plan)
    {
        $zone = $this->zone($request);

        try {
            $items = $plan(new NpmSync(NpmClient::fromStored($zone), $zone));
        } catch (NpmException $e) {
            return redirect()->route('npm.index', $this->zp($zone))->withErrors(['npm' => $e->getMessage()]);
        }

        return view('npm.plan', ['mode' => $mode, 'items' => $items, 'zone' => $zone, 'zp' => $this->zp($zone)]);
    }

    private function apply(Request $request, string $mode, \Closure $run): RedirectResponse
    {
        $zone = $this->zone($request);
        $data = $request->validate([
            'apply' => ['required', 'array', 'min:1'],
            'apply.*' => ['string', 'max:100'],
            'fp' => ['required', 'array'],
            'fp.*' => ['string', 'size:40'],
        ], ['apply.required' => 'Tick at least one change to apply.']);

        // The fingerprint of each ticked item is what the admin was shown in the preview.
        $selected = [];
        foreach ($data['apply'] as $key) {
            $selected[$key] = $data['fp'][$key] ?? '';
        }

        try {
            $result = $run(new NpmSync(NpmClient::fromStored($zone), $zone), $selected);
        } catch (NpmException $e) {
            return redirect()->route($mode === 'import' ? 'npm.import' : 'npm.push', $this->zp($zone))->withErrors(['npm' => $e->getMessage()]);
        }

        return redirect()->route('npm.index', $this->zp($zone))
            ->with('status', "{$result['applied']} change(s) applied.")
            ->with('npm_results', $result['results']);
    }

    /** The zone a request is about: `zone` (query or form), else the default zone. */
    private function zone(Request $request): Zone
    {
        $id = $request->input('zone', $request->query('zone'));

        return ($id !== null && $id !== '' ? Zone::query()->findOrFail((int) $id) : Zones::default()) ?? abort(404);
    }

    /** The route parameters that carry the zone: none in a single-zone lab, so its addresses stay as they were. @return array<string, int> */
    private function zp(Zone $zone): array
    {
        return Zones::multiple() ? ['zone' => $zone->id] : [];
    }
}
