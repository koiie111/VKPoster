<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Domain\Help\HelpArticles;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Response;
use App\Kernel\View\View;
use App\Support\Markdown;

/**
 * The knowledge base: the list of articles and one article.
 */
final class HelpController
{
    public function __construct(private readonly View $view, private readonly HelpArticles $articles)
    {
    }

    public function index(): Response
    {
        return $this->view->response('help/index.twig', [
            'articles' => $this->articles->all(),
            'indexable' => true,
            'seo_title' => 'Справка',
            'seo_description' => 'Как подключить Telegram, ВКонтакте и MAX, запланировать пост и оплатить тариф.',
            'canonical_path' => '/help',
        ]);
    }

    public function show(string $slug): Response
    {
        $article = $this->articles->find($slug) ?? throw new HttpException(404, 'Not found');

        return $this->view->response('help/show.twig', [
            'article' => $article,
            'html' => Markdown::toHtml($article->markdown),
            'toc' => Markdown::headings($article->markdown),
            'articles' => $this->articles->all(),
            'indexable' => true,
            'seo_title' => $article->title,
            'seo_description' => $article->summary,
            'canonical_path' => '/help/' . $article->slug,
        ]);
    }
}
