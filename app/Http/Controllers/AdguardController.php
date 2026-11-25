<?php

namespace App\Http\Controllers;

use App\Dns\AdguardClient;
use App\Dns\AdguardConnection;
use App\Dns\AdguardException;
use App\Dns\AdguardSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Admin: connect AdGuard Home, preview the DNS rewrites the plan needs, and write the ones that were approved. */
class AdguardController extends Controller
{
    public function index()
    {
        $connection = AdguardConnection::summary();
        $version = null;
        $error = null;

        if ($connection) {
            try {
                $version = AdguardClient::fromStored()->test();
            } catch (AdguardException $e) {
                $error = $e->getMessage();
            }
        }

        return view('dns.adguard', ['connection' => $connection, 'version' => $version, 'error' => $error]);
    }

    public function connect(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'verify_tls' => ['sometimes', 'boolean'],
        ]);

        try {
            $url = AdguardConnection::cleanUrl($data['url']);
            $verify = $request->boolean('verify_tls');
            $login = ['username' => (string) ($data['username'] ?? ''), 'password' => (string) ($data['password'] ?? '')];
            $version = (new AdguardClient(['url' => $url, 'verify_tls' => $verify] + $login))->test();
        } catch (AdguardException $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }

        AdguardConnection::save($url, $login['username'], $login['password'], $verify);

        return redirect()->route('dns.adguard')->with('status', "Connected to AdGuard Home {$version}.");
    }

    public function disconnect(): RedirectResponse
    {
        AdguardConnection::forget();

        return redirect()->route('dns.adguard')->with('status', 'AdGuard Home disconnected. Nothing in AdGuard was changed.');
    }

    public function preview()
    {
        try {
            $plan = (new AdguardSync(AdguardClient::fromStored()))->plan();
        } catch (AdguardException $e) {
            return redirect()->route('dns.adguard')->withErrors(['adguard' => $e->getMessage()]);
        }

        return view('dns.adguard-plan', ['items' => $plan['items'], 'others' => $plan['others']]);
    }

    public function apply(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'apply' => ['required', 'array', 'min:1'],
            'apply.*' => ['string', 'max:300'],
            'fp' => ['required', 'array'],
            'fp.*' => ['string', 'size:40'],
        ], ['apply.required' => 'Tick at least one change to apply.']);

        $selected = [];
        foreach ($data['apply'] as $key) {
            $selected[$key] = $data['fp'][$key] ?? '';
        }

        try {
            $result = (new AdguardSync(AdguardClient::fromStored()))->apply($selected);
        } catch (AdguardException $e) {
            return redirect()->route('dns.adguard.preview')->withErrors(['adguard' => $e->getMessage()]);
        }

        return redirect()->route('dns.adguard')
            ->with('status', "{$result['applied']} change(s) written to AdGuard Home.")
            ->with('adguard_results', $result['results']);
    }
}
