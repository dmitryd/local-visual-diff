<?php

declare(strict_types=1);

namespace LocalVisualDiff\Command;

use LocalVisualDiff\Browser\BrowserLocator;
use LocalVisualDiff\Config\IniConfigurationLoader;
use LocalVisualDiff\Config\RunConfiguration;
use LocalVisualDiff\Run\ComparisonRunner;
use LocalVisualDiff\Support\Clock;
use LocalVisualDiff\Support\Filesystem;
use LocalVisualDiff\Crawl\UrlNormalizer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class CompareCommand extends Command
{
    public function __construct(
        private readonly IniConfigurationLoader $configurationLoader = new IniConfigurationLoader(),
        private readonly ComparisonRunner $runner = new ComparisonRunner(),
        private readonly BrowserLocator $browserLocator = new BrowserLocator(),
        private readonly UrlNormalizer $urlNormalizer = new UrlNormalizer(),
        private readonly Filesystem $filesystem = new Filesystem(),
        private readonly Clock $clock = new Clock()
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('compare')
            ->setDescription('Compare a baseline website against a candidate website visually.')
            ->addArgument(
                'baseline-base-url',
                InputArgument::REQUIRED,
                'The baseline site URL that acts as the reference for comparison.'
            )
            ->addArgument(
                'candidate-base-url',
                InputArgument::REQUIRED,
                'The candidate site URL that should match the baseline visually.'
            )
            ->addOption('output-dir', null, InputOption::VALUE_REQUIRED, 'Explicit output directory for this run.')
            ->addOption('browser-path', null, InputOption::VALUE_REQUIRED, 'Explicit browser executable path.')
            ->addOption('viewport-width', null, InputOption::VALUE_REQUIRED, 'Viewport width in pixels.')
            ->addOption('viewport-height', null, InputOption::VALUE_REQUIRED, 'Viewport height in pixels.')
            ->addOption('settle-ms', null, InputOption::VALUE_REQUIRED, 'Milliseconds to wait after navigation.')
            ->addOption('timeout-ms', null, InputOption::VALUE_REQUIRED, 'Milliseconds to wait for browser operations.')
            ->addOption('max-fullpage-height', null, InputOption::VALUE_REQUIRED, 'Maximum full-page height before fallback.')
            ->addOption('context-padding', null, InputOption::VALUE_REQUIRED, 'Padding around a large-page context artifact.')
            ->addOption('shift-tolerance', null, InputOption::VALUE_REQUIRED, 'Pixel shift tolerance for comparison.')
            ->addOption('color-threshold', null, InputOption::VALUE_REQUIRED, 'Color threshold for pixel comparison.')
            ->addOption('crawl-homepage-links', null, InputOption::VALUE_NONE, 'Enable homepage link crawling for later phases.')
            ->addOption('max-pages', null, InputOption::VALUE_REQUIRED, 'Maximum number of pages to compare.')
            ->addOption('capture-matching-screenshots', null, InputOption::VALUE_NONE, 'Keep screenshots for matching pages.')
            ->addOption('overwrite', null, InputOption::VALUE_NONE, 'Allow existing output directories to be reused.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $baselineBaseUrl = $this->urlNormalizer->normalizeBaseUrl((string) $input->getArgument('baseline-base-url'));
            $candidateBaseUrl = $this->urlNormalizer->normalizeBaseUrl((string) $input->getArgument('candidate-base-url'));
            $defaultsPath = dirname(__DIR__, 2) . '/config/defaults.ini';
            $localPath = $this->resolveLocalConfigPath();
            $config = $this->configurationLoader->load(
                $defaultsPath,
                $localPath,
                $this->collectCliOverrides($input),
                $baselineBaseUrl,
                $candidateBaseUrl
            );

            $resolvedBrowserPath = $this->browserLocator->resolve($config->browserPath, PHP_OS_FAMILY);
            $finalOutputDir = $this->resolveFinalOutputDir($config->outputDir, $input->getOption('output-dir'));
            $runConfiguration = new RunConfiguration(
                baselineBaseUrl: $baselineBaseUrl,
                candidateBaseUrl: $candidateBaseUrl,
                outputDir: $finalOutputDir,
                browserPath: $resolvedBrowserPath,
                viewportWidth: $config->viewportWidth,
                viewportHeight: $config->viewportHeight,
                settleMs: $config->settleMs,
                timeoutMs: $config->timeoutMs,
                maxFullpageHeight: $config->maxFullpageHeight,
                contextPadding: $config->contextPadding,
                shiftTolerance: $config->shiftTolerance,
                colorThreshold: $config->colorThreshold,
                overlayOpacity: $config->overlayOpacity,
                crawlHomepageLinks: $config->crawlHomepageLinks,
                maxPages: $config->maxPages,
                captureMatchingScreenshots: $config->captureMatchingScreenshots,
                overwrite: $config->overwrite,
                verbose: $output->isVerbose()
            );

            $summary = $this->runner->run($runConfiguration);
            $output->writeln(sprintf('Report written to %s', $summary->reportPath));

            return Command::SUCCESS;
        } catch (Throwable $throwable) {
            $output->getErrorOutput()->writeln($throwable->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * Convert the raw CLI options into the flat override array expected by the
     * configuration loader.
     *
     * @return array<string, mixed>
     */
    private function collectCliOverrides(InputInterface $input): array
    {
        $overrides = [
            'output_dir' => $input->getOption('output-dir'),
            'browser_path' => $input->getOption('browser-path'),
            'viewport_width' => $input->getOption('viewport-width'),
            'viewport_height' => $input->getOption('viewport-height'),
            'settle_ms' => $input->getOption('settle-ms'),
            'timeout_ms' => $input->getOption('timeout-ms'),
            'max_fullpage_height' => $input->getOption('max-fullpage-height'),
            'context_padding' => $input->getOption('context-padding'),
            'shift_tolerance' => $input->getOption('shift-tolerance'),
            'color_threshold' => $input->getOption('color-threshold'),
            'crawl_homepage_links' => $input->getOption('crawl-homepage-links'),
            'max_pages' => $input->getOption('max-pages'),
            'capture_matching_screenshots' => $input->getOption('capture-matching-screenshots'),
            'overwrite' => $input->getOption('overwrite'),
        ];

        return array_filter(
            $overrides,
            static fn (mixed $value): bool => $value !== null && $value !== false
        );
    }

    private function resolveLocalConfigPath(): ?string
    {
        $localPath = dirname(__DIR__, 2) . '/config/local.ini';

        return is_file($localPath) ? $localPath : null;
    }

    /**
     * @param string|null $configuredOutputDir parent directory from configuration
     * @param mixed       $explicitOutputDir   explicit CLI override, if provided
     */
    private function resolveFinalOutputDir(?string $configuredOutputDir, mixed $explicitOutputDir): string
    {
        $explicitOutputDir = is_string($explicitOutputDir) ? trim($explicitOutputDir) : '';

        if ($explicitOutputDir !== '') {
            return $explicitOutputDir;
        }

        $parentDirectory = $configuredOutputDir === null || trim($configuredOutputDir) === ''
            ? 'var/results'
            : $configuredOutputDir;

        return $this->filesystem->joinPath(
            $parentDirectory,
            'run-' . $this->clock->timestampForRunDirectory()
        );
    }
}
