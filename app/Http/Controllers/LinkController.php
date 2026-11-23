<?php

namespace App\Http\Controllers;

use App\Models\Link;
use App\Rules\Check;
use App\Support\Access;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Plain bookmarks for the dashboard; admins manage them. */
class LinkController extends Controller
{
    public function index()
    {
        return view('links.index', ['links' => Link::query()->orderBy('group')->orderBy('name')->get()]);
    }

    public function create()
    {
        return view('links.form', ['link' => new Link]);
    }

    public function store(Request $request): RedirectResponse
    {
        Link::create($this->data($request));

        return redirect()->route('links.index')->with('status', 'Link added.');
    }

    public function edit(Link $link)
    {
        return view('links.form', ['link' => $link]);
    }

    public function update(Request $request, Link $link): RedirectResponse
    {
        $link->update($this->data($request, $link));

        return redirect()->route('links.index')->with('status', 'Link updated.');
    }

    public function destroy(Link $link): RedirectResponse
    {
        $link->delete();

        return redirect()->route('links.index')->with('status', 'Link deleted.');
    }

    /** @return array<string, mixed> */
    private function data(Request $request, ?Link $link = null): array
    {
        abort_unless(Access::isAdmin($request->user()), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('links', 'name')->ignore($link)],
            // http(s) only: a bookmark must never be a javascript: or data: address.
            'url' => ['required', 'string', 'max:2000', 'url:http,https'],
            'group' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:500'],
            'icon' => ['nullable', 'string', 'max:80', Check::icon()],
        ]);
        $data['icon'] = filled($data['icon'] ?? null) ? strtolower($data['icon']) : null;

        return $data;
    }
}
