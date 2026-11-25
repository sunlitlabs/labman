<?php

namespace App\Http\Controllers;

use App\Domain\LinkInput;
use App\Models\Link;
use App\Support\Access;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Bookmarks for other web pages. Anyone who may see the dashboard sees the list; only admins change it. */
class LinkController extends Controller
{
    public function index(Request $request)
    {
        if (! Gate::allows('view-screen', 'services') && ! Gate::allows('view-screen', 'servers')) {
            Access::deny($request);
        }

        $links = Link::query()->orderBy('group')->orderBy('name')->get();

        return view('links.index', ['links' => $links, 'grouped' => $links->groupBy(fn (Link $l) => $l->group ?: '')]);
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

        return LinkInput::validate($request->only(['name', 'url', 'group', 'notes', 'icon', 'on_dashboard']), $link);
    }
}