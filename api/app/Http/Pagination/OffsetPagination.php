<?php

declare(strict_types=1);

namespace Mordomus\Http\Pagination;

use Illuminate\Http\Request;

/**
 * Paginação offset: clamp único de `page`/`per_page` e meta padronizada.
 */
final readonly class OffsetPagination
{
    private const MAX_PER_PAGE = 100;

    private function __construct(
        public int $perPage,
        public int $page,
    ) {}

    public static function from(Request $request, int $defaultPerPage = 25): self
    {
        return new self(
            min(max($request->integer('per_page', $defaultPerPage), 1), self::MAX_PER_PAGE),
            max($request->integer('page', 1), 1),
        );
    }

    /**
     * @return array{page: int, per_page: int, total: int, last_page: int}
     */
    public function meta(int $total, int $lastPage): array
    {
        return [
            'page' => $this->page,
            'per_page' => $this->perPage,
            'total' => $total,
            'last_page' => $lastPage,
        ];
    }
}
