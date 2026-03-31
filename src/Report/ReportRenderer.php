<?php

declare(strict_types=1);

namespace LocalVisualDiff\Report;

use RuntimeException;
use TYPO3Fluid\Fluid\View\TemplateView;

/**
 * Render the static HTML report for a visual comparison run.
 *
 * The renderer is intentionally tiny: it only configures Fluid with the
 * template paths used by this project, assigns the prepared view model, and
 * returns the final HTML string. Keeping the rendering boundary narrow makes it
 * easier to reason about later when the run orchestration starts passing richer
 * data structures into the report.
 */
final class ReportRenderer
{
    public function __construct(
        private readonly string $templateRootPath = __DIR__ . '/../../templates/report'
    ) {
    }

    /**
     * Render the report template with a normalized view model.
     *
     * @param array<string, mixed> $viewModel
     */
    public function render(array $viewModel): string
    {
        $this->assertTemplateRootExists();

        $view = new TemplateView();
        $templatePaths = $view->getRenderingContext()->getTemplatePaths();
        $templatePaths->setTemplateRootPaths([$this->templateRootPath]);
        $templatePaths->setPartialRootPaths([$this->templateRootPath . '/Partials']);

        $view->assignMultiple($viewModel);

        return $view->render('Report');
    }

    private function assertTemplateRootExists(): void
    {
        if (!is_dir($this->templateRootPath)) {
            throw new RuntimeException(sprintf(
                'Report template root not found: %s',
                $this->templateRootPath
            ));
        }

        $templateFile = $this->templateRootPath . DIRECTORY_SEPARATOR . 'Report.html';

        if (!is_file($templateFile)) {
            throw new RuntimeException(sprintf(
                'Main report template not found: %s',
                $templateFile
            ));
        }
    }
}
