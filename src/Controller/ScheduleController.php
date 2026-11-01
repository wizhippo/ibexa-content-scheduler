<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentScheduler\Controller;

use Ibexa\Contracts\Core\Repository\Exceptions\NotFoundException;
use Ibexa\Contracts\Core\Repository\Exceptions\UnauthorizedException;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Wizhippo\IbexaContentScheduler\Domain\ExecutionTime;
use Wizhippo\IbexaContentScheduler\Domain\ScheduleException;
use Wizhippo\IbexaContentScheduler\Persistence\ScheduleStore;
use Wizhippo\IbexaContentScheduler\Security\ContentSchedulerPolicy;
use Wizhippo\IbexaContentScheduler\Service\ContentAccess;
use Wizhippo\IbexaContentScheduler\Service\ScheduleService;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ScheduleController extends AbstractController
{
    public function __construct(private readonly ScheduleStore $store, private readonly ContentAccess $access, private readonly ScheduleService $schedules, private readonly ClockInterface $clock, private readonly ContentSchedulerPolicy $policy)
    {
    }

    #[Route('/content-scheduler', name: 'wizhippo_schedule_list', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->requireView();
        $offset = max(0, min(1000000, $request->query->getInt('offset')));
        $mine = $request->query->getBoolean('mine', true);
        $contentId = $request->query->getInt('contentId') ?: null;
        $status = $request->query->getString('status');
        if (!in_array($status, ['', 'pending', 'completed', 'cancelled', 'failed', 'conflict'], true)) {
            throw $this->createNotFoundException('Unknown status.');
        }
        $content = $contentId ? $this->read($contentId) : null;
        $raw = $this->store->page($offset, $mine ? $this->access->actor() : null, $contentId, $status);
        $rows = [];
        foreach ($raw as $row) {
            try {
                $item = $this->access->read((int) $row['content_id'], (int) $row['version_no']);
                $rows[] = $row + ['content_name' => $item->getName()];
            } catch (NotFoundException|UnauthorizedException|AccessDeniedException) {
                // Do not disclose names, identifiers, dates, or audit data of inaccessible content.
            }
        }

        return $this->view('list', ['rows' => $rows, 'mine' => $mine, 'status' => $status, 'content' => $content, 'offset' => $offset, 'hasMore' => count($raw) === 25, 'canSchedule' => $this->policy->isGranted(ContentSchedulerPolicy::FUNCTION_SCHEDULE)]);
    }

    #[Route('/content-scheduler/content/{contentId}/{action}/{version}', name: 'wizhippo_schedule_create', requirements: ['contentId' => '[1-9][0-9]*', 'action' => 'publish|hide|reveal', 'version' => '\d+'], methods: ['GET', 'POST'])]
    public function create(int $contentId, string $action, int $version, Request $request): Response
    {
        $this->requireView();
        if (!$this->policy->isGranted(ContentSchedulerPolicy::FUNCTION_SCHEDULE)) {
            throw $this->createAccessDeniedException();
        }
        $content = $this->read($contentId, $action === 'publish' ? $version : 0);
        $languages = $action === 'publish' ? $content->getVersionInfo()->getLanguageCodes() : [];
        $selected = $request->isMethod('POST') ? $request->request->all('languages') : [$request->query->getString('language', $content->getVersionInfo()->getInitialLanguage()->getLanguageCode())];
        $error = null;
        if ($request->isMethod('POST')) {
            $this->csrf($request, 'schedule_create_'.$contentId.'_'.$action.'_'.$version);
            try {
                $timezone = $request->request->getString('timezone');
                $time = $this->submittedTime($request);
                $id = $this->schedules->create($contentId, $action, $version, $selected, $time, $timezone);

                return $this->redirectToRoute('wizhippo_schedule_detail', ['id' => $id], Response::HTTP_SEE_OTHER);
            } catch (ScheduleException $exception) {
                $error = $exception->getMessage();
            } catch (UnauthorizedException) {
                throw $this->createAccessDeniedException('You cannot schedule this action.');
            }
        }

        $fields = $this->timeFields($request, $this->clock->now()->modify('+1 hour'), date_default_timezone_get());

        return $this->view('create', ['content' => $content, 'action' => $action, 'version' => $version, 'languages' => $languages, 'selected' => $selected, 'error' => $error] + $fields, $error ? 422 : 200);
    }

    #[Route('/content-scheduler/{id}', name: 'wizhippo_schedule_detail', requirements: ['id' => '[a-f0-9]{32}'], methods: ['GET', 'POST'])]
    public function detail(string $id, Request $request): Response
    {
        $this->requireView();
        try {
            $row = $this->store->get($id);
        } catch (ScheduleException) {
            throw $this->createNotFoundException('Schedule not found.');
        }
        $content = $this->read((int) $row['content_id'], (int) $row['version_no']);
        $error = null;
        if ($request->isMethod('POST')) {
            $this->csrf($request, 'schedule_'.$id);
            try {
                if ($request->request->getString('operation') === 'cancel') {
                    $this->schedules->cancel($id, $request->request->getInt('revision'));
                } elseif ($request->request->getString('operation') === 'reschedule') {
                    $timezone = $request->request->getString('timezone');
                    $this->schedules->reschedule($id, $request->request->getInt('revision'), $this->submittedTime($request), $timezone);
                } else {
                    throw new ScheduleException('Unknown operation.');
                }

                return $this->redirectToRoute('wizhippo_schedule_detail', ['id' => $id], Response::HTTP_SEE_OTHER);
            } catch (ScheduleException $exception) {
                $error = $exception->getMessage();
            } catch (UnauthorizedException) {
                throw $this->createAccessDeniedException('You cannot manage this action.');
            }
            $row = $this->store->get($id);
        }
        $canManage = ((int) $row['requested_by'] === $this->access->actor() || $this->policy->isGranted(ContentSchedulerPolicy::FUNCTION_MANAGE)) && in_array($row['status'], ['pending', 'failed', 'conflict'], true);

        $fields = $this->timeFields($request, new \DateTimeImmutable('@'.$row['run_at']), $row['timezone']);

        return $this->view('detail', ['row' => $row, 'content' => $content, 'history' => $this->store->history($id), 'canManage' => $canManage, 'error' => $error] + $fields, $error ? 422 : 200);
    }

    private function requireView(): void
    {
        if (!$this->policy->isGranted(ContentSchedulerPolicy::FUNCTION_VIEW)) {
            throw $this->createAccessDeniedException();
        }
    }

    private function submittedTime(Request $request): \DateTimeImmutable
    {
        return ExecutionTime::parseLocal(
            $request->request->getString('date'), $request->request->getString('local_time'),
            $request->request->getString('timezone'), $request->request->getString('occurrence'), $this->clock->now()
        );
    }

    private function timeFields(Request $request, \DateTimeImmutable $default, string $defaultTimezone): array
    {
        $local = $default->setTimezone(new \DateTimeZone($defaultTimezone));
        $date = $request->request->getString('date', $local->format('Y-m-d'));
        $time = $request->request->getString('local_time', $local->format('H:i'));
        $timezone = $request->request->getString('timezone', $defaultTimezone);
        $occurrence = $request->request->getString('occurrence');
        $timeOccurrences = [];
        try {
            $choices = ExecutionTime::occurrences($date, $time, $timezone);
            if (count($choices) === 2) {
                foreach ($choices as $index => $choice) {
                    $value = $index === 0 ? 'earlier' : 'later';
                    $timeOccurrences[] = ['value' => $value, 'label' => ($index === 0 ? 'First occurrence' : 'Second occurrence').' — '.$choice->setTimezone(new \DateTimeZone($timezone))->format('T (P)')];
                    if (!$request->isMethod('POST') && $choice->getTimestamp() === $default->getTimestamp() - (int) $default->format('s')) {
                        $occurrence = $value;
                    }
                }
            }
        } catch (ScheduleException) {
            // Keep submitted fields visible so errors can be corrected without losing input.
        }
        $timezones = \DateTimeZone::listIdentifiers();
        if (!in_array($timezone, $timezones, true)) {
            array_unshift($timezones, $timezone);
        }

        return ['date' => $date, 'localTime' => $time, 'timezone' => $timezone, 'timezones' => $timezones, 'occurrence' => $occurrence, 'timeOccurrences' => $timeOccurrences];
    }

    private function csrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token. Reload the page and try again.');
        }
    }

    private function read(int $id, int $version = 0): \Ibexa\Contracts\Core\Repository\Values\Content\Content
    {
        try {
            return $this->access->read($id, $version);
        } catch (NotFoundException) {
            throw $this->createNotFoundException('Content version not found.');
        } catch (UnauthorizedException) {
            throw $this->createAccessDeniedException('Content version unavailable.');
        }
    }

    private function view(string $template, array $parameters, int $status = 200): Response
    {
        return $this->render('@WizhippoIbexaContentScheduler/'.$template.'.html.twig', $parameters, new Response(status: $status, headers: ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex']));
    }
}
