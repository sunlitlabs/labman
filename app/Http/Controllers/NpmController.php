<?php

namespace App\Http\Controllers;

use App\Npm\Connection;
use App\Npm\NpmClient;
use App\Npm\NpmException;
use App\Npm\NpmSync;
use App\Npm\NpmWizard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Admin-only Nginx Proxy Manager screen: connect, then preview and apply import / push. */
class NpmController extends Controller
{
    public function index()
    {
        return view('npm.index', ['connection' => Connection::summary()]);
    }

    public function connect(Request $request): RedirectResponse
    {
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

        $first = Connection::summary() === null;
        Connection::save($url, $data['email'], $data['password'], $verify);

        // A new connection goes straight to the guided sync; changing details of an existing one stays here.
        return redirect()->route($first ? 'npm.wizard' : 'npm.index')->with('status', "Connected to Nginx Proxy Manager {$version}.");
    }

    /** The guided sync: everything that can be matched up between NPM and LabMan, with defaults chosen. */
    public function wizard(Request $request)
    {
        $only = $request->query('service') ? (int) $request->query('service') : null;

        try {
            $built = (new NpmWizard(new NpmSync(NpmClient::fromStored())))->build($only);
        } catch (NpmException $e) {
            return redirect()->route('npm.index')->withErrors(['npm' => $e->getMessage()]);
        }

        return view('npm.wizard', $built + ['only' => $only, 'connection' => Connection::summary()]);
    }

    public function wizardApply(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'choice' => ['required', 'array'],
            'choice.*' => ['in:import,push,skip'],
            'fp_import' => ['array'], 'fp_import.*' => ['string', 'size:40'],
            'push_key' => ['array'], 'push_key.*' => ['string', 'max:100'],
            'fp_push' => ['array'], 'fp_push.*' => ['string', 'size:40'],
        ]);

        $sel = NpmWizard::selections($data['choice'], $data['fp_import'] ?? [], $data['push_key'] ?? [], $data['fp_push'] ?? []);

        if ($sel['import'] === [] && $sel['push'] === []) {
            return redirect()->route('npm.index')->with('status', 'Nothing was selected, so nothing changed.');
        }

        try {
            $sync = new NpmSync(NpmClient::fromStored());
            $imported = $sel['import'] ? $sync->applyImport($sel['import']) : ['applied' => 0, 'results' => []];
            $pushed = $sel['push'] ? $sync->applyPush($sel['push']) : ['applied' => 0, 'results' => []];
        } catch (NpmException $e) {
            return redirect()->route('npm.wizard')->withErrors(['npm' => $e->getMessage()]);
        }

        $applied = $imported['applied'] + $pushed['applied'];

        return redirect()->route('npm.index')
            ->with('status', "{$applied} change(s) applied.")
            ->with('npm_results', array_merge($imported['results'], $pushed['results']));
    }

    public function disconnect(): RedirectResponse
    {
        Connection::forget();

        return redirect()->route('npm.index')->with('status', 'Disconnected. The saved NPM login was deleted.');
    }

    public function importPreview()
    {
        return $this->preview('import', fn (NpmSync $sync) => $sync->importPlan());
    }

    public function pushPreview()
    {
        return $this->preview('push', fn (NpmSync $sync) => $sync->pushPlan());
    }

    public function importApply(Request $request): RedirectResponse
    {
        return $this->apply($request, 'import', fn (NpmSync $s, array $sel) => $s->applyImport($sel));
    }

    public function pushApply(Request $request): RedirectResponse
    {
        return $this->apply($request, 'push', fn (NpmSync $s, array $sel) => $s->applyPush($sel));
    }

    private function preview(string $mode, \Closure $plan)
    {
        try {
            $items = $plan(new NpmSync(NpmClient::fromStored()));
        } catch (NpmException $e) {
            return redirect()->route('npm.index')->withErrors(['npm' => $e->getMessage()]);
        }

        return view('npm.plan', ['mode' => $mode, 'items' => $items]);
    }

    private function apply(Request $request, string $mode, \Closure $run): RedirectResponse
    {
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
            $result = $run(new NpmSync(NpmClient::fromStored()), $selected);
        } catch (NpmException $e) {
            return redirect()->route($mode === 'import' ? 'npm.import' : 'npm.push')->withErrors(['npm' => $e->getMessage()]);
        }

        return redirect()->route('npm.index')
            ->with('status', "{$result['applied']} change(s) applied.")
            ->with('npm_results', $result['results']);
    }
}
