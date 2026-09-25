<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Document;

use Dompdf\Dompdf;
use Dompdf\Options;
use Kanso\Core\Internal\Domain\Document\PdfRendererInterface;

/**
 * HTML to PDF in-process with dompdf, so an installation needs no extra
 * service to print. Locked down: no remote fetches, no PHP or JavaScript in
 * the HTML, and file access confined to the template directory.
 */
final class DompdfRenderer implements PdfRendererInterface
{
    public function __construct(private readonly string $templateDir)
    {
    }

    public function render(string $html): string
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setChroot([$this->templateDir]);
        $options->setTempDir(sys_get_temp_dir());
        // DejaVu ships with dompdf and has å, ä and ö; the PDF core fonts do not
        // cover everything a customer name can contain.
        $options->setDefaultFont('DejaVu Sans');
        $options->setDefaultPaperSize('a4');
        $options->setIsFontSubsettingEnabled(true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();
        $this->numberPages($dompdf);

        return (string) $dompdf->output();
    }

    /** "1 / 2" at the bottom right of every page, level with the template's footer. */
    private function numberPages(Dompdf $dompdf): void
    {
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        if (null === $font) {
            return;
        }

        $size = 7.5;
        $text = '{PAGE_NUM} / {PAGE_COUNT}';
        $width = $dompdf->getFontMetrics()->getTextWidth('00 / 00', $font, $size);
        // 16 mm right margin and 8 mm from the bottom edge, in points.
        $canvas->page_text($canvas->get_width() - 45.35 - $width, $canvas->get_height() - 22.7 - $size, $text, $font, $size, [0.47, 0.47, 0.47]);
    }
}
