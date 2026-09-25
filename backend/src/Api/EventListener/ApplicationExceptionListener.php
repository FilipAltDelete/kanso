<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\EventListener;

use Kanso\Core\Internal\Api\Http\ProblemResponse;
use Kanso\Core\Internal\Application\Exception\ApplicationException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Failures the caller can act on become problem responses; anything else stays a 500. */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
final class ApplicationExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof ApplicationException) {
            return;
        }

        $event->setResponse(ProblemResponse::create(
            $exception->status(),
            $exception->title(),
            $exception->getMessage(),
            $exception->violations(),
        ));
    }
}
