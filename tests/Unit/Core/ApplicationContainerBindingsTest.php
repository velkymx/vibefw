<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Core;

use Fw\Core\Application;
use Fw\Core\HttpKernel;
use Fw\Core\View;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ApplicationContainerBindingsTest extends TestCase
{
    #[Test]
    public function containerBindsViewAfterBoot(): void
    {
        $app = Application::getInstance();

        $this->assertTrue(
            $app->getContainer()->has(View::class),
            'View must be registered in the container once the application has booted, '
            . 'so ApplicationBooted listeners can resolve it.',
        );
    }

    #[Test]
    public function containerBindsHttpKernelAfterBoot(): void
    {
        $app = Application::getInstance();

        $this->assertTrue(
            $app->getContainer()->has(HttpKernel::class),
            'HttpKernel must be registered in the container once the application has booted.',
        );
    }

    #[Test]
    public function registerContainerInstancesRunsAfterKernelConstruction(): void
    {
        $source = file_get_contents(BASE_PATH . '/src/Core/Application.php');
        $this->assertIsString($source);

        $kernelPos = strpos($source, '$this->kernel = new HttpKernel(');
        $this->assertNotFalse($kernelPos, 'Expected HttpKernel construction in Application.');

        $dispatchPos = strpos($source, '$this->events->dispatch(new ApplicationBooted(');
        $this->assertNotFalse($dispatchPos, 'Expected ApplicationBooted dispatch in Application.');

        $between = substr($source, $kernelPos, $dispatchPos - $kernelPos);
        $this->assertStringContainsString(
            '$this->registerContainerInstances();',
            $between,
            'registerContainerInstances() must run between HttpKernel construction '
            . 'and the ApplicationBooted dispatch so listeners see View/HttpKernel in the container.',
        );
    }
}
