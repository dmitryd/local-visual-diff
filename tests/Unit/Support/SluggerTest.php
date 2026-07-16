<?php

declare(strict_types=1);

namespace LocalVisualDiff\Tests\Unit\Support;

use LocalVisualDiff\Support\Slugger;
use PHPUnit\Framework\TestCase;

final class SluggerTest extends TestCase
{
    public function testLongPathsAreShortenedToFilesystemSafeNames(): void
    {
        $slugger = new Slugger();
        $path = '/fr/themes/food/projets-soutenus-dans-le-cadre-de-linitiative-food-40-systeme-de-mesure-pour-la-caracterisation-en-ligne-de-la-texture-de-produits-alimentaires-contenant-des-proteines-structurees-vegetales-hybrides-dans-le-contexte-de-la-numerisation-de-la-chaine-de-valeur';

        $slug = $slugger->slugPath($path);

        self::assertLessThanOrEqual(255, strlen($slug));
        self::assertMatchesRegularExpression('/-[a-f0-9]{8}$/', $slug);
        self::assertStringStartsWith('fr-themes-food-projets-soutenus', $slug);
    }

    public function testDifferentLongPathsKeepDifferentHashSuffixes(): void
    {
        $slugger = new Slugger();
        $commonPrefix = '/section/' . str_repeat('same-segment-', 40);

        self::assertNotSame(
            $slugger->slugPath($commonPrefix . 'first'),
            $slugger->slugPath($commonPrefix . 'second')
        );
    }
}
