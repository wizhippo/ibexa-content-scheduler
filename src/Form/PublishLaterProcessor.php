<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentScheduler\Form;

use Ibexa\ContentForms\Data\Content\ContentCreateData;
use Ibexa\ContentForms\Data\Content\ContentUpdateData;
use Ibexa\ContentForms\Event\FormActionEvent;
use Ibexa\Contracts\Core\Repository\Repository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Wizhippo\IbexaContentScheduler\Security\ContentSchedulerPolicy;
use Wizhippo\IbexaContentScheduler\Service\ContentAccess;

final class PublishLaterProcessor implements EventSubscriberInterface
{
    public function __construct(private readonly Repository $repository, private readonly ContentAccess $access, private readonly UrlGeneratorInterface $urls, private readonly ContentSchedulerPolicy $policy)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return ['content.edit.publishLater' => 'saveAndChooseTime'];
    }

    public function saveAndChooseTime(FormActionEvent $event): void
    {
        if (!$this->policy->isGranted(ContentSchedulerPolicy::FUNCTION_VIEW) || !$this->policy->isGranted(ContentSchedulerPolicy::FUNCTION_SCHEDULE)) {
            throw new AccessDeniedException('Scheduling permission is required.');
        }
        $data = $event->getData();
        if (!$data instanceof ContentCreateData && !$data instanceof ContentUpdateData) {
            throw new \LogicException('Unsupported content form data.');
        }
        $language = $event->getForm()->getConfig()->getOption('languageCode');
        $main = $data instanceof ContentCreateData ? $data->mainLanguageCode : $data->getContentDraft()->getContentInfo()->getMainLanguageCode();
        foreach ($data->getFieldsData() as $identifier => $field) {
            if ($language === $main || $field->getFieldDefinition()->isTranslatable()) {
                $data->setField($identifier, $field->getValue(), $language);
            }
        }
        $service = $this->repository->getContentService();
        // Full field validation: no draft-save suppression or empty validation field list.
        $draft = $data instanceof ContentCreateData
            ? $service->createContent($data, $data->getLocationStructs())
            : $service->updateContent($data->getContentDraft()->getVersionInfo(), $data);
        $this->access->authorize($draft, 'publish', [$language]);
        $event->setPayload('content', $draft);
        $event->setResponse(new RedirectResponse($this->urls->generate('wizhippo_schedule_create', [
            'contentId' => $draft->getId(), 'action' => 'publish', 'version' => $draft->getVersionInfo()->getVersionNo(), 'language' => $language,
        ])));
    }
}
