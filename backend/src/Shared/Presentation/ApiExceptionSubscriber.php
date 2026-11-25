<?php

declare(strict_types=1);

namespace App\Shared\Presentation;

use App\Shared\Domain\DomainException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Exception\PartialDenormalizationException;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Converte qualquer exceção em /api para o envelope de erro padronizado.
 * Mensagens são compreensíveis para o usuário; detalhes técnicos vão para o log.
 */
final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly bool $debug = false,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Prioridade abaixo do ExceptionListener do Security (1), para que
        // AccessDenied/Authentication sejam tratados antes pelo firewall.
        return [KernelEvents::EXCEPTION => ['onException', -10]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $e = $event->getThrowable();

        $response = match (true) {
            $e instanceof DomainException => ApiResponse::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->details),
            null !== ($violations = $this->findViolations($e)) => ApiResponse::error(
                'VALIDATION_FAILED',
                'Alguns campos não são válidos.',
                422,
                ['fields' => $this->violationsToArray($violations)],
            ),
            null !== ($typeErrors = $this->findTypeErrors($e)) => ApiResponse::error(
                'VALIDATION_FAILED',
                'Alguns campos não são válidos.',
                422,
                ['fields' => $typeErrors],
            ),
            // Rede de segurança: a validação no caso de uso deve pegar antes,
            // mas sob concorrência a constraint do banco é quem decide.
            $e instanceof UniqueConstraintViolationException => ApiResponse::error(
                'DUPLICATE',
                'Já existe um registro com esses dados.',
                409,
            ),
            $e instanceof ForeignKeyConstraintViolationException => ApiResponse::error(
                'INVALID_REFERENCE',
                'O registro referencia dados inexistentes ou em uso.',
                409,
            ),
            $e instanceof AccessDeniedException => ApiResponse::error('FORBIDDEN', 'Você não tem permissão para esta ação.', 403),
            $e instanceof AuthenticationException => ApiResponse::error('UNAUTHENTICATED', 'Faça login para continuar.', 401),
            $e instanceof HttpExceptionInterface => $this->fromHttpException($e),
            default => null,
        };

        if (null === $response) {
            $this->logger->error('Erro não tratado na API', ['exception' => $e]);
            $response = ApiResponse::error(
                'INTERNAL_ERROR',
                'Ocorreu um erro inesperado. Tente novamente; se persistir, contate o suporte.',
                500,
                $this->debug ? ['exception' => $e::class, 'message' => $e->getMessage()] : [],
            );
        }

        $event->setResponse($response);
    }

    private function fromHttpException(HttpExceptionInterface $e): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $status = $e->getStatusCode();
        [$code, $message] = match ($status) {
            400 => ['BAD_REQUEST', 'Requisição inválida.'],
            401 => ['UNAUTHENTICATED', 'Faça login para continuar.'],
            403 => ['FORBIDDEN', 'Você não tem permissão para esta ação.'],
            404 => ['NOT_FOUND', 'Recurso não encontrado.'],
            405 => ['METHOD_NOT_ALLOWED', 'Método não permitido.'],
            415 => ['UNSUPPORTED_MEDIA_TYPE', 'Envie os dados em JSON.'],
            422 => ['VALIDATION_FAILED', 'Alguns campos não são válidos.'],
            429 => ['TOO_MANY_REQUESTS', 'Muitas tentativas. Aguarde um pouco e tente novamente.'],
            default => ['HTTP_ERROR', 'Não foi possível concluir a requisição.'],
        };

        $response = ApiResponse::error($code, $message, $status);
        foreach ($e->getHeaders() as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    private function findViolations(\Throwable $e): ?ConstraintViolationListInterface
    {
        for ($cur = $e; null !== $cur; $cur = $cur->getPrevious()) {
            if ($cur instanceof ValidationFailedException) {
                return $cur->getViolations();
            }
        }

        return null;
    }

    /**
     * @return array<string, list<string>>|null
     */
    private function findTypeErrors(\Throwable $e): ?array
    {
        for ($cur = $e; null !== $cur; $cur = $cur->getPrevious()) {
            if ($cur instanceof PartialDenormalizationException) {
                $fields = [];
                foreach ($cur->getErrors() as $error) {
                    $fields[$error->getPath() ?? '_'][] = 'Tipo de valor inválido.';
                }

                return $fields;
            }
            if ($cur instanceof NotNormalizableValueException) {
                return [$cur->getPath() ?? '_' => ['Tipo de valor inválido.']];
            }
        }

        return null;
    }

    /**
     * @return array<string, list<string>>
     */
    private function violationsToArray(ConstraintViolationListInterface $violations): array
    {
        $fields = [];
        foreach ($violations as $violation) {
            $fields[$violation->getPropertyPath() ?: '_'][] = (string) $violation->getMessage();
        }

        return $fields;
    }
}
