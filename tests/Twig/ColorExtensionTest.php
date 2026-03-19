<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\ColorExtension;
use PHPUnit\Framework\TestCase;

class ColorExtensionTest extends TestCase
{
    private ColorExtension $ext;

    protected function setUp(): void
    {
        $this->ext = new ColorExtension();
    }

    public function testDarkColorGetsWhiteText(): void
    {
        $this->assertSame('#ffffff', $this->ext->contrastColor('#1a56db')); // dark blue
        $this->assertSame('#ffffff', $this->ext->contrastColor('#000000')); // black
        $this->assertSame('#ffffff', $this->ext->contrastColor('#dc3545')); // Bootstrap red
    }

    public function testLightColorGetsBlackText(): void
    {
        $this->assertSame('#000000', $this->ext->contrastColor('#ffffff')); // white
        $this->assertSame('#000000', $this->ext->contrastColor('#ffc107')); // Bootstrap yellow
        $this->assertSame('#000000', $this->ext->contrastColor('#f8f9fa')); // Bootstrap light
    }

    public function testShortHexExpanded(): void
    {
        $this->assertSame('#ffffff', $this->ext->contrastColor('#000')); // short black
        $this->assertSame('#000000', $this->ext->contrastColor('#fff')); // short white
    }

    public function testWithoutHash(): void
    {
        $this->assertSame('#ffffff', $this->ext->contrastColor('000000'));
        $this->assertSame('#000000', $this->ext->contrastColor('ffffff'));
    }
}
