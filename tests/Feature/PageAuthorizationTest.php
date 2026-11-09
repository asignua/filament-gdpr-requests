<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Feature;

use Asignua\FilamentGdprRequests\Enums\RequestType;
use Asignua\FilamentGdprRequests\GdprRequests;
use Asignua\FilamentGdprRequests\Resources\GdprRequestResource;
use Asignua\FilamentGdprRequests\Tests\TestCase;

/**
 * A real HTTP GET of every page, as the wrong and as the right user. Filament 5.11.2 stopped asking `canCreate()` /
 * `canView()` on the pages (it asks `getXAuthorizationResponse()`), so a rule that lives only in a `canX()` override opens the page.
 */
class PageAuthorizationTest extends TestCase
{
    public function test_the_create_page_refuses_a_user_without_the_manage_gate(): void
    {
        $this->actingAs($this->user('none'))->get(GdprRequestResource::getUrl('create'))->assertForbidden();
    }

    public function test_the_create_page_opens_for_a_manager(): void
    {
        $this->actingAs($this->user('manager'))->get(GdprRequestResource::getUrl('create'))->assertOk();
    }

    public function test_the_view_page_refuses_a_user_without_the_view_gate(): void
    {
        $request = GdprRequests::submit(RequestType::Access, 'ada@customer.example', 'Ada');

        $this->actingAs($this->user('none'))->get(GdprRequestResource::getUrl('view', ['record' => $request->ulid]))->assertForbidden();
    }

    public function test_the_view_page_opens_for_a_viewer(): void
    {
        $request = GdprRequests::submit(RequestType::Access, 'ada@customer.example', 'Ada');

        $this->actingAs($this->user('manager'))->get(GdprRequestResource::getUrl('view', ['record' => $request->ulid]))->assertOk();
    }

    public function test_the_erasure_page_refuses_a_user_without_the_erase_gate(): void
    {
        $request = GdprRequests::submit(RequestType::Erasure, 'ada@customer.example', 'Ada');

        $this->actingAs($this->user('manager'))->get(GdprRequestResource::getUrl('erasure', ['record' => $request->ulid]))->assertForbidden();
    }

    public function test_the_erasure_page_opens_for_the_officer(): void
    {
        $request = GdprRequests::submit(RequestType::Erasure, 'ada@customer.example', 'Ada');

        $this->actingAs($this->user('dpo'))->get(GdprRequestResource::getUrl('erasure', ['record' => $request->ulid]))->assertOk();
    }
}
