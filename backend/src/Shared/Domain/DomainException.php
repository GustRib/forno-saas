<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Violação de regra de negócio. A mensagem é exibida ao usuário, portanto
 * deve ser escrita em linguagem compreensível (pt-BR) e sem dados sensíveis.
 */
class DomainException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'BUSINESS_RULE_VIOLATION',
        public readonly int $httpStatus = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $message = 'Registro não encontrado.'): self
    {
        return new self($message, 'NOT_FOUND', 404);
    }

    public static function conflict(string $message, string $code = 'CONFLICT'): self
    {
        return new self($message, $code, 409);
    }

    /**
     * Erro de validação ligado a um campo específico do payload.
     */
    public static function invalidField(string $field, string $message): self
    {
        return new self($message, 'VALIDATION_FAILED', 422, ['fields' => [$field => [$message]]]);
    }
}
