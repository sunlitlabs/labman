<?php

namespace App\Http\Controllers;

use App\Inventory\ChoiceForm;
use App\Inventory\Connector;
use App\Inventory\InventoryException;
use App\Inventory\InventorySync;
use App\Inventory\RepoConfig;
use App\Inventory\RepoUrl;
use App\Inventory\SyncStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Admin-only inventory sync: connect the repository, compare, review, apply. */
class InventoryController extends Controller
{
    public function index()
    {
        return view('inventory.index', ['connection' => RepoConfig::summary(), 'last' => SyncStatus::last()]);
    }

    public function connect(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'repo_url' => ['required', 'string', 'max:500'],
            'branch' => ['nullable', 'string', 'max:100'],
            'path' => ['nullable', 'string', 'max:200'],
            'username' => ['nullable', 'string', 'max:100'],
            'token' => ['nullable', 'string', 'max:500'],
            'author_name' => ['nullable', 'string', 'max:100'],
            'author_email' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $result = Connector::connect($data);
        } catch (InventoryException $e) {
            $message = $e->getMessage();
            $field = match (true) {
                str_starts_with($message, 'Enter the access token') => 'token',
                str_starts_with($message, 'The git user name') => 'username',
                str_starts_with($message, 'Which file') || str_contains($message, 'does not exist on branch') || str_contains($message, 'inventory file path') => 'path',
                default => 'repo_url',
            };
            throw ValidationException::withMessages([$field => $message]);
        }

        // Straight to the comparison, so the first thing after connecting is matching hosts to servers.
        return redirect()->route('inventory.compare')->with('status', 'Connected as '.$result['username'].'. Read '.$result['hosts'].' host(s) from '.$result['path'].'. Here is how they compare with your servers.');
    }

    /** Fills the connection form from a pasted link (called by the form's script as you paste). */
    public function parseLink(Request $request): JsonResponse
    {
        $parsed = RepoUrl::parse((string) $request->query('url', ''));

        return response()->json($parsed === null ? ['ok' => false] : ['ok' => true] + $parsed)->header('Cache-Control', 'no-store');
    }

    public function disconnect(): RedirectResponse
    {
        RepoConfig::forget();

        return redirect()->route('inventory.index')->with('status', 'Disconnected. The saved repository token was deleted. Nothing in the repository was changed.');
    }

    public function compare()
    {
        try {
            $plan = InventorySync::fromStored()->compare();
        } catch (InventoryException $e) {
            return redirect()->route('inventory.index')->withErrors(['inventory' => $e->getMessage()]);
        }

        return view('inventory.compare', ['plan' => $plan, 'old' => session()->getOldInput(), 'last' => SyncStatus::last()]);
    }

    public function review(Request $request)
    {
        $choices = ChoiceForm::fromRequest($request);
        $deleteOld = $request->boolean('delete_old');
        $convert = $request->boolean('convert');

        try {
            $review = InventorySync::fromStored()->review($choices, $deleteOld, $convert);
        } catch (InventoryException $e) {
            return redirect()->route('inventory.compare')->withErrors(['inventory' => $e->getMessage()])->withInput($request->except('_token'));
        }

        return view('inventory.review', [
            'review' => $review,
            'choices' => ChoiceForm::toJson($choices),
            'deleteOld' => $deleteOld,
            'convert' => $convert,
            'repo' => RepoConfig::summary(),
        ]);
    }

    public function apply(Request $request): RedirectResponse
    {
        $data = $request->validate(['choices' => ['required', 'string', 'max:200000'], 'fingerprint' => ['required', 'string', 'size:40']]);

        $where = RepoConfig::summary() ?? ['branch' => '', 'path' => ''];

        try {
            $result = InventorySync::fromStored()->apply(ChoiceForm::fromJson($data['choices']), $data['fingerprint'], $request->boolean('delete_old'), $request->boolean('convert'));
        } catch (InventoryException $e) {
            SyncStatus::failed($e->getMessage(), $where['branch'], $where['path']);

            return redirect()->route('inventory.compare')->withErrors(['inventory' => $e->getMessage()]);
        }

        SyncStatus::succeeded($result, $where['branch'], $where['path']);

        $parts = [];
        if ($result['pushed']) {
            $parts[] = 'pushed commit '.substr((string) $result['commit'], 0, 7).' to the repository';
        }
        if ($result['labman'] > 0) {
            $parts[] = $result['labman'].' LabMan change(s) saved';
        }

        return redirect()->route('inventory.index')->with('status', 'Done: '.($parts ? implode(' and ', $parts) : 'nothing needed changing').'.');
    }
}
