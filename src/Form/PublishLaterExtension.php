<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentScheduler\Form;

use Ibexa\ContentForms\Form\Type\Content\ContentEditType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Wizhippo\IbexaContentScheduler\Security\ContentSchedulerPolicy;

final class PublishLaterExtension extends AbstractTypeExtension
{
    public function __construct(private readonly ContentSchedulerPolicy $policy)
    {
    }

    public static function getExtendedTypes(): iterable
    {
        return [ContentEditType::class];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($this->policy->isGranted(ContentSchedulerPolicy::FUNCTION_VIEW) && $this->policy->isGranted(ContentSchedulerPolicy::FUNCTION_SCHEDULE)) {
            $builder->add('publishLater', SubmitType::class, ['label' => 'Publish later', 'translation_domain' => false, 'attr' => ['hidden' => true]]);
        }
    }
}
