<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Tests\Feature;

use Asignua\FilamentGdprRequests\DataMap\Erase;
use Asignua\FilamentGdprRequests\DataMap\PersonalData;
use Asignua\FilamentGdprRequests\DataMap\Rule;
use Asignua\FilamentGdprRequests\Services\ErasureService;
use Asignua\FilamentGdprRequests\Tests\Support\RunsErasures;
use Asignua\FilamentGdprRequests\Tests\Support\Shop;
use Asignua\FilamentGdprRequests\Tests\TestCase;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Document;

/**
 * A row that stays but stops naming its file loses the file as surely as a deleted row.
 */
class FileOrphanTest extends TestCase
{
    use RunsErasures;

    public function test_an_anonymization_that_moves_the_file_path_without_removing_the_file_blocks(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $this->registry()->forget(Document::class);
        PersonalData::for(Document::class)->of(Customer::class)->matchedBy('customer_id')
            ->files(static fn (Document $document): array => [['disk' => $document->disk, 'path' => $document->path]])
            ->erase(Erase::anonymize(['path' => Rule::fixed('erased')]));

        $this->assertContains('files_orphaned', $this->codes(app(ErasureService::class)->plan($this->erasureOf($ada))));
    }

    public function test_an_anonymization_that_leaves_the_path_alone_does_not_block(): void
    {
        $ada = Shop::customer('Ada', 'ada@customer.example');
        $this->registry()->forget(Document::class);
        PersonalData::for(Document::class)->of(Customer::class)->matchedBy('customer_id')
            ->files(static fn (Document $document): array => [['disk' => $document->disk, 'path' => $document->path]])
            ->erase(Erase::anonymize(['title' => Rule::fixed('Erased')]));

        $this->assertNotContains('files_orphaned', $this->codes(app(ErasureService::class)->plan($this->erasureOf($ada))));
    }
}
