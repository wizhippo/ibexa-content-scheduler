<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentScheduler\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;
use Wizhippo\IbexaContentScheduler\Persistence\ScheduleStore;
use Wizhippo\IbexaContentScheduler\Service\Fingerprint;

final class BundleIntegrationTest extends KernelTestCase
{
    public function testTimeFieldsRenderNativePickersWithoutManualOffsets(): void
    {
        self::bootKernel();
        $html = self::getContainer()->get(Environment::class)->render('@WizhippoIbexaContentScheduler/time_fields.html.twig', [
            'date' => '2026-10-31', 'localTime' => '21:30', 'timezone' => 'America/Toronto',
            'timezones' => ['America/Toronto', 'UTC'], 'timeOccurrences' => [], 'occurrence' => '',
        ]);
        self::assertStringContainsString('type="date"', $html);
        self::assertStringContainsString('type="time"', $html);
        self::assertStringContainsString('value="21:30"', $html);
        self::assertStringContainsString('value="America/Toronto" selected', $html);
        self::assertStringNotContainsString('name="occurrence"', $html);
        self::assertStringNotContainsString('name="offset"', $html);
    }

    public function testStorageUsesExactRepositoryConnection(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        self::assertSame($container->get('ibexa.persistence.connection'), $container->get(ScheduleStore::class)->connection);
    }

    public function testRoutesAndTemplatesAreRegistered(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $router = $container->get(RouterInterface::class);
        self::assertSame('/content-scheduler', $router->generate('wizhippo_schedule_list'));
        foreach (['create', 'detail', 'list', 'dashboard', 'base', 'time_fields'] as $name) {
            self::assertTrue($container->get(Environment::class)->getLoader()->exists('@WizhippoIbexaContentScheduler/'.$name.'.html.twig'));
        }
        self::assertInstanceOf(FormFactoryInterface::class, $container->get(FormFactoryInterface::class));
        self::assertInstanceOf(Fingerprint::class, $container->get(Fingerprint::class));
        $extensions = $container->get(\Symfony\Component\Form\FormRegistryInterface::class)
            ->getType(\Ibexa\ContentForms\Form\Type\Content\ContentEditType::class)->getTypeExtensions();
        self::assertNotEmpty(array_filter($extensions, static fn ($extension): bool => $extension instanceof \Wizhippo\IbexaContentScheduler\Form\PublishLaterExtension));
    }
}
