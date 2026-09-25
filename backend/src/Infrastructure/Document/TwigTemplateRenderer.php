<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Document;

use Kanso\Core\Internal\Domain\Document\DocumentType;
use Kanso\Core\Internal\Domain\Document\TemplateRendererInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Document templates are Twig (Pimsen's choice for generated documents),
 * in `templates/documents/`, with HTML autoescaping and strict variables, so
 * a typo in a template fails loudly instead of printing an empty box.
 *
 * The templates are the core's own for now. When customers can edit them,
 * they must run in Twig's sandbox (as in Pimsen) — see ADR-0007.
 */
final class TwigTemplateRenderer implements TemplateRendererInterface
{
    private readonly Environment $twig;

    public function __construct(string $templateDir)
    {
        // No compiled-template cache: two small templates compile in
        // milliseconds, and a cache would be local disk the worker keeps.
        $this->twig = new Environment(new FilesystemLoader($templateDir), [
            'autoescape' => 'html',
            'strict_variables' => true,
            'cache' => false,
        ]);
    }

    public function render(DocumentType $type, array $data): string
    {
        return $this->twig->render($type->template(), $data);
    }
}
