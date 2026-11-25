<?php

declare(strict_types=1);

namespace App\Shared\Presentation;

use Symfony\Component\HttpFoundation\Request;

final readonly class Pagination
{
    public const MAX_LIMIT = 100;

    private function __construct(
        public int $page,
        public int $limit,
    ) {
    }

    public static function fromRequest(Request $request, int $defaultLimit = 25): self
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(self::MAX_LIMIT, max(1, $request->query->getInt('limit', $defaultLimit)));

        return new self($page, $limit);
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->limit;
    }
}
