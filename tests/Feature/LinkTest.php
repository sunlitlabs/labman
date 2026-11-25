<?php

namespace Tests\Feature;

use App\Models\Link;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Bookmarks for pages that are neither services nor servers. */
class LinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_adds_edits_and_deletes_a_link(): void
    {
        $this->actingAs($this->admin());

        $this->post('/links', ['name' => 'Network controller', 'url' => 'https://controller.example.com:8043', 'group' => 'Network'])
            ->assertRedirect(route('links.index'))->assertSessionHasNoErrors();
        $link = Link::where('name', 'Network controller')->firstOrFail();
        $this->assertSame('Network', $link->group);

        $this->get(route('links.index'))->assertOk()->assertSee('Network controller');
        $this->get(route('links.edit', $link))->assertOk();

        $this->put(route('links.update', $link), ['name' => 'Controller', 'url' => 'https://controller.example.com'])->assertSessionHasNoErrors();
        $this->assertSame('Controller', $link->fresh()->name);

        $this->delete(route('links.destroy', $link));
        $this->assertSame(0, Link::count());
    }

    public function test_only_web_addresses_are_accepted(): void
    {
        $this->actingAs($this->admin());

        foreach (['javascript:alert(1)', 'ftp://files.example.com', 'data:text/html,hi', 'not a url'] as $bad) {
            $this->post('/links', ['name' => 'Bad', 'url' => $bad])->assertSessionHasErrors('url');
        }
        $this->assertSame(0, Link::count());
    }

    public function test_links_show_on_the_dashboard_and_open_in_a_new_tab(): void
    {
        $this->admin();
        Link::create(['name' => 'Cloud dashboard', 'url' => 'https://cloud.example.org/home', 'group' => 'Cloud']);

        $this->actingAs($this->user())->get('/')->assertOk()
            ->assertSee('Links · Cloud')->assertSee('Cloud dashboard')
            ->assertSee('data-href="https://cloud.example.org/home"', false);
    }

    public function test_management_is_admin_only(): void
    {
        $this->admin();
        $link = Link::create(['name' => 'Cloud', 'url' => 'https://cloud.example.org']);

        $this->actingAs($this->user());
        $this->get(route('links.index'))->assertOk()->assertDontSee('Add link')->assertDontSee('/edit', false);
        $this->post('/links', ['name' => 'X', 'url' => 'https://example.com'])->assertForbidden();
        $this->delete(route('links.destroy', $link))->assertForbidden();
        $this->assertSame(1, Link::count());
    }
}
