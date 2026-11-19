<?php

namespace App\Http\Controllers;

use App\Inventory\ChoiceForm;
use App\Inventory\Connector;
use App\Inventory\InventoryException;
use App\Inventory\InventorySync;
use App\Inventory\RepoConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Admin-only inventory sync: connect the repository, compare, review, apply. */
class InventoryController extends Controller
{
    public function index()
    {
        return view('inventory.index', ['connection' => RepoConfig::summary()]);
    }

    public function connect(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'repo_url' => ['required', 'string', 'max:300'],
            'branch' => ['required', 'string', 'max:100'],
            'path' => ['required', 'string', 'max:200'],
            'username' => ['required', 'string', 'max:100'],
            'token' => ['nullable', 'string', 'max:500'],
            'author_name' => ['required', 'string', 'max:100'],
            'author_email' => ['required', 'string', 'max:100'],
        ]);

        try {
            $result = Connector::connect($data);
        } catch (InventoryException $e) {
            $message = $e->getMessage();
            $field = match (true) {
                str_starts_with($message, 'Enter the access token') => 'token',
                str_starts_with($message, 'The git user name') => 'username',
                default => 'repo_url',
            };
            throw ValidationException::withMessages([$field => $message]);
        }

        return redirect()->route('inventory.index')->with('status', 'Connected. Read '.$result['hosts'].' host(s) from '.$result['path'].'.');
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

        return view('inventory.compare', ['plan' => $plan, 'old' => session()->getOldInput()]);
    }

    public function review(Request $request)
    {
        $choices = ChoiceForm::fromRequest($request);
        $deleteOld = $request->boolean('delete_old');

        try {
            $review = InventorySync::fromStored()->review($choices, $deleteOld);
        } catch (InventoryException $e) {
            return redirect()->route('inventory.compare')->withErrors(['inventory' => $e->getMessage()])->withInput($request->except('_token'));
        }

        return view('inventory.review', [
            'review' => $review,
            'choices' => ChoiceForm::toJson($choices),
            'deleteOld' => $deleteOld,
            'repo' => RepoConfig::summary(),
        ]);
    }

    public function apply(Request $request): RedirectResponse
    {
        $data = $request->validate(['choices' => ['required', 'string', 'max:200000'], 'fingerprint' => ['required', 'string', 'size:40']]);

        try {
            $result = InventorySync::fromStored()->apply(ChoiceForm::fromJson($data['choices']), $data['fingerprint'], $request->boolean('delete_old'));
        } catch (InventoryException $e) {
            return redirect()->route('inventory.compare')->withErrors(['inventory' => $e->getMessage()]);
        }

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
