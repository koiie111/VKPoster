<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Domain\Legal\LegalDocuments;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Response;
use App\Kernel\View\View;
use App\Support\Markdown;

/**
 * Public legal pages (`/legal/offer`, `/legal/privacy`, `/legal/cookies`, `/legal/requisites`) rendered from `resources/legal`.
 */
final class LegalController
{
    public function __construct(private readonly View $view, private readonly LegalDocuments $documents)
    {
    }

    public function show(string $slug): Response
    {
        $document = $this->documents->find($slug) ?? throw new HttpException(404, 'Not found');

        return $this->view->response('legal/show.twig', [
            'document' => $document,
            'html' => Markdown::toHtml($document->markdown),
            'documents' => $this->documents->all(),
            'indexable' => true,
            'seo_title' => $document->title,
            'seo_description' => $document->title . ' сервиса',
            'canonical_path' => '/legal/' . $document->slug,
        ]);
    }
}
